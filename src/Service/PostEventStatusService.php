<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\instructor_companion\Controller\ClassCheckoutController;

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
   * The participant-facing Event Feedback webform (survey link in reminders).
   */
  public const EVALUATION_WEBFORM = 'webform_1181';

  /**
   * The satisfaction rating element on that form (1-5; 0 means unanswered).
   */
  public const EVALUATION_RATING = 'overall_how_satisfied_were_you_with_the_event';

  /**
   * The free-text element that carries what went wrong.
   */
  public const EVALUATION_IMPROVE = 'was_there_anything_that_could_have_been_improved';

  /**
   * At or below this rating, a response is worth a staff member's attention.
   */
  public const LOW_RATING = 3;

  /**
   * CiviCRM event types that owe post-class wrap-up, when nothing is configured.
   *
   * 6 = Ticketed Workshop, 16 = Ticketed Member Only. Deliberately not every
   * type: a Meetup is hosted rather than taught, so its host owes no badges
   * and no payment; a Program is a multi-week cohort whose first session is
   * not the end of anything; a Tour is staff-run. Asking those people to
   * "close out" is how a reminder becomes noise — 6 of the 19 reminders sent
   * to 2026-09-09 went to meetups and programs.
   */
  public const DEFAULT_CLOSEOUT_EVENT_TYPES = [6, 16];

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
   * Classes that have ended with wrap-up still outstanding.
   *
   * The staff-side counterpart to the instructor's post-event hub: the same
   * four steps, for every recent class at once. Classes with no counted
   * participants are skipped — nagging about a class nobody attended is noise,
   * and it is the same rule the reminder cron applies.
   *
   * @param int $days
   *   How far back to look from now, or zero for all history.
   * @param int $limit
   *   Maximum classes to return.
   * @param int $older_than_days
   *   Exclude classes newer than this age. Zero includes all ended classes.
   * @param int $offset
   *   Number of outstanding classes to skip for pagination.
   *
   * @return array<int, array>
   *   Newest-ended first. Each row: event_id, title, uid, instructor,
   *   ended (timestamp), status (see ::getStatus()), attendees (int),
   *   evaluations (int) — participant Event Feedback responses for the class.
   */
  public function closeoutBacklog(int $days = 30, int $limit = 60, int $older_than_days = 0, int $offset = 0): array {
    $now = \Drupal::time()->getRequestTime();
    $since = $days > 0 ? date('Y-m-d H:i:s', $now - $days * 86400) : '1970-01-01 00:00:00';
    $until = date('Y-m-d H:i:s', $now - $older_than_days * 86400);

    $q = $this->database->select('civicrm_event', 'e');
    $q->innerJoin('civicrm_event__field_civi_event_instructor', 'i', 'e.id = i.entity_id AND i.deleted = 0');
    $q->addField('e', 'id', 'event_id');
    $q->addField('e', 'title', 'title');
    $q->addField('i', 'field_civi_event_instructor_target_id', 'uid');
    $q->addExpression('COALESCE(e.end_date, e.start_date)', 'ended');
    $q->where('COALESCE(e.end_date, e.start_date) BETWEEN :lo AND :hi', [':lo' => $since, ':hi' => $until]);
    if ($older_than_days > 0) {
      $q->where('COALESCE(e.end_date, e.start_date) < :cutoff', [':cutoff' => $until]);
    }
    $q->condition('e.is_active', 1);
    $q->condition('e.is_template', 0);
    $types = self::closeoutEventTypes();
    if ($types) {
      $q->condition('e.event_type_id', $types, 'IN');
    }
    $q->orderBy('ended', 'DESC');

    $evaluations = $this->evaluationSummaries($since);
    $user_storage = $this->entityTypeManager->getStorage('user');
    $rows = [];

    foreach ($q->execute() as $row) {
      $event_id = (int) $row->event_id;
      $uid = (int) $row->uid;
      if (!$uid) {
        continue;
      }
      $attendees = $this->countedParticipants($event_id);
      if (!$attendees) {
        continue;
      }
      $status = $this->getStatus($event_id, $uid);
      if ($status['all_complete']) {
        continue;
      }
      if ($offset > 0) {
        $offset--;
        continue;
      }
      $instructor = $user_storage->load($uid);
      $rows[] = [
        'event_id' => $event_id,
        'title' => (string) $row->title,
        'uid' => $uid,
        // Translation is the caller's job — this service has no string trait.
        'instructor' => $instructor ? $instructor->getDisplayName() : '',
        'ended' => strtotime((string) $row->ended) ?: NULL,
        'status' => $status,
        'attendees' => $attendees,
        'evaluation' => $evaluations[$event_id] ?? ['count' => 0, 'lowest' => NULL, 'average' => NULL],
      ];
      if (count($rows) >= $limit) {
        break;
      }
    }
    return $rows;
  }

  /**
   * Event types that owe post-class wrap-up.
   *
   * Static so the reminder cron can apply exactly the same scope without
   * having to hold a reference to this service's config.
   *
   * @return int[]
   *   CiviCRM event_type_id values; empty means "every type".
   */
  public static function closeoutEventTypes(): array {
    $configured = \Drupal::config('instructor_companion.settings')->get('closeout_event_types');
    if ($configured === NULL) {
      return self::DEFAULT_CLOSEOUT_EVENT_TYPES;
    }
    return array_values(array_filter(array_map('intval', (array) $configured)));
  }

  /**
   * The CiviCRM event types, id => label, for the settings form.
   *
   * @return array<int, string>
   *   Empty when CiviCRM's tables are not present.
   */
  public static function eventTypeOptions(): array {
    $db = \Drupal::database();
    if (!$db->schema()->tableExists('civicrm_option_value')) {
      return [];
    }
    $q = $db->select('civicrm_option_value', 'ov');
    $q->innerJoin('civicrm_option_group', 'og', "og.id = ov.option_group_id AND og.name = 'event_type'");
    $q->addField('ov', 'value', 'id');
    $q->addField('ov', 'label', 'label');
    $q->orderBy('ov.label');
    $out = [];
    foreach ($q->execute() as $row) {
      if (ctype_digit((string) $row->id)) {
        $out[(int) $row->id] = (string) $row->label;
      }
    }
    return $out;
  }

  /**
   * Counted (non-test, is_counted status) participants on an event.
   */
  public function countedParticipants(int $event_id): int {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $q->condition('p.event_id', $event_id);
    $q->condition('p.is_test', 0);
    $q->condition('pst.is_counted', 1);
    return (int) $q->countQuery()->execute()->fetchField();
  }

  /**
   * Participant Event Feedback per event: how many, and how they scored it.
   *
   * The survey is webform_1181, linked from the "Thanks for Attending!" and
   * "3 days Later Reminder" CiviCRM reminders, which pass ?event_id=. Older
   * submissions predate that parameter and simply do not count.
   *
   * A rating of 0 means the question was skipped, not "terrible" — it is
   * excluded from both the average and the lowest score.
   *
   * @return array<int, array{count: int, lowest: int|null, average: float|null}>
   *   Keyed by event id.
   */
  protected function evaluationSummaries(string $since): array {
    if (!$this->database->schema()->tableExists('webform_submission_data')) {
      return [];
    }
    $q = $this->database->select('webform_submission_data', 'ev');
    $q->innerJoin('webform_submission', 'ws', 'ws.sid = ev.sid');
    $q->leftJoin('webform_submission_data', 'sat', "sat.sid = ws.sid AND sat.name = :sat", [':sat' => self::EVALUATION_RATING]);
    $q->addField('ev', 'value', 'event_id');
    $q->addExpression('COUNT(DISTINCT ws.sid)', 'n');
    $q->addExpression('MIN(NULLIF(sat.value, 0))', 'lowest');
    $q->addExpression('AVG(NULLIF(sat.value, 0))', 'average');
    $q->condition('ws.webform_id', self::EVALUATION_WEBFORM);
    $q->condition('ev.name', 'event_id');
    // Responses arrive days after the class, so look a little wider than the
    // backlog window itself.
    $q->condition('ws.created', strtotime($since) - 30 * 86400, '>=');
    $q->groupBy('ev.value');
    $out = [];
    foreach ($q->execute() as $row) {
      if (!ctype_digit((string) $row->event_id)) {
        continue;
      }
      $out[(int) $row->event_id] = [
        'count' => (int) $row->n,
        'lowest' => $row->lowest === NULL ? NULL : (int) $row->lowest,
        'average' => $row->average === NULL ? NULL : round((float) $row->average, 1),
      ];
    }
    return $out;
  }

  /**
   * Evaluations that flag a problem: rated at or below LOW_RATING.
   *
   * Per-class averages are noise at one or two responses, but a single low
   * rating is signal at any n — and the free-text that comes with it is the
   * most useful thing in the whole survey. Two instructor no-shows in
   * August 2026 were reported here and read by nobody.
   *
   * @param int $days
   *   How far back to look.
   * @param int $limit
   *   Maximum responses to return.
   *
   * @return array<int, array>
   *   Newest first: sid, created, event_id, event_title, rating, comment.
   */
  public function lowRatedEvaluations(int $days = 90, int $limit = 15): array {
    if (!$this->database->schema()->tableExists('webform_submission_data')) {
      return [];
    }
    $since = \Drupal::time()->getRequestTime() - $days * 86400;

    $q = $this->database->select('webform_submission', 'ws');
    $q->innerJoin('webform_submission_data', 'sat', "sat.sid = ws.sid AND sat.name = :sat", [':sat' => self::EVALUATION_RATING]);
    $q->leftJoin('webform_submission_data', 'ev', "ev.sid = ws.sid AND ev.name = 'event_id'");
    $q->leftJoin('webform_submission_data', 'ti', "ti.sid = ws.sid AND ti.name = 'event_title'");
    $q->leftJoin('webform_submission_data', 'imp', "imp.sid = ws.sid AND imp.name = :imp", [':imp' => self::EVALUATION_IMPROVE]);
    $q->addField('ws', 'sid', 'sid');
    $q->addField('ws', 'created', 'created');
    $q->addField('sat', 'value', 'rating');
    $q->addField('ev', 'value', 'event_id');
    $q->addField('ti', 'value', 'event_title');
    $q->addField('imp', 'value', 'comment');
    $q->condition('ws.webform_id', self::EVALUATION_WEBFORM);
    $q->condition('ws.created', $since, '>=');
    // 0 is "not answered", so the floor is 1.
    $q->condition('sat.value', [1, self::LOW_RATING], 'BETWEEN');
    $q->orderBy('ws.created', 'DESC');
    $q->range(0, $limit);

    $out = [];
    foreach ($q->execute() as $row) {
      $out[] = [
        'sid' => (int) $row->sid,
        'created' => (int) $row->created,
        'rating' => (int) $row->rating,
        'event_id' => ctype_digit((string) $row->event_id) ? (int) $row->event_id : NULL,
        'event_title' => (string) ($row->event_title ?? ''),
        'comment' => trim((string) ($row->comment ?? '')),
      ];
    }
    return $out;
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
      'badges_done_pairs' => $this->countCheckedOutPairs($participant_uids, $badge_tids)
      + $this->countNotPassedPairs($event_id, $participant_uids, $badge_tids),
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
   * Counts (attendee, badge) pairs the instructor marked "attended, did not
   * pass" for this event. Handled from the instructor's point of view — the
   * student has to retake — so they must not keep the badges step open.
   *
   * Only pairs WITHOUT a class stamp are counted, so a later pass (which
   * clears the record anyway) is never double-counted.
   */
  protected function countNotPassedPairs(int $event_id, array $uids, array $badge_tids): int {
    if (!$uids || !$badge_tids) {
      return 0;
    }
    $map = (array) $this->state->get(ClassCheckoutController::NOT_PASSED_STATE_KEY, []);
    if (!$map) {
      return 0;
    }
    $count = 0;
    foreach ($uids as $uid) {
      foreach ($badge_tids as $tid) {
        if (isset($map[ClassCheckoutController::notPassedKey($event_id, (int) $uid, (int) $tid)])) {
          $count++;
        }
      }
    }
    return $count;
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
