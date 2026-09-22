<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * One email to a registration that stalled at payment.
 *
 * "Your seat is still open."
 *
 * A registration whose card step never completed sits in CiviCRM as
 * "Pending (incomplete transaction)". It holds no seat and nothing follows it
 * up: in the twelve months to 2026-09-22 there were 134 of them from 95
 * people, 117 while a seat was still open, and only 51 later completed that
 * class. This sends once, a day later, only while the class is ahead and a
 * seat is open, and never to someone who has since registered.
 */
class AbandonedRegistrationService {

  public const STATE_KEY = 'instructor_companion.abandoned_registration_sent';
  public const MAIL_KEY = 'abandoned_registration';
  public const STATUS_INCOMPLETE = 6;
  public const DEFAULT_AFTER_HOURS = 24;

  /**
   * Older than this and the moment has passed.
   */
  public const MAX_AGE_DAYS = 14;

  /**
   * Do not chase a seat for a class starting within this many hours.
   */
  public const LEAD_HOURS = 6;

  protected const ROLE_ATTENDEE = '1';
  protected const PRUNE_DAYS = 60;

  public function __construct(
    protected Connection $database,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Switched on (default on).
   */
  public function isEnabled(): bool {
    return (bool) ($this->configFactory->get('instructor_companion.settings')->get('abandoned_followup_enabled') ?? TRUE);
  }

  /**
   * Hours after the attempt before the email.
   */
  public function afterHours(): int {
    $h = (int) ($this->configFactory->get('instructor_companion.settings')->get('abandoned_after_hours') ?? 0);
    return $h > 0 ? $h : self::DEFAULT_AFTER_HOURS;
  }

  /**
   * Whether a stalled registration is worth chasing at $now. Pure; unit tested.
   *
   * @param int $now
   *   Current timestamp.
   * @param int $registered
   *   Timestamp of the attempt.
   * @param int $start
   *   Timestamp of the class.
   * @param bool $seat_open
   *   Whether the class still has room.
   * @param bool $registered_since
   *   Whether the contact holds a counted or waitlisted registration now.
   * @param int $after_hours
   *   Hours the attempt must be old.
   */
  public static function eligible(int $now, int $registered, int $start, bool $seat_open, bool $registered_since, int $after_hours = self::DEFAULT_AFTER_HOURS): bool {
    if (!$seat_open || $registered_since) {
      return FALSE;
    }
    if ($registered > $now - $after_hours * 3600) {
      return FALSE;
    }
    if ($registered < $now - self::MAX_AGE_DAYS * 86400) {
      return FALSE;
    }
    return $start > $now + self::LEAD_HOURS * 3600;
  }

  /**
   * Cron entry point. Returns the number of emails sent.
   */
  public function run(): int {
    if (!$this->isEnabled()) {
      return 0;
    }
    foreach (['civicrm_event', 'civicrm_participant', 'civicrm_email'] as $table) {
      if (!$this->database->schema()->tableExists($table)) {
        return 0;
      }
    }
    $now = $this->time->getRequestTime();
    $sent = $this->prune((array) $this->state->get(self::STATE_KEY, []), $now);
    $candidates = $this->candidates($now);

    if (empty($sent['_seeded'])) {
      foreach ($candidates as $c) {
        $sent['p' . $c['participant_id']] = ['t' => $now, 'seeded' => TRUE];
      }
      $sent['_seeded'] = $now;
      $this->state->set(self::STATE_KEY, $sent);
      $this->logger->notice('Abandoned-registration follow-up seeded: @n stalled registration(s) recorded without sending.', ['@n' => count($candidates)]);
      return 0;
    }

    $total = 0;
    foreach ($candidates as $c) {
      $pkey = 'p' . $c['participant_id'];
      $ckey = 'c' . $c['contact_id'] . '-' . $c['event_id'];
      if (isset($sent[$pkey]) || isset($sent[$ckey])) {
        continue;
      }
      $sent[$pkey] = ['t' => $now];
      $sent[$ckey] = ['t' => $now];
      $this->state->set(self::STATE_KEY, $sent);
      if ($this->email($c)) {
        $total++;
      }
    }
    if ($total) {
      $this->logger->notice('Abandoned-registration follow-up: @n email(s) sent.', ['@n' => $total]);
    }
    return $total;
  }

  /**
   * Stalled registrations that are worth an email right now.
   *
   * @return array<int, array>
   *   participant_id, contact_id, email, first_name, event_id, title, start
   *   (local string), registered (local string).
   */
  public function candidates(int $now): array {
    $types = PostEventStatusService::closeoutEventTypes();
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $q->innerJoin('civicrm_contact', 'c', 'c.id = p.contact_id');
    $q->innerJoin('civicrm_email', 'em', 'em.contact_id = c.id AND em.is_primary = 1');
    $q->addField('p', 'id', 'participant_id');
    $q->addField('p', 'contact_id', 'contact_id');
    $q->addField('p', 'register_date', 'registered');
    $q->addField('e', 'id', 'event_id');
    $q->addField('e', 'title', 'title');
    $q->addField('e', 'start_date', 'start');
    $q->addField('e', 'max_participants', 'max');
    $q->addField('em', 'email', 'email');
    $q->addField('c', 'first_name', 'first_name');
    $q->addField('c', 'display_name', 'display_name');
    $q->condition('p.status_id', self::STATUS_INCOMPLETE)
      ->condition('p.is_test', 0)
      ->condition('e.is_active', 1)
      ->condition('e.is_template', 0)
      ->condition('e.start_date', date('Y-m-d H:i:s', $now), '>')
      ->condition('p.register_date', date('Y-m-d H:i:s', $now - self::MAX_AGE_DAYS * 86400), '>=')
      ->condition('c.is_deleted', 0)
      ->condition('c.is_deceased', 0)
      ->condition('c.do_not_email', 0)
      ->condition('c.is_opt_out', 0)
      ->condition('em.on_hold', 0)
      ->condition('em.email', '', '<>');
    if ($types) {
      $q->condition('e.event_type_id', $types, 'IN');
    }
    $role = self::ROLE_ATTENDEE;
    $or = $q->orConditionGroup()
      ->condition('p.role_id', $role)
      ->condition('p.role_id', '%' . $this->database->escapeLike("\x01" . $role . "\x01") . '%', 'LIKE')
      ->condition('p.role_id', $this->database->escapeLike($role . "\x01") . '%', 'LIKE')
      ->condition('p.role_id', '%' . $this->database->escapeLike("\x01" . $role), 'LIKE');
    $q->condition($or);
    // Sold seats and whether this contact registered properly since.
    $q->addExpression('(SELECT COUNT(*) FROM {civicrm_participant} s WHERE s.event_id = e.id AND s.is_test = 0 AND s.status_id IN (1, 2, 5, 14, 15))', 'sold');
    $q->addExpression('(SELECT COUNT(*) FROM {civicrm_participant} s2 WHERE s2.event_id = e.id AND s2.contact_id = p.contact_id AND s2.status_id IN (1, 2, 5, 7, 9, 14, 15))', 'registered_since');
    $q->orderBy('p.register_date');

    $out = [];
    foreach ($q->execute() as $r) {
      $max = (int) $r->max;
      $seat_open = $max <= 0 || (int) $r->sold < $max;
      $registered = strtotime((string) $r->registered) ?: 0;
      $start = strtotime((string) $r->start) ?: 0;
      if (!self::eligible($now, $registered, $start, $seat_open, (int) $r->registered_since > 0, $this->afterHours())) {
        continue;
      }
      $first = trim((string) $r->first_name);
      $out[] = [
        'participant_id' => (int) $r->participant_id,
        'contact_id' => (int) $r->contact_id,
        'email' => (string) $r->email,
        'first_name' => $first !== '' ? $first : (string) $r->display_name,
        'event_id' => (int) $r->event_id,
        'title' => (string) $r->title,
        'start' => (string) $r->start,
        'registered' => (string) $r->registered,
      ];
    }
    return $out;
  }

  /**
   * How many stalled registrations exist on upcoming classes, chased or not.
   */
  public function openCount(int $now): int {
    $types = PostEventStatusService::closeoutEventTypes();
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $q->condition('p.status_id', self::STATUS_INCOMPLETE)
      ->condition('p.is_test', 0)
      ->condition('e.is_active', 1)
      ->condition('e.is_template', 0)
      ->condition('e.start_date', date('Y-m-d H:i:s', $now), '>')
      ->condition('p.register_date', date('Y-m-d H:i:s', $now - self::MAX_AGE_DAYS * 86400), '>=');
    if ($types) {
      $q->condition('e.event_type_id', $types, 'IN');
    }
    return (int) $q->countQuery()->execute()->fetchField();
  }

  /**
   * The site's absolute base, even from drush cron where there is no host.
   */
  public function baseUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    $host = $request ? (string) $request->getSchemeAndHttpHost() : '';
    return preg_match('#^https?://[^/]+\.[a-z]+#i', $host) ? rtrim($host, '/') : 'https://www.makehaven.org';
  }

  /**
   * Subject and body. Pure; unit tested.
   *
   * @return array{subject:string, body:string}
   *   Subject and plain-text body.
   */
  public static function compose(array $c, string $register_url, string $subject_tpl = '', string $body_tpl = ''): array {
    $start = strtotime($c['start']) ?: 0;
    $replacements = [
      '[first_name]' => $c['first_name'],
      '[event_title]' => $c['title'],
      '[event_date]' => $start ? date('l, F j \a\t g:i A', $start) : '',
      '[register_url]' => $register_url,
    ];
    $subject = $subject_tpl !== '' ? $subject_tpl : self::defaultSubject();
    $body = $body_tpl !== '' ? $body_tpl : self::defaultBody();
    return ['subject' => strtr($subject, $replacements), 'body' => strtr($body, $replacements)];
  }

  /**
   * Default subject.
   */
  public static function defaultSubject(): string {
    return 'Your seat in [event_title] is still open';
  }

  /**
   * Default body.
   */
  public static function defaultBody(): string {
    return "Hi [first_name],\n\n"
      . "You started registering for [event_title] ([event_date]) but the payment did not go through, so you are not yet on the list. A seat is still open.\n\n"
      . "Finish your registration here:\n[register_url]\n\n"
      . "If you changed your mind, no action is needed and this is the only reminder you will get. If something went wrong on the payment page, reply to this email and we will help.\n\n"
      . "The MakeHaven Team\nwww.makehaven.org";
  }

  /**
   * Sends one email.
   */
  protected function email(array $c): bool {
    $config = $this->configFactory->get('instructor_companion.settings');
    $register_url = $this->baseUrl() . '/civicrm/event/register?id=' . $c['event_id'] . '&reset=1';
    $message = self::compose($c, $register_url, (string) $config->get('abandoned_subject'), (string) $config->get('abandoned_body'));
    $result = $this->mailManager->mail(
      'instructor_companion',
      self::MAIL_KEY,
      $c['email'],
      \Drupal::languageManager()->getDefaultLanguage()->getId(),
      ['subject' => $message['subject'], 'body' => $message['body']],
      NULL,
      TRUE
    );
    if (empty($result['result'])) {
      $this->logger->warning('Abandoned-registration email to @mail for event @e did not send.', [
        '@mail' => $c['email'],
        '@e' => $c['event_id'],
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Drops records older than PRUNE_DAYS.
   */
  protected function prune(array $sent, int $now): array {
    $cutoff = $now - self::PRUNE_DAYS * 86400;
    foreach ($sent as $k => $v) {
      if ($k !== '_seeded' && (int) ($v['t'] ?? 0) < $cutoff) {
        unset($sent[$k]);
      }
    }
    return $sent;
  }

}
