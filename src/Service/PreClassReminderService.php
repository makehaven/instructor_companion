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
 * The two reminders before a class: a week out and two days out.
 *
 * Until 2026-10 these were CiviCRM per-event scheduled reminders ("1 Week
 * Event Reminder", "Event Happening Soon" a day before), cloned onto every
 * copy with whatever wording the source event carried. The education
 * manager rewrote both (2026-10-14, approved by the workshop coordinator)
 * and asked for them to run from one place. This sends them, and while it
 * is on it switches off the CiviCRM reminders it replaces on the same
 * events, so nobody gets both.
 *
 * Per stage, an attendee is reminded once, only if they registered before
 * the stage's moment (someone who signs up five days out has just had the
 * confirmation and gets only the two-day one), and never inside LEAD_HOURS
 * of the start. Multi-session classes are reminded about the first session.
 * Seeded on first run so reminders CiviCRM already sent are not repeated.
 */
class PreClassReminderService {

  public const STATE_KEY = 'instructor_companion.preclass_reminder_sent';
  public const MAIL_WEEK = 'preclass_reminder_week';
  public const MAIL_SOON = 'preclass_reminder_soon';
  public const DEFAULT_EVENT_TYPES = [6, 16];
  public const DEFAULT_REPLY_TO = 'education@makehaven.org';
  public const DEFAULT_VISIT_URL = '/visit';

  /**
   * Stage => hours before the start it becomes due.
   */
  public const STAGES = [
    'week' => 168,
    'soon' => 48,
  ];

  /**
   * No reminder inside this many hours of the start.
   */
  public const LEAD_HOURS = 6;

  /**
   * Days a sent record is kept.
   */
  protected const PRUNE_DAYS = 30;

  /**
   * CiviCRM participant role value for Attendee.
   */
  protected const ROLE_ATTENDEE = '1';

  public function __construct(
    protected Connection $database,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected SessionSchedule $sessions,
    protected EventHygieneService $hygiene,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Switched on (default off: CiviCRM's reminders run until this is on).
   */
  public function isEnabled(): bool {
    return (bool) $this->configFactory->get('instructor_companion.settings')->get('preclass_enabled');
  }

  /**
   * Event types whose attendees get the reminders.
   */
  public function eventTypes(): array {
    $configured = $this->configFactory->get('instructor_companion.settings')->get('preclass_event_types');
    if ($configured === NULL) {
      return self::DEFAULT_EVENT_TYPES;
    }
    return array_values(array_filter(array_map('intval', (array) $configured)));
  }

  /**
   * Which stage, if any, is due for one registration. Pure; unit tested.
   *
   * A stage is due from its moment until the next stage's moment (the last
   * one until LEAD_HOURS before the start), and only for someone who had
   * registered by that moment.
   *
   * @return string|null
   *   'week', 'soon' or NULL.
   */
  public static function dueStage(int $now, int $start, int $registered): ?string {
    if ($start <= $now + self::LEAD_HOURS * 3600) {
      return NULL;
    }
    $stages = self::STAGES;
    arsort($stages);
    $keys = array_keys($stages);
    foreach ($keys as $i => $stage) {
      $moment = $start - $stages[$stage] * 3600;
      $next = isset($keys[$i + 1]) ? $start - $stages[$keys[$i + 1]] * 3600 : PHP_INT_MAX;
      if ($now >= $moment && $now < $next) {
        return $registered <= $moment ? $stage : NULL;
      }
    }
    return NULL;
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
    $this->retireCiviReminders();
    $now = $this->time->getRequestTime();
    $sent = $this->prune((array) $this->state->get(self::STATE_KEY, []), $now);
    $due = $this->due($now);

    if (empty($sent['_seeded'])) {
      // CiviCRM already sent the week reminder to anyone in that stage now.
      // Its day-before one has not gone out for a class more than a day away
      // and is switched off from here on, so those people still get ours.
      $seeded = 0;
      foreach ($due as $r) {
        if ($r['stage'] === 'soon' && strtotime($r['start']) > $now + 86400) {
          continue;
        }
        $sent[$r['key']] = ['t' => $now, 'seeded' => TRUE];
        $seeded++;
      }
      $sent['_seeded'] = $now;
      $this->state->set(self::STATE_KEY, $sent);
      $this->logger->notice('Pre-class reminders seeded: @n reminder(s) CiviCRM already covered recorded without sending.', ['@n' => $seeded]);
      $due = array_filter($due, fn($r) => !isset($sent[$r['key']]));
    }

    $total = 0;
    foreach ($due as $r) {
      if (isset($sent[$r['key']])) {
        continue;
      }
      // Claim first so a failure part-way cannot re-send.
      $sent[$r['key']] = ['t' => $now];
      $this->state->set(self::STATE_KEY, $sent);
      if ($this->email($r)) {
        $total++;
      }
    }
    if ($total) {
      $this->logger->notice('Pre-class reminders: @n email(s) sent.', ['@n' => $total]);
    }
    return $total;
  }

  /**
   * Reminders due right now, one row per (participant, stage).
   *
   * @return array<int, array>
   *   key, stage, participant_id, contact_id, email, first_name, event_id,
   *   title, type, start, end (local strings), registered.
   */
  public function due(int $now): array {
    $lo = date('Y-m-d H:i:s', $now + self::LEAD_HOURS * 3600);
    $hi = date('Y-m-d H:i:s', $now + max(self::STAGES) * 3600);
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $q->innerJoin('civicrm_contact', 'c', 'c.id = p.contact_id');
    $q->innerJoin('civicrm_email', 'em', 'em.contact_id = c.id AND em.is_primary = 1');
    $q->addField('p', 'id', 'participant_id');
    $q->addField('p', 'contact_id', 'contact_id');
    $q->addField('p', 'register_date', 'registered');
    $q->addField('e', 'id', 'event_id');
    $q->addField('e', 'title', 'title');
    $q->addField('e', 'event_type_id', 'type');
    $q->addField('e', 'start_date', 'start');
    $q->addField('e', 'end_date', 'end');
    $q->addField('em', 'email', 'email');
    $q->addField('c', 'first_name', 'first_name');
    $q->addField('c', 'display_name', 'display_name');
    $q->condition('p.is_test', 0)
      ->condition('p.status_id', AttendeeFollowupService::COUNTED_STATUSES, 'IN')
      ->condition('e.is_active', 1)
      ->condition('e.is_template', 0)
      ->condition('e.start_date', [$lo, $hi], 'BETWEEN')
      ->condition('c.is_deleted', 0)
      ->condition('c.is_deceased', 0)
      ->condition('c.do_not_email', 0)
      ->condition('em.on_hold', 0)
      ->condition('em.email', '', '<>');
    $types = $this->eventTypes();
    if ($types) {
      $q->condition('e.event_type_id', $types, 'IN');
    }
    $role = self::ROLE_ATTENDEE;
    $or = $q->orConditionGroup()
      ->condition('p.role_id', $role)
      ->condition('p.role_id', '%' . $this->database->escapeLike("\x01" . $role . "\x01") . '%', 'LIKE')
      ->condition('p.role_id', $this->database->escapeLike($role . "\x01") . '%', 'LIKE')
      ->condition('p.role_id', '%' . $this->database->escapeLike("\x01" . $role), 'LIKE');
    $q->condition($or)->orderBy('e.start_date')->orderBy('p.id');

    $out = [];
    foreach ($q->execute() as $r) {
      $stage = self::dueStage($now, (int) strtotime((string) $r->start), (int) strtotime((string) $r->registered));
      if ($stage === NULL) {
        continue;
      }
      $first = trim((string) $r->first_name);
      $out[] = [
        'key' => 'p' . $r->participant_id . '-' . $stage,
        'stage' => $stage,
        'participant_id' => (int) $r->participant_id,
        'contact_id' => (int) $r->contact_id,
        'email' => (string) $r->email,
        'first_name' => $first !== '' ? $first : (string) $r->display_name,
        'event_id' => (int) $r->event_id,
        'title' => (string) $r->title,
        'type' => (int) $r->type,
        'start' => (string) $r->start,
        'end' => (string) ($r->end ?? ''),
        'registered' => (string) $r->registered,
      ];
    }
    return $out;
  }

  /**
   * Switches off the CiviCRM reminders this replaces. Returns how many.
   *
   * Per-event "before the start" reminders up to a week out on upcoming
   * events of the covered types, and the same on templates of those types
   * so new copies do not bring them back. Program-specific ones (GEMS weekly
   * emails) are left alone. Disabled, not deleted.
   */
  public function retireCiviReminders(): int {
    if (!$this->isEnabled()) {
      return 0;
    }
    $rows = $this->civiRemindersToRetire();
    if (!$rows) {
      return 0;
    }
    $n = $this->hygiene->disable(array_column($rows, 'id'));
    $this->logger->notice('Pre-class reminders: switched off @n CiviCRM reminder(s) they replace: @ids', [
      '@n' => $n,
      '@ids' => implode(', ', array_column($rows, 'id')),
    ]);
    return $n;
  }

  /**
   * The active CiviCRM reminders retireCiviReminders() would switch off.
   *
   * @return array<int, array{id:int,title:string,event_id:int,event_title:string,is_template:int}>
   *   One row per schedule.
   */
  public function civiRemindersToRetire(): array {
    if (!$this->database->schema()->tableExists('civicrm_action_schedule')) {
      return [];
    }
    $types = $this->eventTypes();
    if (!$types) {
      return [];
    }
    $q = $this->database->select('civicrm_action_schedule', 's')
      ->fields('s', ['id', 'title', 'mapping_id', 'entity_value', 'start_action_offset', 'start_action_unit'])
      ->condition('s.mapping_id', [3, 5], 'IN')
      ->condition('s.is_active', 1)
      ->condition('s.start_action_condition', 'before')
      ->condition('s.start_action_date', 'start_date');
    $out = [];
    foreach ($q->execute() as $r) {
      if (!self::isReplacedReminder((string) $r->title, (int) $r->start_action_offset, (string) $r->start_action_unit)) {
        continue;
      }
      $value = trim((string) $r->entity_value, "\x01");
      if (!ctype_digit($value)) {
        // Several events in one row: made by hand, left alone.
        continue;
      }
      $event = $this->database->select('civicrm_event', 'e')
        ->fields('e', ['id', 'title', 'template_title', 'event_type_id', 'start_date', 'is_template'])
        ->condition('e.id', (int) $value)
        ->execute()->fetchAssoc();
      if (!$event || !in_array((int) $event['event_type_id'], $types, TRUE)) {
        continue;
      }
      $is_template = (int) $r->mapping_id === 5 || !empty($event['is_template']);
      if (!$is_template && (string) $event['start_date'] <= date('Y-m-d H:i:s')) {
        continue;
      }
      $out[] = [
        'id' => (int) $r->id,
        'title' => (string) $r->title,
        'event_id' => (int) $event['id'],
        'event_title' => (string) ($is_template ? ($event['template_title'] ?: $event['title']) : $event['title']),
        'is_template' => (int) $is_template,
      ];
    }
    return $out;
  }

  /**
   * Whether a CiviCRM "before" reminder is one of the two this replaces. Pure.
   *
   * Anything from a day to eight days before the start, except
   * program-specific ones (GEMS weekly emails). Same-day ones are left: the
   * door-pass email is what goes out that day.
   */
  public static function isReplacedReminder(string $title, int $offset, string $unit): bool {
    if (stripos($title, 'GEMS') !== FALSE) {
      return FALSE;
    }
    $hours = match ($unit) {
      'hour' => $offset,
      'day' => $offset * 24,
      'week' => $offset * 168,
      default => -1,
    };
    return $hours >= 24 && $hours <= 192;
  }

  /**
   * Subject and body for one reminder. Pure; unit tested.
   *
   * @return array{subject:string, body:string}
   *   Subject and plain-text body.
   */
  public static function compose(array $r, array $texts, string $sessions_line, string $door_note, string $visit_url): array {
    $start = strtotime($r['start']) ?: 0;
    $end = $r['end'] !== '' ? (strtotime($r['end']) ?: 0) : 0;
    $time = $start ? date('g:i A', $start) : '';
    if ($end && date('Y-m-d', $end) === date('Y-m-d', $start)) {
      $time .= ' – ' . date('g:i A', $end);
    }
    $replacements = [
      '[first_name]' => $r['first_name'],
      '[event_title]' => $r['title'],
      '[event_date]' => $start ? date('l, F j, Y', $start) : '',
      '[event_time]' => $time,
      '[sessions_line]' => $sessions_line,
      '[door_pass_note]' => $door_note,
      '[visit_url]' => $visit_url,
    ];
    if ($r['stage'] === 'week') {
      $subject = $texts['week_subject'] ?: self::defaultWeekSubject();
      $body = $texts['week_body'] ?: self::defaultWeekBody();
    }
    else {
      $subject = $texts['soon_subject'] ?: self::defaultSoonSubject();
      $body = $texts['soon_body'] ?: self::defaultSoonBody();
    }
    $body = strtr($body, $replacements);
    // An empty token leaves a blank line behind; never more than one.
    $body = preg_replace("/\n{3,}/", "\n\n", preg_replace("/[ \t]+\n/", "\n", $body));
    return ['subject' => strtr($subject, $replacements), 'body' => trim($body) . "\n"];
  }

  /**
   * The door-pass sentence, when this class's registrants get a pass.
   */
  public function doorPassNote(int $event_type): string {
    $config = $this->configFactory->get('event_access_unifi.settings');
    if (!$config->get('enabled') || !$config->get('email_enabled')) {
      return '';
    }
    if (!in_array($event_type, array_map('intval', (array) $config->get('event_type_ids')), TRUE)) {
      return '';
    }
    $hours = (int) ($config->get('email_lead_hours') ?? 4);
    $when = match (TRUE) {
      $hours <= 0 => 'You will get, or have already received,',
      $hours === 1 => 'About an hour before your workshop starts, you’ll get',
      default => "About $hours hours before your workshop starts, you’ll get",
    };
    return "$when another email with a QR code and a numerical access code. Either one will let you unlock our front door when you arrive.";
  }

  /**
   * Sends one reminder.
   */
  protected function email(array $r): bool {
    $config = $this->configFactory->get('instructor_companion.settings');
    $texts = [
      'week_subject' => (string) $config->get('preclass_week_subject'),
      'week_body' => (string) $config->get('preclass_week_body'),
      'soon_subject' => (string) $config->get('preclass_soon_subject'),
      'soon_body' => (string) $config->get('preclass_soon_body'),
    ];
    $summary = SessionSchedule::summary($this->sessions->getSchedule($r['event_id']));
    $sessions_line = $summary !== '' ? "This class meets in $summary; the date above is the first session." : '';
    $message = self::compose($r, $texts, $sessions_line, $this->doorPassNote($r['type']), $this->absolute(self::DEFAULT_VISIT_URL));
    $result = $this->mailManager->mail(
      'instructor_companion',
      $r['stage'] === 'week' ? self::MAIL_WEEK : self::MAIL_SOON,
      $r['email'],
      \Drupal::languageManager()->getDefaultLanguage()->getId(),
      ['subject' => $message['subject'], 'body' => $message['body']],
      (string) ($config->get('preclass_reply_to') ?: self::DEFAULT_REPLY_TO),
      TRUE
    );
    if (empty($result['result'])) {
      $this->logger->warning('Pre-class reminder (@s) to @mail for event @e did not send.', [
        '@s' => $r['stage'],
        '@mail' => $r['email'],
        '@e' => $r['event_id'],
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Makes a site path absolute, even from drush cron where there is no host.
   */
  protected function absolute(string $path): string {
    $request = $this->requestStack->getCurrentRequest();
    $host = $request ? (string) $request->getSchemeAndHttpHost() : '';
    $base = preg_match('#^https?://[^/]+\.[a-z]+#i', $host) ? rtrim($host, '/') : 'https://www.makehaven.org';
    return $base . '/' . ltrim($path, '/');
  }

  /**
   * Default subject of the week-out reminder.
   */
  public static function defaultWeekSubject(): string {
    return 'See you next week: [event_title]';
  }

  /**
   * Default body of the week-out reminder (education manager, 2026-10-14).
   */
  public static function defaultWeekBody(): string {
    return "[first_name],\n\n"
      . "Hello! We’re looking forward to seeing you at MakeHaven next week! You’re registered for [event_title], and we wanted to send along a quick reminder with the details.\n\n"
      . "YOUR WORKSHOP DETAILS\n\n"
      . "Workshop: [event_title]\n"
      . "Date: [event_date]\n"
      . "Time: [event_time]\n"
      . "Location: MakeHaven, 770 Chapel Street, New Haven, CT\n"
      . "[sessions_line]\n\n"
      . "A FEW THINGS TO KNOW BEFORE YOU ARRIVE\n\n"
      . "- Please review the workshop description for any specific clothing, footwear, or other requirements.\n"
      . "- Unless otherwise noted in the workshop description, we’ll provide all the tools and materials you’ll need.\n"
      . "- Plan to arrive a few minutes early so you can get settled before the workshop begins.\n\n"
      . "If your plans have changed and you can no longer attend, please let us know as soon as possible. As a friendly reminder, there is a 72-hr cut off on refunds.\n\n"
      . "Have questions about the workshop or need an accommodation to participate? Just reply to this email. We’re happy to help!\n\n"
      . "See you next week!\n\n"
      . "The MakeHaven Team";
  }

  /**
   * Default subject of the two-day reminder.
   */
  public static function defaultSoonSubject(): string {
    return 'In two days: [event_title]';
  }

  /**
   * Default body of the two-day reminder (education manager, 2026-10-14).
   */
  public static function defaultSoonBody(): string {
    return "Hi [first_name],\n\n"
      . "Just a quick reminder that you’re signed up for [event_title] in two days! We’re looking forward to having you.\n\n"
      . "WORKSHOP DETAILS\n\n"
      . "Workshop: [event_title]\n"
      . "Date: [event_date]\n"
      . "Time: [event_time]\n"
      . "Location: MakeHaven, 770 Chapel Street, New Haven, CT\n"
      . "[sessions_line]\n\n"
      . "GETTING HERE & GETTING IN\n\n"
      . "You can find directions and parking information at [visit_url]\n\n"
      . "[door_pass_note]\n\n"
      . "A FEW THINGS BEFORE YOU COME\n\n"
      . "- Check the workshop description for any specific clothing, footwear, or safety requirements.\n"
      . "- Unless otherwise noted, we’ll have all the tools and materials you’ll need.\n"
      . "- Try to arrive a few minutes early so we can get started on time.\n\n"
      . "If you have any questions or need anything ahead of the workshop, just reply to this email.\n\n"
      . "See you soon!\n\n"
      . "The MakeHaven Team";
  }

  /**
   * Drops records older than PRUNE_DAYS.
   */
  protected function prune(array $sent, int $now): array {
    $cutoff = $now - self::PRUNE_DAYS * 86400;
    foreach ($sent as $k => $v) {
      if ($k === '_seeded') {
        continue;
      }
      if ((int) ($v['t'] ?? 0) < $cutoff) {
        unset($sent[$k]);
      }
    }
    return $sent;
  }

}
