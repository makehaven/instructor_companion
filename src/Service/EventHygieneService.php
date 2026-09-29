<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

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

}
