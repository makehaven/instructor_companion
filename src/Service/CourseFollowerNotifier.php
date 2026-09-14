<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\path_alias\AliasManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tells course followers when a new run of the course opens.
 *
 * Every course page carries a "Notify Me" flag (`course_interest`) whose copy
 * promises "hear first when the next cohort opens". Until 2026-09-15 nothing
 * acted on it: the flag was only a sort weight in the catalog. This service
 * runs from cron and emails each follower once per newly public, active,
 * future CiviCRM event linked to the course they follow.
 *
 * Events that were already open when the feature first ran are recorded as
 * notified without sending, so shipping it never mails people about cohorts
 * they could already see.
 */
final class CourseFollowerNotifier {

  public const STATE_KEY = 'instructor_companion.course_follower_notified';
  public const FLAG_ID = 'course_interest';
  public const MAIL_KEY = 'course_follower_notice';

  /**
   * How events are called per course type — what the theme's nouns table says.
   */
  private const NOUNS = [
    'program' => 'cohort',
    'workshop' => 'session',
    'meetup' => 'meetup',
    'badge_class' => 'class',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly MailManagerInterface $mailManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly RequestStack $requestStack,
    private readonly AliasManagerInterface $aliasManager,
  ) {}

  /**
   * The site's absolute base, even from drush cron where there is no host.
   */
  public function baseUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    $host = $request ? (string) $request->getSchemeAndHttpHost() : '';
    return preg_match('#^https?://[^/]+\.[a-z]+#i', $host) ? rtrim($host, '/') : 'https://www.makehaven.org';
  }

  /**
   * Whether the notice is switched on (default on).
   */
  public function isEnabled(): bool {
    return (bool) ($this->configFactory->get('instructor_companion.settings')->get('follower_notice_enabled') ?? TRUE);
  }

  /**
   * Cron entry point. Returns the number of emails sent.
   */
  public function run(): int {
    if (!$this->isEnabled()) {
      return 0;
    }
    foreach (['civicrm_event', 'civicrm_event__field_parent_course', 'flagging'] as $table) {
      if (!$this->database->schema()->tableExists($table)) {
        return 0;
      }
    }
    $notified = (array) $this->state->get(self::STATE_KEY, []);
    $candidates = $this->openEvents();

    if (empty($notified['_seeded'])) {
      // First run: everything already open is old news.
      foreach ($candidates as $event_id => $row) {
        $notified[$event_id] = ['t' => $this->time->getRequestTime(), 'sent' => 0, 'seeded' => TRUE];
      }
      $notified['_seeded'] = $this->time->getRequestTime();
      $this->state->set(self::STATE_KEY, $notified);
      $this->logger->notice('Course follower notices seeded: @n already-open event(s) recorded without sending.', ['@n' => count($candidates)]);
      return 0;
    }

    $total = 0;
    foreach ($candidates as $event_id => $row) {
      if (isset($notified[$event_id])) {
        continue;
      }
      // Claim first and persist, so a failure part-way cannot re-send.
      $notified[$event_id] = ['t' => $this->time->getRequestTime(), 'sent' => 0];
      $this->state->set(self::STATE_KEY, $notified);
      $count = 0;
      foreach ($this->followers((int) $row['course_nid']) as $f) {
        if ($this->email($f, $row)) {
          $count++;
        }
      }
      $notified[$event_id]['sent'] = $count;
      $this->state->set(self::STATE_KEY, $notified);
      $total += $count;
      $this->logger->notice('Course follower notice: @n follower(s) of "@course" told about event @e "@title".', [
        '@n' => $count,
        '@course' => $row['course_title'],
        '@e' => $event_id,
        '@title' => $row['title'],
      ]);
    }
    return $total;
  }

  /**
   * Events that are public, active, not templates, in the future, with a course.
   *
   * @return array<int, array>
   *   event id => ['id', 'title', 'start', 'course_nid', 'course_title', 'course_type'].
   */
  public function openEvents(): array {
    $q = $this->database->select('civicrm_event', 'e');
    $q->join('civicrm_event__field_parent_course', 'pc', 'pc.entity_id = e.id');
    $q->join('node_field_data', 'n', 'n.nid = pc.field_parent_course_target_id');
    $q->leftJoin('node__field_course_type', 't', 't.entity_id = n.nid');
    $q->fields('e', ['id', 'title', 'start_date'])
      ->fields('n', ['nid'])
      ->addField('n', 'title', 'course_title');
    $q->addField('t', 'field_course_type_value', 'course_type');
    $q->condition('e.is_active', 1)
      ->condition('e.is_public', 1)
      ->condition('e.is_template', 0)
      ->condition('n.status', 1)
      ->condition('e.start_date', date('Y-m-d H:i:s', $this->time->getRequestTime()), '>')
      ->orderBy('e.start_date');
    $out = [];
    foreach ($q->execute() as $r) {
      $out[(int) $r->id] = [
        'id' => (int) $r->id,
        'title' => (string) $r->title,
        'start' => (string) $r->start_date,
        'course_nid' => (int) $r->nid,
        'course_title' => (string) $r->course_title,
        'course_type' => (string) ($r->course_type ?? 'workshop'),
      ];
    }
    return $out;
  }

  /**
   * People following a course: active accounts with an email.
   *
   * @return array<int, array{uid:int,name:string,email:string}>
   */
  public function followers(int $course_nid): array {
    $q = $this->database->select('flagging', 'f');
    $q->join('users_field_data', 'u', 'u.uid = f.uid');
    $q->leftJoin('user__field_first_name', 'fn', 'fn.entity_id = u.uid');
    $q->fields('u', ['uid', 'name', 'mail']);
    $q->addField('fn', 'field_first_name_value', 'first_name');
    $q->condition('f.flag_id', self::FLAG_ID)
      ->condition('f.entity_type', 'node')
      ->condition('f.entity_id', $course_nid)
      ->condition('u.status', 1)
      ->isNotNull('u.mail')
      ->condition('u.mail', '', '<>');
    $out = [];
    foreach ($q->execute() as $r) {
      $out[(int) $r->uid] = [
        'uid' => (int) $r->uid,
        'name' => trim((string) ($r->first_name ?? '')) !== '' ? trim((string) $r->first_name) : (string) $r->name,
        'email' => (string) $r->mail,
      ];
    }
    return $out;
  }

  /**
   * The word for one run of this kind of course.
   */
  public static function noun(string $course_type): string {
    return self::NOUNS[$course_type] ?? 'session';
  }

  /**
   * Builds the subject and body for one follower. Pure; unit tested.
   *
   * @return array{subject:string, body:string}
   */
  public static function compose(string $name, array $event, string $register_url, string $course_url): array {
    $noun = self::noun($event['course_type']);
    $start = strtotime($event['start']) ?: 0;
    $when = $start ? date('l, F j, Y \a\t g:i A', $start) : '';
    $subject = sprintf('%s: a new %s is open', $event['course_title'], $noun);
    $lines = [
      sprintf('Hi %s,', $name),
      '',
      sprintf('You asked to hear when %s next runs. It does: %s%s.', $event['course_title'], $event['title'], $when !== '' ? ' starts ' . $when : ''),
      '',
      sprintf('Details and registration: %s', $register_url),
      '',
      sprintf('Places are limited and you are hearing first. If you no longer want these emails, open the program page and click Following to stop: %s', $course_url),
      '',
      'The MakeHaven Team',
      'www.makehaven.org',
    ];
    return ['subject' => $subject, 'body' => implode("\n", $lines)];
  }

  /**
   * Sends one notice.
   */
  private function email(array $follower, array $event): bool {
    $base = $this->baseUrl();
    $register_url = $base . '/civicrm/event/info?id=' . (int) $event['id'] . '&reset=1';
    $course_url = $base . $this->aliasManager->getAliasByPath('/node/' . (int) $event['course_nid']);
    $message = self::compose($follower['name'], $event, $register_url, $course_url);
    $result = $this->mailManager->mail(
      'instructor_companion',
      self::MAIL_KEY,
      $follower['email'],
      \Drupal::languageManager()->getDefaultLanguage()->getId(),
      ['subject' => $message['subject'], 'body' => $message['body']],
      NULL,
      TRUE
    );
    if (empty($result['result'])) {
      $this->logger->warning('Course follower notice to @mail about event @e did not send.', ['@mail' => $follower['email'], '@e' => $event['id']]);
      return FALSE;
    }
    return TRUE;
  }

}
