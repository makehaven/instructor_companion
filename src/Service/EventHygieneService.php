<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Two things every event should carry, and one it should not.
 *
 * Staff make almost every event by copying the previous one in CiviCRM, and
 * CiviCRM's copy clones the event's *per-event* scheduled reminders. Three of
 * those ("3 days Later Reminder", "Thanks for attending", "eval") are the
 * leftovers of the pre-2026-08 per-event survey chain, superseded by the one
 * type-level "Thanks for Attending!" reminder (#2445–#2450). The 2026-08-11
 * cleanup disabled 83 of them, but every copy since has re-created its own —
 * six between mid-August and 2026-09-22 — so attendees of those classes get
 * two survey emails. disableClonedReminders() runs after each copy.
 *
 * Waitlists: CiviCRM's "Update Participant Statuses" job already promotes the
 * first waitlisted person to "Pending from waitlist" and emails them when a
 * seat frees. What it cannot do without `expiration_time` on the event is
 * take the seat back when they do not pay — so on 2026-09-22 seventeen offers
 * were sitting unpaid, some for two years, and classes ran with empty seats
 * while people waited (event 950: two unpaid offers, five cancellations, ran
 * 5 of 9). ensureWaitlistExpiry() gives every waitlisted event the module's
 * default; CiviCRM does the rest, and resets the clock at promotion (CRM-6496).
 *
 * End times: the attendee survey, attendance and the wrap-up all key on
 * civicrm_event.end_date, and an event saved without one is never surveyed.
 * Policy (JR 2026-10-03): an event with no end time gets one — the course's
 * usual length, else default_event_length_minutes (120). fillMissingEnd()
 * runs in hook_civicrm_pre; cron backfills anything that slipped past it.
 */
class EventHygieneService {

  /**
   * Per-event "after" reminder titles that the type-level reminder replaced.
   */
  public const CLONED_REMINDER_TITLES = [
    '3 days Later Reminder',
    'Thanks for attending',
    'Thanks for Attending!',
    'eval',
    'Instructor Post-Event Evaluation',
  ];

  /**
   * Hours an unpaid waitlist offer stays open before CiviCRM expires it.
   */
  public const DEFAULT_WAITLIST_OFFER_HOURS = 24;

  /**
   * Length given to an event saved without an end time, in minutes.
   */
  public const DEFAULT_EVENT_LENGTH_MINUTES = 120;

  /**
   * CiviCRM's action-schedule mapping for "a specific event".
   */
  protected const MAPPING_EVENT = 3;

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether a reminder title is one of the superseded per-event ones. Pure.
   */
  public static function isClonedReminderTitle(string $title, ?array $titles = NULL): bool {
    $needle = mb_strtolower(trim($title));
    foreach ($titles ?? self::CLONED_REMINDER_TITLES as $t) {
      if ($needle === mb_strtolower(trim((string) $t))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The configured title list (settings override the constant).
   */
  public function clonedReminderTitles(): array {
    $configured = $this->configFactory->get('instructor_companion.settings')->get('cloned_reminder_titles');
    $list = array_values(array_filter(array_map('trim', (array) ($configured ?: [])), 'strlen'));
    return $list ?: self::CLONED_REMINDER_TITLES;
  }

  /**
   * Active per-event "after" reminders on one event whose title is superseded.
   *
   * @return array<int, array{id:int,title:string}>
   *   Schedule id and title.
   */
  public function clonedReminders(int $event_id): array {
    if (!$this->database->schema()->tableExists('civicrm_action_schedule')) {
      return [];
    }
    $q = $this->database->select('civicrm_action_schedule', 's')
      ->fields('s', ['id', 'title', 'entity_value'])
      ->condition('s.mapping_id', self::MAPPING_EVENT)
      ->condition('s.is_active', 1)
      ->condition('s.start_action_condition', 'after');
    $or = $q->orConditionGroup()
      ->condition('s.entity_value', (string) $event_id)
      ->condition('s.entity_value', '%' . $this->database->escapeLike("\x01" . $event_id . "\x01") . '%', 'LIKE')
      ->condition('s.entity_value', $this->database->escapeLike($event_id . "\x01") . '%', 'LIKE')
      ->condition('s.entity_value', '%' . $this->database->escapeLike("\x01" . $event_id), 'LIKE');
    $q->condition($or);
    $titles = $this->clonedReminderTitles();
    $out = [];
    foreach ($q->execute() as $r) {
      if (self::isClonedReminderTitle((string) $r->title, $titles)) {
        $out[] = ['id' => (int) $r->id, 'title' => (string) $r->title];
      }
    }
    return $out;
  }

  /**
   * Switches off the superseded reminders on one event. Returns how many.
   *
   * Disabled, not deleted: CiviCRM's copy will not re-enable them and a human
   * can still read what the event used to send.
   */
  public function disableClonedReminders(int $event_id): int {
    $rows = $this->clonedReminders($event_id);
    if (!$rows) {
      return 0;
    }
    $this->disable(array_column($rows, 'id'));
    $this->logger->notice('Event @e: switched off @n cloned per-event reminder(s): @t. The type-level "Thanks for Attending!" reminder still sends.', [
      '@e' => $event_id,
      '@n' => count($rows),
      '@t' => implode(', ', array_column($rows, 'title')),
    ]);
    return count($rows);
  }

  /**
   * Every active superseded per-event reminder, site-wide, with its event.
   *
   * For the one-time cleanup command. Rows whose entity_value names several
   * events are left alone — those were made by hand, not by a copy.
   *
   * @return array<int, array>
   *   id, title, event_id, event_title, start.
   */
  public function allClonedReminders(): array {
    if (!$this->database->schema()->tableExists('civicrm_action_schedule')) {
      return [];
    }
    $titles = $this->clonedReminderTitles();
    $q = $this->database->select('civicrm_action_schedule', 's')
      ->fields('s', ['id', 'title', 'entity_value'])
      ->condition('s.mapping_id', self::MAPPING_EVENT)
      ->condition('s.is_active', 1)
      ->condition('s.start_action_condition', 'after')
      ->orderBy('s.id');
    $out = [];
    foreach ($q->execute() as $r) {
      $value = trim((string) $r->entity_value, "\x01");
      if (!ctype_digit($value) || !self::isClonedReminderTitle((string) $r->title, $titles)) {
        continue;
      }
      $event = $this->database->select('civicrm_event', 'e')
        ->fields('e', ['id', 'title', 'start_date'])
        ->condition('e.id', (int) $value)
        ->execute()->fetchAssoc();
      $out[] = [
        'id' => (int) $r->id,
        'title' => (string) $r->title,
        'event_id' => (int) $value,
        'event_title' => (string) ($event['title'] ?? '(event missing)'),
        'start' => (string) ($event['start_date'] ?? ''),
      ];
    }
    return $out;
  }

  /**
   * Sets is_active = 0 on the given schedule ids.
   */
  public function disable(array $ids): int {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
      return 0;
    }
    return (int) $this->database->update('civicrm_action_schedule')
      ->fields(['is_active' => 0])
      ->condition('id', $ids, 'IN')
      ->execute();
  }

  /**
   * Configured offer window in hours; 0 means leave events alone.
   */
  public function waitlistOfferHours(): int {
    $v = $this->configFactory->get('instructor_companion.settings')->get('waitlist_offer_hours');
    return $v === NULL ? self::DEFAULT_WAITLIST_OFFER_HOURS : max(0, (int) $v);
  }

  /**
   * Gives one waitlisted event the offer window if it has none.
   */
  public function ensureWaitlistExpiry(int $event_id): bool {
    $hours = $this->waitlistOfferHours();
    if ($hours <= 0 || !$this->database->schema()->tableExists('civicrm_event')) {
      return FALSE;
    }
    $updated = (int) $this->database->update('civicrm_event')
      ->fields(['expiration_time' => $hours])
      ->condition('id', $event_id)
      ->condition('has_waitlist', 1)
      ->where('(expiration_time IS NULL OR expiration_time = 0)')
      ->execute();
    if ($updated) {
      $this->logger->notice('Event @e: waitlist offers now expire after @h h if unpaid.', [
        '@e' => $event_id,
        '@h' => $hours,
      ]);
    }
    return $updated > 0;
  }

  /**
   * Waitlisted events (templates and upcoming) with no offer window.
   *
   * @return array<int, array{id:int,title:string,start:string,is_template:int}>
   *   Templates first, then upcoming events by date.
   */
  public function eventsMissingWaitlistExpiry(): array {
    if (!$this->database->schema()->tableExists('civicrm_event')) {
      return [];
    }
    $types = PostEventStatusService::closeoutEventTypes();
    $q = $this->database->select('civicrm_event', 'e')
      ->fields('e', ['id', 'title', 'template_title', 'start_date', 'is_template'])
      ->condition('e.has_waitlist', 1)
      ->where('(e.expiration_time IS NULL OR e.expiration_time = 0)');
    if ($types) {
      $q->condition('e.event_type_id', $types, 'IN');
    }
    $or = $q->orConditionGroup()
      ->condition('e.is_template', 1)
      ->condition(
        $q->andConditionGroup()
          ->condition('e.is_template', 0)
          ->condition('e.is_active', 1)
          ->condition('e.start_date', date('Y-m-d H:i:s'), '>')
      );
    $q->condition($or)->orderBy('e.is_template', 'DESC')->orderBy('e.start_date');
    $out = [];
    foreach ($q->execute() as $r) {
      $out[] = [
        'id' => (int) $r->id,
        'title' => (string) ($r->is_template ? ($r->template_title ?: $r->title) : $r->title),
        'start' => (string) ($r->start_date ?? ''),
        'is_template' => (int) $r->is_template,
      ];
    }
    return $out;
  }

  /**
   * Moves upcoming events and templates from one offer window to another.
   *
   * Only rows still carrying $from change, so a window staff typed on one
   * event by hand survives a change of the site-wide setting. Past events are
   * left as they ran.
   */
  public function restampWaitlistExpiry(int $from, int $to): int {
    if ($from === $to || $from <= 0 || $to <= 0 || !$this->database->schema()->tableExists('civicrm_event')) {
      return 0;
    }
    $q = $this->database->update('civicrm_event')
      ->fields(['expiration_time' => $to])
      ->condition('has_waitlist', 1)
      ->condition('expiration_time', $from);
    $or = $q->orConditionGroup()
      ->condition('is_template', 1)
      ->condition('start_date', date('Y-m-d H:i:s'), '>');
    $n = (int) $q->condition($or)->execute();
    if ($n) {
      $this->logger->notice('Waitlist offer window moved from @from h to @to h on @n upcoming event(s)/template(s).', [
        '@from' => $from,
        '@to' => $to,
        '@n' => $n,
      ]);
    }
    return $n;
  }

  /**
   * Applies the offer window to everything eventsMissingWaitlistExpiry() lists.
   */
  public function backfillWaitlistExpiry(): int {
    $hours = $this->waitlistOfferHours();
    if ($hours <= 0) {
      return 0;
    }
    $ids = array_column($this->eventsMissingWaitlistExpiry(), 'id');
    if (!$ids) {
      return 0;
    }
    $n = (int) $this->database->update('civicrm_event')
      ->fields(['expiration_time' => $hours])
      ->condition('id', $ids, 'IN')
      ->execute();
    if ($n) {
      $this->logger->notice('Waitlist offer window (@h h) set on @n event(s)/template(s): @ids', [
        '@h' => $hours,
        '@n' => $n,
        '@ids' => implode(', ', $ids),
      ]);
    }
    return $n;
  }

  /**
   * Configured fallback length in minutes.
   */
  public function defaultEventLengthMinutes(): int {
    $v = (int) ($this->configFactory->get('instructor_companion.settings')->get('default_event_length_minutes') ?? 0);
    return $v >= 15 ? $v : self::DEFAULT_EVENT_LENGTH_MINUTES;
  }

  /**
   * Length for an event with no end: the course's last same-day run, else the default.
   */
  public function lengthFor(?int $course_nid): int {
    if ($course_nid && $this->database->schema()->tableExists('civicrm_event__field_parent_course')) {
      $q = $this->database->select('civicrm_event', 'e');
      $q->innerJoin('civicrm_event__field_parent_course', 'c', 'c.entity_id = e.id AND c.deleted = 0');
      $q->addExpression('TIMESTAMPDIFF(MINUTE, e.start_date, e.end_date)', 'minutes');
      $q->condition('c.field_parent_course_target_id', $course_nid)
        ->condition('e.is_template', 0)
        ->isNotNull('e.end_date')
        ->where('DATE(e.start_date) = DATE(e.end_date)')
        ->where('e.end_date > e.start_date')
        ->orderBy('e.start_date', 'DESC')
        ->range(0, 1);
      $minutes = (int) $q->execute()->fetchField();
      if ($minutes >= 15 && $minutes <= 720) {
        return $minutes;
      }
    }
    return $this->defaultEventLengthMinutes();
  }

  /**
   * Parses a CiviCRM date param ('YmdHis', 'Y-m-d H:i:s', 'Y-m-d H:i'). Pure.
   */
  public static function parseCiviDate($value): ?int {
    $value = trim((string) $value);
    if ($value === '' || $value === 'null') {
      return NULL;
    }
    if (ctype_digit($value) && (strlen($value) === 14 || strlen($value) === 12)) {
      $dt = \DateTimeImmutable::createFromFormat(strlen($value) === 14 ? 'YmdHis' : 'YmdHi', $value);
      return $dt ? $dt->getTimestamp() : NULL;
    }
    $ts = strtotime($value);
    return $ts === FALSE ? NULL : $ts;
  }

  /**
   * Gives an event being saved an end time when it has none.
   *
   * For hook_civicrm_pre on Event create/edit. On edit a params array without
   * end_date means "unchanged", so the stored value decides. Templates are
   * left alone: they have no real date, and their copies are caught on the
   * edit that sets one.
   *
   * @return string|null
   *   The end_date written into $params ('YmdHis'), or NULL if none needed.
   */
  public function fillMissingEnd(string $op, ?int $event_id, array &$params): ?string {
    if (!empty($params['is_template'])) {
      return NULL;
    }
    $stored = NULL;
    if ($op === 'edit' && $event_id) {
      $stored = $this->database->select('civicrm_event', 'e')
        ->fields('e', ['start_date', 'end_date', 'is_template'])
        ->condition('e.id', $event_id)
        ->execute()
        ->fetchAssoc() ?: NULL;
      if ($stored && !empty($stored['is_template']) && !array_key_exists('is_template', $params)) {
        return NULL;
      }
    }
    $end_given = array_key_exists('end_date', $params);
    $end = $end_given ? self::parseCiviDate($params['end_date']) : self::parseCiviDate($stored['end_date'] ?? NULL);
    if ($end !== NULL) {
      return NULL;
    }
    $start = array_key_exists('start_date', $params)
      ? self::parseCiviDate($params['start_date'])
      : self::parseCiviDate($stored['start_date'] ?? NULL);
    if ($start === NULL) {
      return NULL;
    }
    $course = NULL;
    if ($event_id && $this->database->schema()->tableExists('civicrm_event__field_parent_course')) {
      $course = (int) $this->database->select('civicrm_event__field_parent_course', 'c')
        ->fields('c', ['field_parent_course_target_id'])
        ->condition('c.entity_id', $event_id)
        ->condition('c.deleted', 0)
        ->execute()
        ->fetchField() ?: NULL;
    }
    $minutes = $this->lengthFor($course);
    $params['end_date'] = date('YmdHis', $start + $minutes * 60);
    $this->logger->notice('Event @e saved with no end time; set to @m minutes after the start (@end).', [
      '@e' => $event_id ?: 'new',
      '@m' => $minutes,
      '@end' => date('Y-m-d H:i', $start + $minutes * 60),
    ]);
    return $params['end_date'];
  }

  /**
   * Active, non-template events from yesterday on with a start and no end.
   *
   * @return array<int, array{id:int,title:string,start:string,course:?int}>
   *   By start date.
   */
  public function eventsMissingEnd(): array {
    if (!$this->database->schema()->tableExists('civicrm_event')) {
      return [];
    }
    $q = $this->database->select('civicrm_event', 'e')
      ->fields('e', ['id', 'title', 'start_date'])
      ->condition('e.is_template', 0)
      ->condition('e.is_active', 1)
      ->isNotNull('e.start_date')
      ->isNull('e.end_date')
      ->condition('e.start_date', date('Y-m-d H:i:s', strtotime('-1 day')), '>=')
      ->orderBy('e.start_date');
    if ($this->database->schema()->tableExists('civicrm_event__field_parent_course')) {
      $q->leftJoin('civicrm_event__field_parent_course', 'c', 'c.entity_id = e.id AND c.deleted = 0');
      $q->addField('c', 'field_parent_course_target_id', 'course');
    }
    $out = [];
    foreach ($q->execute() as $r) {
      $out[] = [
        'id' => (int) $r->id,
        'title' => (string) $r->title,
        'start' => (string) $r->start_date,
        'course' => !empty($r->course) ? (int) $r->course : NULL,
      ];
    }
    return $out;
  }

  /**
   * Sets end_date on every event eventsMissingEnd() lists. Returns how many.
   *
   * Written directly (no CiviCRM save, so no Stripe or Slack side effects);
   * only end_date changes and only where it is NULL.
   */
  public function backfillMissingEnds(): int {
    $n = 0;
    foreach ($this->eventsMissingEnd() as $e) {
      $start = self::parseCiviDate($e['start']);
      if ($start === NULL) {
        continue;
      }
      $minutes = $this->lengthFor($e['course']);
      $updated = $this->database->update('civicrm_event')
        ->fields(['end_date' => date('Y-m-d H:i:s', $start + $minutes * 60)])
        ->condition('id', $e['id'])
        ->isNull('end_date')
        ->execute();
      if ($updated) {
        $n++;
        Cache::invalidateTags(['civicrm_event:' . $e['id']]);
        $this->logger->notice('Event @e had no end time; set to @m minutes after the start.', ['@e' => $e['id'], '@m' => $minutes]);
      }
    }
    return $n;
  }

}
