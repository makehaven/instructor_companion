<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps field_instructor_status in step with who has actually taught.
 *
 * JR, 2026-11-10: an instructor is active when they have taught a class in
 * the last 12 months, and that should happen without anyone remembering to
 * flip the flag. Hosting a meetup is not teaching, so only class event types
 * count (the instructor field doubles as "host" on meetups).
 *
 * The flag is also the approval gate for proposing a class, so two things
 * stop the rule locking people out:
 *  - someone staff approved (or whose profile was created) inside the window
 *    stays active until the window runs out, so a new instructor has a year
 *    to teach a first class;
 *  - someone with a class already scheduled is not deactivated.
 * A scheduled class does not activate anyone: only a class that has happened
 * does, so a proposal cannot approve its own author.
 *
 * A staff "inactive" set after the person's last class holds. If they teach
 * again after it, staff evidently let them, and they become active.
 */
class InstructorStatusSync {

  /**
   * Ticketed Workshop, Program, Ticketed Member Only.
   */
  public const CLASS_EVENT_TYPES = [6, 7, 16];

  public const DEFAULT_MONTHS = 12;
  public const STATE_LAST_RUN = 'instructor_companion.status_sync_last_run';
  public const STATE_MANUAL = 'instructor_companion.status_manual';

  /**
   * True while this service is saving a profile, so the presave hook can
   * tell an automatic change from a staff one.
   */
  protected bool $syncing = FALSE;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected InstructorApprovalGate $gate,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * The status a profile should have, from the facts about it.
   *
   * @param string $current
   *   'active' or 'inactive'.
   * @param int $last_class
   *   Timestamp of the last class taught, 0 for none.
   * @param int $upcoming
   *   Classes scheduled in the future.
   * @param int $grace_start
   *   When the approval window started: the last staff approval, else the
   *   profile's creation.
   * @param int $manual_inactive_at
   *   When staff last set the profile inactive, 0 for never.
   * @param int $now
   *   Current timestamp.
   * @param int $months
   *   Window length.
   */
  public static function decide(string $current, int $last_class, int $upcoming, int $grace_start, int $manual_inactive_at, int $now, int $months = self::DEFAULT_MONTHS): string {
    $cutoff = strtotime("-$months months", $now);
    $active = InstructorApprovalGate::STATUS_ACTIVE;
    $inactive = InstructorApprovalGate::STATUS_INACTIVE;

    if ($last_class && $last_class >= $cutoff) {
      if ($current !== $active && $manual_inactive_at > $last_class) {
        return $inactive;
      }
      return $active;
    }
    if ($current === $active && ($upcoming > 0 || $grace_start >= $cutoff)) {
      return $active;
    }
    return $inactive;
  }

  /**
   * Window in months; 0 switches the automatic rule off.
   */
  public function months(): int {
    $value = $this->configFactory->get('instructor_companion.settings')->get('auto_status_months');
    return $value === NULL ? self::DEFAULT_MONTHS : max(0, (int) $value);
  }

  /**
   * Cron entry: once a day.
   */
  public function run(): void {
    if ($this->months() === 0) {
      return;
    }
    $now = $this->time->getRequestTime();
    if ($now - (int) $this->state->get(self::STATE_LAST_RUN, 0) < 20 * 3600) {
      return;
    }
    $this->state->set(self::STATE_LAST_RUN, $now);
    $this->apply();
  }

  /**
   * Works out, and unless $dry_run saves, every status that should change.
   *
   * @return array[]
   *   One row per change: profile_id, uid, name, from, to, last_class,
   *   upcoming, reason.
   */
  public function apply(bool $dry_run = FALSE): array {
    $months = $this->months() ?: self::DEFAULT_MONTHS;
    $now = $this->time->getRequestTime();
    $storage = $this->entityTypeManager->getStorage('profile');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'instructor')
      ->condition('status', 1)
      ->execute();
    if (!$ids) {
      return [];
    }
    $profiles = $storage->loadMultiple($ids);
    $uids = [];
    foreach ($profiles as $p) {
      if ($p->getOwnerId()) {
        $uids[] = (int) $p->getOwnerId();
      }
    }
    $stats = $this->classStats($uids);
    $manual = (array) $this->state->get(self::STATE_MANUAL, []);

    $changes = [];
    foreach ($profiles as $p) {
      $uid = (int) $p->getOwnerId();
      if (!$uid || !$p->hasField('field_instructor_status')) {
        continue;
      }
      $current = $this->gate->status($p);
      $s = $stats[$uid] ?? ['last_class' => 0, 'upcoming' => 0];
      $m = $manual[$p->id()] ?? NULL;
      $approved_at = ($m && $m['status'] === InstructorApprovalGate::STATUS_ACTIVE) ? (int) $m['time'] : 0;
      $manual_inactive_at = ($m && $m['status'] === InstructorApprovalGate::STATUS_INACTIVE) ? (int) $m['time'] : 0;
      $grace_start = max($approved_at, (int) $p->getCreatedTime());

      $target = self::decide($current, $s['last_class'], $s['upcoming'], $grace_start, $manual_inactive_at, $now, $months);
      if ($target === $current) {
        continue;
      }
      $owner = $p->getOwner();
      $reason = $target === InstructorApprovalGate::STATUS_ACTIVE
        ? 'taught a class on ' . date('Y-m-d', $s['last_class'])
        : ($s['last_class']
          ? 'last class ' . date('Y-m-d', $s['last_class']) . ", none in $months months and none scheduled"
          : "no class taught within $months months of approval");
      $changes[] = [
        'profile_id' => (int) $p->id(),
        'uid' => $uid,
        'name' => $owner ? $owner->getDisplayName() : (string) $p->label(),
        'from' => $current,
        'to' => $target,
        'last_class' => $s['last_class'],
        'upcoming' => $s['upcoming'],
        'reason' => $reason,
      ];
      if ($dry_run) {
        continue;
      }
      $p->set('field_instructor_status', $target);
      $p->setNewRevision(TRUE);
      if (method_exists($p, 'setRevisionLogMessage')) {
        $p->setRevisionLogMessage("Instructor status set to $target automatically: $reason");
      }
      $this->syncing = TRUE;
      try {
        $p->save();
      }
      finally {
        $this->syncing = FALSE;
      }
      $this->logger->notice('Instructor @name (uid @uid) set to @to automatically: @reason', [
        '@name' => end($changes)['name'],
        '@uid' => $uid,
        '@to' => $target,
        '@reason' => $reason,
      ]);
    }
    return $changes;
  }

  /**
   * Whether the profile being saved right now is this service's own change.
   */
  public function isSyncing(): bool {
    return $this->syncing;
  }

  /**
   * Records a staff decision, so the automatic rule respects it.
   */
  public function recordManual(int $profile_id, string $status): void {
    $manual = (array) $this->state->get(self::STATE_MANUAL, []);
    $manual[$profile_id] = ['status' => $status, 'time' => $this->time->getRequestTime()];
    $this->state->set(self::STATE_MANUAL, $manual);
  }

  /**
   * Last class taught and classes scheduled, per instructor uid.
   *
   * Classes only: meetups, member events and the like list their host in the
   * same field and are not teaching.
   *
   * @return array<int, array{last_class:int, upcoming:int, classes:int, classes_12mo:int}>
   */
  public function classStats(array $uids): array {
    $out = [];
    if (!$uids || !$this->database->schema()->tableExists('civicrm_event__field_civi_event_instructor')) {
      return $out;
    }
    $now = date('Y-m-d H:i:s', $this->time->getRequestTime());
    $year_ago = date('Y-m-d H:i:s', strtotime('-12 months', $this->time->getRequestTime()));
    $q = $this->database->select('civicrm_event__field_civi_event_instructor', 'i');
    $q->join('civicrm_event', 'e', 'e.id = i.entity_id');
    $q->addField('i', 'field_civi_event_instructor_target_id', 'uid');
    $q->addExpression("SUM(e.start_date <= '$now')", 'classes');
    $q->addExpression("SUM(e.start_date <= '$now' AND e.start_date >= '$year_ago')", 'classes_12mo');
    $q->addExpression("SUM(e.start_date > '$now' AND e.is_active = 1)", 'upcoming');
    $q->addExpression("MAX(CASE WHEN e.start_date <= '$now' THEN e.start_date END)", 'last_class');
    $q->condition('i.field_civi_event_instructor_target_id', $uids, 'IN')
      ->condition('i.deleted', 0)
      ->condition('e.is_template', 0)
      ->condition('e.event_type_id', self::CLASS_EVENT_TYPES, 'IN')
      ->groupBy('i.field_civi_event_instructor_target_id');
    foreach ($q->execute() as $row) {
      $out[(int) $row->uid] = [
        'last_class' => !empty($row->last_class) ? (int) strtotime($row->last_class) : 0,
        'upcoming' => (int) $row->upcoming,
        'classes' => (int) $row->classes,
        'classes_12mo' => (int) $row->classes_12mo,
      ];
    }
    return $out;
  }

}
