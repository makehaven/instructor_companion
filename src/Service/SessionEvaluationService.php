<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the attendee evaluation for multi-session classes after the LAST one.
 *
 * CiviCRM's "Thanks for Attending!" scheduled reminders (one per event type)
 * fire 24 hours after `civicrm_event.end_date`, and a scheduled reminder can
 * only be keyed to the four CiviCRM event dates — not to a Drupal field. For
 * a class that meets six times, end_date is the end of session one, so eight
 * stained-glass students were asked to rate a class that was one-sixth done.
 *
 * Two passes, both on cron:
 *  - hold(): for every multi-session event, pre-insert a `civicrm_action_log`
 *    row per participant for each end_date-keyed evaluation reminder. CiviCRM
 *    treats an existing log row as "already sent" and skips them. The row's
 *    message says why, so the hold is visible in CiviCRM's reminder log.
 *  - send(): once the last session is over (plus the same delay CiviCRM
 *    uses), email the evaluation from Drupal to the same audience the
 *    reminder would have picked — and stamp the log rows as sent.
 *
 * Single-session classes are untouched: CiviCRM keeps sending those. This is
 * deliberately the first step of moving post-event mail into Drupal, where it
 * can see attendance; it is not a second copy of CiviCRM's reminder engine.
 */
class SessionEvaluationService {

  /**
   * State key: event_id => ['t' => unix_ts, 'sent' => int recipients].
   */
  protected const SENT_STATE_KEY = 'instructor_companion.session_evaluation_sent';

  /**
   * Messages written to civicrm_action_log so a human can tell ours apart.
   */
  public const HOLD_MARKER = '[instructor_companion] Held: multi-session class, evaluation goes out after the last session.';
  public const SENT_MARKER = '[instructor_companion] Sent by Drupal after the last session.';

  /**
   * Hours after the last session before the evaluation goes out.
   */
  public const DEFAULT_DELAY_HOURS = 24;

  /**
   * Where the evaluation link points; absolute because cron has no host.
   */
  public const DEFAULT_URL = 'https://www.makehaven.org/event/evaluation/[event_type_id]?event_id=[event_id]';

  /**
   * How far back the send pass looks, so a skipped cron still catches up.
   */
  protected const MAX_HOURS = 96;

  /**
   * How far around "now" the hold pass looks for first sessions.
   */
  protected const HOLD_SPAN_DAYS = 30;

  /**
   * CiviCRM's mapping id for "event type" reminders.
   *
   * @see \CRM_Event_ActionMapping::EVENT_TYPE_MAPPING_ID
   */
  protected const EVENT_TYPE_MAPPING_ID = '2';

  /**
   * Drop processed-event records older than this so state stays small.
   */
  protected const PRUNE_DAYS = 60;

  public function __construct(
    protected Connection $database,
    protected StateInterface $state,
    protected SessionSchedule $sessions,
    protected MailManagerInterface $mailManager,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Cron entry point.
   */
  public function run(): void {
    if (!$this->isEnabled()) {
      return;
    }
    if (!$this->database->schema()->tableExists('civicrm_action_schedule')) {
      return;
    }
    try {
      $this->hold();
      $this->send();
    }
    catch (\Throwable $e) {
      $this->logger->error('Session evaluation pass failed: @m', ['@m' => $e->getMessage()]);
    }
  }

  /**
   * Whether the Drupal-side evaluation is switched on.
   */
  public function isEnabled(): bool {
    return (bool) ($this->configFactory->get('instructor_companion.settings')
      ->get('session_evaluation_enabled') ?? TRUE);
  }

  /**
   * Hours after the last session before sending.
   */
  public function delayHours(): int {
    $h = (int) ($this->configFactory->get('instructor_companion.settings')->get('session_evaluation_delay_hours') ?? 0);
    return $h > 0 ? $h : self::DEFAULT_DELAY_HOURS;
  }

  /**
   * Returns [lower, upper] local bounds for a class's effective end.
   *
   * Pure — unit tested.
   */
  public static function dueWindow(int $now, int $delay_hours): array {
    return [
      date(SessionSchedule::LOCAL_FORMAT, $now - self::MAX_HOURS * 3600),
      date(SessionSchedule::LOCAL_FORMAT, $now - $delay_hours * 3600),
    ];
  }

  /**
   * Active end_date-keyed evaluation reminders, by event type.
   *
   * @return array<int, array>
   *   schedule id => ['types' => int[], 'statuses' => int[], 'roles' => int[]].
   */
  public function evaluationSchedules(): array {
    $q = $this->database->select('civicrm_action_schedule', 'a')
      ->fields('a', ['id', 'entity_value', 'entity_status', 'recipient', 'recipient_listing'])
      ->condition('a.is_active', 1)
      ->condition('a.mapping_id', self::EVENT_TYPE_MAPPING_ID)
      ->condition('a.start_action_date', 'end_date')
      ->condition('a.start_action_condition', 'after');
    $out = [];
    foreach ($q->execute() as $r) {
      $out[(int) $r->id] = [
        'types' => self::splitValues((string) $r->entity_value),
        'statuses' => self::splitValues((string) $r->entity_status),
        'roles' => (string) $r->recipient === 'participant_role' ? self::splitValues((string) $r->recipient_listing) : [],
      ];
    }
    return $out;
  }

  /**
   * Splits a CiviCRM multi-value string (\x01 or comma separated) to ints.
   */
  public static function splitValues(string $raw): array {
    $parts = preg_split('/[\x01,]+/', trim($raw, "\x01, "));
    return array_values(array_filter(array_map('intval', $parts ?: [])));
  }

  /**
   * Multi-session events whose first session falls near now.
   *
   * @return int[]
   *   Event ids.
   */
  protected function multiSessionEventsAround(int $now): array {
    if (!$this->database->schema()->tableExists('civicrm_event__field_civi_event_sessions')) {
      return [];
    }
    $lo = date(SessionSchedule::LOCAL_FORMAT, $now - self::HOLD_SPAN_DAYS * 86400);
    $hi = date(SessionSchedule::LOCAL_FORMAT, $now + self::HOLD_SPAN_DAYS * 86400);
    $q = $this->database->select('civicrm_event', 'e');
    $q->innerJoin('civicrm_event__field_civi_event_sessions', 's', 'e.id = s.entity_id AND s.deleted = 0');
    $q->addField('e', 'id');
    $q->condition('e.is_active', 1);
    $q->condition('e.is_template', 0);
    $q->where('e.start_date BETWEEN :lo AND :hi', [':lo' => $lo, ':hi' => $hi]);
    $q->groupBy('e.id');
    $q->having('COUNT(s.delta) >= 1');
    $ids = array_map('intval', $q->execute()->fetchCol());
    return array_values(array_filter($ids, fn(int $id): bool => $this->sessions->isMultiSession($id)));
  }

  /**
   * Pre-marks CiviCRM's evaluation reminders as handled for multi-session events.
   *
   * @return int
   *   Log rows inserted.
   */
  public function hold(): int {
    $schedules = $this->evaluationSchedules();
    if (!$schedules) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $inserted = 0;
    foreach ($this->multiSessionEventsAround($now) as $event_id) {
      $type = $this->eventType($event_id);
      foreach ($schedules as $schedule_id => $def) {
        if (!in_array($type, $def['types'], TRUE)) {
          continue;
        }
        foreach ($this->audience($event_id, $def) as $p) {
          if ($this->hasLogRow($schedule_id, $p['participant_id'])) {
            continue;
          }
          $this->database->insert('civicrm_action_log')->fields([
            'contact_id' => $p['contact_id'],
            'entity_id' => $p['participant_id'],
            'entity_table' => 'civicrm_participant',
            'action_schedule_id' => $schedule_id,
            'action_date_time' => date('Y-m-d H:i:s', $now),
            'is_error' => 0,
            'message' => self::HOLD_MARKER,
            'repetition_number' => 0,
          ])->execute();
          $inserted++;
        }
      }
    }
    if ($inserted) {
      $this->logger->notice('Held @n CiviCRM evaluation reminder(s) for multi-session classes.', ['@n' => $inserted]);
    }
    return $inserted;
  }

  /**
   * Sends evaluations for multi-session classes whose last session is over.
   *
   * @return int
   *   Emails sent.
   */
  public function send(): int {
    $schedules = $this->evaluationSchedules();
    $now = $this->time->getRequestTime();
    [$lo, $hi] = self::dueWindow($now, $this->delayHours());
    $ended = $this->sessions->endedBetween($lo, $hi, function ($q) {
      $q->condition('e.is_active', 1);
      $q->condition('e.is_template', 0);
      $q->addField('e', 'event_type_id', 'type');
      $q->addField('e', 'title', 'title');
    });

    $sent_map = $this->prune((array) $this->state->get(self::SENT_STATE_KEY, []), $now);
    $total = 0;
    foreach ($ended as $event_id => $row) {
      if (isset($sent_map[$event_id]) || !$this->sessions->isMultiSession($event_id)) {
        continue;
      }
      // Mark up-front so a mid-loop failure cannot double-send.
      $sent_map[$event_id] = ['t' => $now, 'sent' => 0];

      $type = (int) $row['type'];
      // Audience = what the reminder for this type would have used; without a
      // matching reminder there is nothing to substitute for.
      $schedule_id = NULL;
      foreach ($schedules as $sid => $def) {
        if (in_array($type, $def['types'], TRUE)) {
          $schedule_id = $sid;
          break;
        }
      }
      if ($schedule_id === NULL) {
        continue;
      }
      $count = 0;
      foreach ($this->audience($event_id, $schedules[$schedule_id]) as $p) {
        // Someone CiviCRM already wrote to (a class whose sessions were added
        // after its first-session reminder went out) is not asked twice.
        if ($this->hasForeignLogRow($schedule_id, $p['participant_id'])) {
          continue;
        }
        if ($this->email($p, $event_id, (string) $row['title'], $type)) {
          $count++;
          $this->stampLogRow($schedule_id, $p, $now);
        }
      }
      $sent_map[$event_id]['sent'] = $count;
      $total += $count;
      $this->logger->notice('Evaluation sent to @n attendee(s) of multi-session event @e after its last session.', [
        '@n' => $count,
        '@e' => $event_id,
      ]);
    }
    $this->state->set(self::SENT_STATE_KEY, $sent_map);
    return $total;
  }

  /**
   * Sends now for one event, on a staff member's say-so (bypasses the window).
   */
  public function sendNow(int $event_id): int {
    $schedules = $this->evaluationSchedules();
    $type = $this->eventType($event_id);
    $title = (string) $this->database->select('civicrm_event', 'e')->fields('e', ['title'])->condition('e.id', $event_id)->execute()->fetchField();
    foreach ($schedules as $sid => $def) {
      if (!in_array($type, $def['types'], TRUE)) {
        continue;
      }
      $now = $this->time->getRequestTime();
      $count = 0;
      foreach ($this->audience($event_id, $def) as $p) {
        if ($this->hasForeignLogRow($sid, $p['participant_id'])) {
          continue;
        }
        if ($this->email($p, $event_id, $title, $type)) {
          $count++;
          $this->stampLogRow($sid, $p, $now);
        }
      }
      $map = (array) $this->state->get(self::SENT_STATE_KEY, []);
      $map[$event_id] = ['t' => $now, 'sent' => $count];
      $this->state->set(self::SENT_STATE_KEY, $map);
      return $count;
    }
    return 0;
  }

  /**
   * The event's type id.
   */
  protected function eventType(int $event_id): int {
    return (int) $this->database->select('civicrm_event', 'e')
      ->fields('e', ['event_type_id'])
      ->condition('e.id', $event_id)
      ->execute()
      ->fetchField();
  }

  /**
   * Participants a reminder with this definition would address.
   *
   * Mirrors CiviCRM: the reminder's participant statuses and roles, non-test,
   * contact mailable (not deceased, no do-not-email / opt-out), primary email
   * not on hold.
   *
   * @return array[]
   *   Rows: participant_id, contact_id, email, first_name, display_name.
   */
  protected function audience(int $event_id, array $def): array {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_contact', 'c', 'c.id = p.contact_id');
    $q->innerJoin('civicrm_email', 'em', "em.contact_id = c.id AND em.is_primary = 1");
    $q->addField('p', 'id', 'participant_id');
    $q->addField('p', 'contact_id', 'contact_id');
    $q->addField('em', 'email', 'email');
    $q->addField('c', 'first_name', 'first_name');
    $q->addField('c', 'display_name', 'display_name');
    $q->condition('p.event_id', $event_id);
    $q->condition('p.is_test', 0);
    if ($def['statuses']) {
      $q->condition('p.status_id', $def['statuses'], 'IN');
    }
    if ($def['roles']) {
      // role_id may hold several \x01-separated roles; match any.
      $or = $q->orConditionGroup();
      foreach ($def['roles'] as $role) {
        $or->condition('p.role_id', $role);
        $or->condition('p.role_id', '%' . $this->database->escapeLike("\x01" . $role . "\x01") . '%', 'LIKE');
        $or->condition('p.role_id', $this->database->escapeLike($role . "\x01") . '%', 'LIKE');
        $or->condition('p.role_id', '%' . $this->database->escapeLike("\x01" . $role), 'LIKE');
      }
      $q->condition($or);
    }
    $q->condition('c.is_deleted', 0);
    $q->condition('c.is_deceased', 0);
    $q->condition('c.do_not_email', 0);
    $q->condition('c.is_opt_out', 0);
    $q->condition('em.on_hold', 0);
    $q->condition('em.email', '', '<>');
    $q->orderBy('p.id');
    $out = [];
    foreach ($q->execute() as $r) {
      $out[] = [
        'participant_id' => (int) $r->participant_id,
        'contact_id' => (int) $r->contact_id,
        'email' => (string) $r->email,
        'first_name' => (string) $r->first_name,
        'display_name' => (string) $r->display_name,
      ];
    }
    return $out;
  }

  /**
   * Whether any action_log row exists for this schedule + participant.
   */
  protected function hasLogRow(int $schedule_id, int $participant_id): bool {
    return (bool) $this->database->select('civicrm_action_log', 'l')
      ->fields('l', ['id'])
      ->condition('l.action_schedule_id', $schedule_id)
      ->condition('l.entity_table', 'civicrm_participant')
      ->condition('l.entity_id', $participant_id)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Whether CiviCRM (not us) already logged a send for this participant.
   */
  protected function hasForeignLogRow(int $schedule_id, int $participant_id): bool {
    $q = $this->database->select('civicrm_action_log', 'l')
      ->fields('l', ['id'])
      ->condition('l.action_schedule_id', $schedule_id)
      ->condition('l.entity_table', 'civicrm_participant')
      ->condition('l.entity_id', $participant_id)
      ->condition('l.is_error', 0)
      ->range(0, 1);
    $or = $q->orConditionGroup()
      ->isNull('l.message')
      ->condition('l.message', '[instructor_companion]%', 'NOT LIKE');
    $q->condition($or);
    return (bool) $q->execute()->fetchField();
  }

  /**
   * Marks the participant's log row as sent by us (inserting if absent).
   */
  protected function stampLogRow(int $schedule_id, array $p, int $now): void {
    $updated = $this->database->update('civicrm_action_log')
      ->fields(['message' => self::SENT_MARKER, 'action_date_time' => date('Y-m-d H:i:s', $now)])
      ->condition('action_schedule_id', $schedule_id)
      ->condition('entity_table', 'civicrm_participant')
      ->condition('entity_id', $p['participant_id'])
      ->condition('message', self::HOLD_MARKER)
      ->execute();
    if (!$updated && !$this->hasLogRow($schedule_id, $p['participant_id'])) {
      $this->database->insert('civicrm_action_log')->fields([
        'contact_id' => $p['contact_id'],
        'entity_id' => $p['participant_id'],
        'entity_table' => 'civicrm_participant',
        'action_schedule_id' => $schedule_id,
        'action_date_time' => date('Y-m-d H:i:s', $now),
        'is_error' => 0,
        'message' => self::SENT_MARKER,
        'repetition_number' => 0,
      ])->execute();
    }
  }

  /**
   * Builds the evaluation link for an event.
   */
  public function evaluationUrl(int $event_id, int $event_type_id): string {
    $pattern = (string) ($this->configFactory->get('instructor_companion.settings')->get('session_evaluation_url') ?: self::DEFAULT_URL);
    $path = str_replace(['[event_type_id]', '[event_id]'], [$event_type_id, $event_id], $pattern);
    if (preg_match('#^https?://#', $path)) {
      return $path;
    }
    // A relative path only works with a real request host; drush cron has
    // none, so fall back to the canonical site.
    $host = (string) \Drupal::request()->getSchemeAndHttpHost();
    $base = preg_match('#^https?://[^/]+\.[a-z]+#i', $host) ? rtrim($host, '/') : 'https://www.makehaven.org';
    return $base . '/' . ltrim($path, '/');
  }

  /**
   * Emails one attendee.
   */
  protected function email(array $p, int $event_id, string $title, int $type): bool {
    $config = $this->configFactory->get('instructor_companion.settings');
    $replacements = [
      '[first_name]' => $p['first_name'] !== '' ? $p['first_name'] : $p['display_name'],
      '[event_title]' => $title,
      '[evaluation_url]' => $this->evaluationUrl($event_id, $type),
    ];
    $subject = strtr((string) ($config->get('session_evaluation_subject') ?: 'Thank you for attending [event_title]!'), $replacements);
    $body = strtr((string) ($config->get('session_evaluation_body') ?: self::defaultBody()), $replacements);
    $result = $this->mailManager->mail(
      'instructor_companion',
      'session_evaluation',
      $p['email'],
      \Drupal::languageManager()->getDefaultLanguage()->getId(),
      ['subject' => $subject, 'body' => $body],
      NULL,
      TRUE
    );
    if (empty($result['result'])) {
      $this->logger->warning('Evaluation email to @mail for event @e did not send.', [
        '@mail' => $p['email'],
        '@e' => $event_id,
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Default body — the same words as CiviCRM reminder #2445.
   */
  public static function defaultBody(): string {
    return "Hello [first_name],\n\n"
      . "Thank you for joining us at MakeHaven for [event_title]. We loved having you and hope you found it both fun and inspiring.\n\n"
      . "We are always looking to improve. Could you take a couple of minutes to tell us how it went? As a thank-you you will receive a \$5 discount code for your next class or meetup after completing the form.\n\n"
      . "Share your feedback on [event_title]:\n[evaluation_url]\n\n"
      . "Thanks again, and we hope to see you back soon.\n\nThe MakeHaven Team";
  }

  /**
   * Drops sent-map records older than PRUNE_DAYS.
   */
  protected function prune(array $sent, int $now): array {
    $cutoff = $now - self::PRUNE_DAYS * 86400;
    return array_filter($sent, static fn(array $r): bool => ($r['t'] ?? 0) >= $cutoff);
  }

}
