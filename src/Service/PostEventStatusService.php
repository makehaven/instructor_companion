<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Single source of truth for an instructor's post-event task completion.
 *
 * A class's post-event flow has four steps, in dependency order:
 *   1. attendance — instructor confirmed who actually showed up
 *   2. badges     — every counted attendee checked out for each event badge
 *   3. feedback   — an `instructor_feedback` webform submission exists for the
 *                   event (also carries the materials-to-reorder signal)
 *   4. payment    — a non-draft payment_request exists for this event + payee.
 *
 * The hub page, the dashboard rows, and the +48h reminder cron all read their
 * state from here so the three surfaces never drift. The actual yes/no
 * decision lives in the pure, Drupal-free ::computeStatus() so it can be unit
 * tested directly (this module tests pure formulas, not the DB layer).
 */
class PostEventStatusService {

  public const STEP_ATTENDANCE = 'attendance';
  public const STEP_BADGES = 'badges';
  public const STEP_FEEDBACK = 'feedback';
  public const STEP_PAYMENT = 'payment';

  /**
   * Ordered step keys.
   */
  public const STEPS = [
    self::STEP_ATTENDANCE,
    self::STEP_BADGES,
    self::STEP_FEEDBACK,
    self::STEP_PAYMENT,
  ];

  /**
   * Untranslated step labels. Callers wrap with t() for display.
   */
  public const LABELS = [
    self::STEP_ATTENDANCE => 'Take attendance',
    self::STEP_BADGES => 'Approve badges',
    self::STEP_FEEDBACK => 'Post-class feedback & materials',
    self::STEP_PAYMENT => 'Request payment for hours',
  ];

  /**
   * State key holding a map of event_id => ['uid' => int, 'time' => int].
   *
   * Mirrors the single-key array pattern used by the proposal-deactivate
   * queue in this module (see ProposalDeactivateSubscriber).
   */
  protected const ATTENDANCE_STATE_KEY = 'instructor_companion.attendance_confirmed';

  /**
   * Payment_request status values that mean the instructor submitted it.
   *
   * 'draft' = saved but not submitted; 'rejected' = needs redo. Neither
   * counts as the instructor having completed their part.
   */
  protected const PAYMENT_DONE_STATUSES = ['submitted', 'approved', 'paid'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the full post-event status for an event + instructor.
   *
   * @return array
   *   Keys: 'steps' (ordered list, each with key/label/complete/applicable/
   *   detail), 'all_complete' (bool, applicable steps only),
   *   'incomplete' (step keys still outstanding), 'incomplete_labels',
   *   'progress' (e.g. "2/3"), 'event_id'.
   */
  public function getStatus(int $event_id, int $instructor_uid): array {
    $signals = $this->gatherSignals($event_id, $instructor_uid);
    return ['event_id' => $event_id] + self::computeStatus($signals);
  }

  /**
   * Records that the instructor has confirmed attendance for an event.
   */
  public function confirmAttendance(int $event_id, int $uid): void {
    $map = (array) $this->state->get(self::ATTENDANCE_STATE_KEY, []);
    $map[$event_id] = ['uid' => $uid, 'time' => \Drupal::time()->getRequestTime()];
    $this->state->set(self::ATTENDANCE_STATE_KEY, $map);
  }

  /**
   * Whether attendance has been confirmed for an event.
   */
  public function isAttendanceConfirmed(int $event_id): bool {
    $map = (array) $this->state->get(self::ATTENDANCE_STATE_KEY, []);
    return !empty($map[$event_id]);
  }

  /**
   * Pure decision logic. No Drupal, no DB — unit tested directly.
   *
   * @param array $s
   *   Raw signals:
   *   - attendance_confirmed (bool)
   *   - badges_applicable (bool): event awards at least one badge
   *   - badges_total_pairs (int): attendees x event badges to check off
   *   - badges_done_pairs (int): of those, how many are stamped complete
   *   - feedback_submitted (bool)
   *   - payment_done (bool): a non-draft payment_request exists for payee+event
   *   - payment_detail (string|null): short status label for display.
   *
   * @return array
   *   See ::getStatus().
   */
  public static function computeStatus(array $s): array {
    $attendance_complete = !empty($s['attendance_confirmed']);

    $badges_applicable = !empty($s['badges_applicable']);
    $total_pairs = (int) ($s['badges_total_pairs'] ?? 0);
    $done_pairs = (int) ($s['badges_done_pairs'] ?? 0);
    // No badges on the event → step is not applicable. Badges configured but
    // nobody attended → nothing to do → vacuously complete.
    $badges_complete = !$badges_applicable
      || $total_pairs === 0
      || $done_pairs >= $total_pairs;

    $feedback_complete = !empty($s['feedback_submitted']);
    $payment_complete = !empty($s['payment_done']);

    $steps = [];
    $steps[self::STEP_ATTENDANCE] = [
      'key' => self::STEP_ATTENDANCE,
      'label' => self::LABELS[self::STEP_ATTENDANCE],
      'applicable' => TRUE,
      'complete' => $attendance_complete,
      'detail' => $attendance_complete ? 'Confirmed' : 'Not yet confirmed',
    ];
    $steps[self::STEP_BADGES] = [
      'key' => self::STEP_BADGES,
      'label' => self::LABELS[self::STEP_BADGES],
      'applicable' => $badges_applicable,
      'complete' => $badges_complete,
      'detail' => !$badges_applicable
        ? 'No badges for this class'
        : ($badges_complete
          ? 'All attendees checked out'
          : ($total_pairs - $done_pairs) . ' of ' . $total_pairs . ' still to check off'),
    ];
    $steps[self::STEP_FEEDBACK] = [
      'key' => self::STEP_FEEDBACK,
      'label' => self::LABELS[self::STEP_FEEDBACK],
      'applicable' => TRUE,
      'complete' => $feedback_complete,
      'detail' => $feedback_complete ? 'Submitted' : 'Not yet submitted',
    ];
    $steps[self::STEP_PAYMENT] = [
      'key' => self::STEP_PAYMENT,
      'label' => self::LABELS[self::STEP_PAYMENT],
      'applicable' => TRUE,
      'complete' => $payment_complete,
      'detail' => $payment_complete
        ? (string) ($s['payment_detail'] ?? 'Submitted')
        : (string) ($s['payment_detail'] ?? 'No request submitted'),
    ];

    $incomplete = [];
    foreach (self::STEPS as $key) {
      if ($steps[$key]['applicable'] && !$steps[$key]['complete']) {
        $incomplete[] = $key;
      }
    }
    $applicable_count = count(array_filter($steps, static fn(array $st): bool => $st['applicable']));
    $done_count = $applicable_count - count($incomplete);

    return [
      'steps' => array_values($steps),
      'all_complete' => $incomplete === [],
      'incomplete' => $incomplete,
      'incomplete_labels' => array_map(static fn(string $k): string => self::LABELS[$k], $incomplete),
      'progress' => $done_count . '/' . $applicable_count,
    ];
  }

  /**
   * Gathers raw DB-backed signals for an event + instructor.
   */
  protected function gatherSignals(int $event_id, int $instructor_uid): array {
    $badge_tids = $this->getEventBadgeTids($event_id);
    $participant_uids = $badge_tids ? array_keys($this->getParticipantUids($event_id)) : [];
    $payment = $this->getPaymentSignal($event_id, $instructor_uid);

    return [
      'attendance_confirmed' => $this->isAttendanceConfirmed($event_id),
      'badges_applicable' => $badge_tids !== [],
      'badges_total_pairs' => count($participant_uids) * count($badge_tids),
      'badges_done_pairs' => $this->countCheckedOutPairs($participant_uids, $badge_tids),
      'feedback_submitted' => $this->hasFeedbackSubmission($event_id),
      'payment_done' => $payment['done'],
      'payment_detail' => $payment['detail'],
    ];
  }

  /**
   * Badge taxonomy term IDs awarded by an event (empty if none).
   */
  protected function getEventBadgeTids(int $event_id): array {
    $event = $this->entityTypeManager->getStorage('civicrm_event')->load($event_id);
    if (!$event || !$event->hasField('field_civi_event_badges')) {
      return [];
    }
    $tids = [];
    foreach ($event->get('field_civi_event_badges') as $item) {
      if ($item->target_id) {
        $tids[] = (int) $item->target_id;
      }
    }
    return array_values(array_unique($tids));
  }

  /**
   * Returns [uid => display_name] for counted, non-test participants.
   *
   * Mirrors ClassCheckoutController::getParticipantUids() — the canonical
   * "who counts as an attendee" query. Kept in sync deliberately.
   */
  protected function getParticipantUids(int $event_id): array {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_participant_status_type', 'pst', 'p.status_id = pst.id');
    $q->innerJoin('civicrm_uf_match', 'ufm', 'ufm.contact_id = p.contact_id');
    $q->addField('ufm', 'uf_id', 'uid');
    $q->condition('p.event_id', $event_id);
    $q->condition('p.is_test', 0);
    $q->condition('pst.is_counted', 1);
    $q->distinct();
    $uids = $q->execute()->fetchCol();
    if (!$uids) {
      return [];
    }
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
    $out = [];
    foreach ($users as $uid => $user) {
      $out[(int) $uid] = $user->getDisplayName();
    }
    return $out;
  }

  /**
   * Counts (attendee, badge) pairs that have a class-completed badge_request.
   */
  protected function countCheckedOutPairs(array $uids, array $badge_tids): int {
    if (!$uids || !$badge_tids) {
      return 0;
    }
    $q = $this->database->select('node__field_member_to_badge', 'm');
    $q->innerJoin('node__field_badge_requested', 'b', 'm.entity_id = b.entity_id');
    $q->innerJoin('node__field_class_completed_date', 'c', 'm.entity_id = c.entity_id');
    $q->addExpression('COUNT(DISTINCT CONCAT(m.field_member_to_badge_target_id, :sep, b.field_badge_requested_target_id))', 'pairs', [':sep' => '-']);
    $q->condition('m.field_member_to_badge_target_id', $uids, 'IN');
    $q->condition('b.field_badge_requested_target_id', $badge_tids, 'IN');
    $q->isNotNull('c.field_class_completed_date_value');
    return (int) $q->execute()->fetchField();
  }

  /**
   * Whether an instructor_feedback submission exists for this event.
   *
   * Exact event_id match — replaces the old 30-day timestamp heuristic. Until
   * the webform ships there are simply no rows and this returns FALSE.
   */
  protected function hasFeedbackSubmission(int $event_id): bool {
    return (bool) $this->database->select('webform_submission_data', 'd')
      ->fields('d', ['sid'])
      ->condition('d.webform_id', 'instructor_feedback')
      ->condition('d.name', 'event_id')
      ->condition('d.value', (string) $event_id)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Whether a non-draft payment_request exists for this event + payee.
   *
   * @return array
   *   ['done' => bool, 'detail' => string|null].
   */
  protected function getPaymentSignal(int $event_id, int $instructor_uid): array {
    try {
      $q = $this->database->select('payment_request_field_data', 'pr');
      $q->innerJoin('payment_request__field_payee', 'payee', 'pr.id = payee.entity_id AND payee.deleted = 0');
      $q->innerJoin('payment_request__field_event', 'ev', 'pr.id = ev.entity_id AND ev.deleted = 0');
      $q->leftJoin('payment_request__field_status', 'st', 'pr.id = st.entity_id AND st.deleted = 0');
      $q->addField('st', 'field_status_value', 'status');
      $q->condition('payee.field_payee_target_id', $instructor_uid);
      $q->condition('ev.field_event_target_id', $event_id);
      $statuses = array_map('strtolower', array_filter($q->execute()->fetchCol(), 'strlen'));
    }
    catch (\Throwable $e) {
      return ['done' => FALSE, 'detail' => NULL];
    }
    foreach (['paid', 'approved', 'submitted'] as $rank) {
      if (in_array($rank, $statuses, TRUE)) {
        return ['done' => TRUE, 'detail' => ucfirst($rank)];
      }
    }
    if (in_array('draft', $statuses, TRUE)) {
      return ['done' => FALSE, 'detail' => 'Draft started'];
    }
    return ['done' => FALSE, 'detail' => NULL];
  }

}
