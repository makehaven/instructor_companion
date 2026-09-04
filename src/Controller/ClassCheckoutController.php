<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class-end checkout UI for instructors.
 *
 * A class that lists a badge (field_civi_event_badges) includes the badging
 * session a facilitator would otherwise run, and the instructor is the
 * badger. So the instructor's "mark complete" click IS the checkout:
 *  - stamps field_class_completed_date on the badge_request (creating a
 *    pending one if needed),
 *  - marks the CiviCRM participant Attended,
 *  - activates the badge right away when the student has already passed the
 *    quiz (or the badge has no quiz), or
 *  - otherwise emails the quiz link; assign_badge_from_quiz activates the
 *    badge automatically when the 100% pass lands on a class-stamped request.
 *
 * No staff step and no training-documentation form are involved.
 */
class ClassCheckoutController extends ControllerBase {

  public function build(int $event_id): array {
    $event = $this->loadEvent($event_id);
    if (!$event) {
      throw new NotFoundHttpException();
    }

    $badge_tids = $this->getEventBadgeTids($event);
    if (!$badge_tids) {
      return [
        '#markup' => $this->t('This class has no badges to check off (field_civi_event_badges is empty).'),
      ];
    }

    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $badge_terms = $term_storage->loadMultiple($badge_tids);

    $participants = $this->getParticipantUids($event_id);

    $build = [];
    $build['#attached']['library'][] = 'instructor_companion/dashboard';
    $build['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['class-checkout-intro']],
      'title' => [
        '#markup' => '<h2>' . $this->t('Class Checkout: @label', ['@label' => $event->label()]) . '</h2>',
      ],
      'help' => [
        '#markup' => '<p>' . $this->t('Mark each student who completed the class. This is their badge checkout: the badge becomes active immediately if they have already passed the quiz, otherwise the moment they pass it (they get an email with the quiz link). No staff review or documentation form is needed.') . '</p>',
      ],
    ];

    foreach ($badge_terms as $badge_term) {
      $rows = [];
      foreach ($participants as $uid => $participant_name) {
        $rows[] = $this->buildParticipantRow($event_id, $uid, $participant_name, $badge_term);
      }
      $build['badge_' . $badge_term->id()] = [
        '#type' => 'details',
        '#title' => $badge_term->getName(),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Student'),
            $this->t('Quiz'),
            $this->t('Class Done'),
            $this->t('Badge Status'),
            $this->t('Action'),
          ],
          '#rows' => $rows,
          '#empty' => $this->t('No counted participants on this class.'),
        ],
      ];
    }

    return $build;
  }

  public function markComplete(int $event_id, int $user_id, int $badge_tid): RedirectResponse {
    $event = $this->loadEvent($event_id);
    $badge_term = $this->entityTypeManager()->getStorage('taxonomy_term')->load($badge_tid);
    $student = $this->entityTypeManager()->getStorage('user')->load($user_id);

    if (!$event || !$badge_term instanceof TermInterface || !$student) {
      throw new NotFoundHttpException();
    }
    if (!in_array($badge_tid, $this->getEventBadgeTids($event), TRUE)) {
      throw new AccessDeniedHttpException('Badge not associated with this class.');
    }
    if (!in_array($user_id, array_keys($this->getParticipantUids($event_id)), TRUE)) {
      throw new AccessDeniedHttpException('User is not a counted participant on this class.');
    }

    $badge_request = $this->loadExistingBadgeRequest($user_id, $badge_tid);
    $now = (new DrupalDateTime('now', 'UTC'))->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    $is_new = FALSE;

    if (!$badge_request) {
      // No badge_request yet — create one in pending state.
      $badge_request = Node::create([
        'type' => 'badge_request',
        'title' => 'Badge Request for ' . $badge_term->getName() . ' by User ' . $user_id,
        'field_badge_requested' => ['target_id' => $badge_tid],
        'field_badge_status' => 'pending',
        'field_member_to_badge' => ['target_id' => $user_id],
        'field_class_completed_date' => $now,
      ]);
      $badge_request->setRevisionLogMessage('Created by class checkout from event #' . $event_id . '.');
      $badge_request->save();
      $is_new = TRUE;
    }
    elseif ($badge_request->get('field_class_completed_date')->isEmpty()) {
      $badge_request->set('field_class_completed_date', $now);
      $badge_request->setNewRevision(TRUE);
      $badge_request->setRevisionLogMessage('Class completion stamped via class checkout from event #' . $event_id . '.');
      $badge_request->save();
    }

    $messenger = $this->messenger();
    $student_name = $student->getDisplayName();
    $badge_name = $badge_term->getName();

    // Attendance: the instructor just attested they were in the room.
    $this->markParticipantAttended($event_id, $user_id);
    // A pass supersedes any earlier "did not pass" for this class.
    $this->setNotPassed($event_id, $user_id, $badge_tid, FALSE);

    $quiz_id = $badge_term->hasField('field_badge_quiz_reference')
      ? (int) ($badge_term->get('field_badge_quiz_reference')->target_id ?? 0)
      : 0;
    $quiz_passed = $quiz_id > 0 && $this->userHasPassedQuiz($user_id, $quiz_id);
    $current_status = (string) ($badge_request->get('field_badge_status')->value ?? '');
    $outcome = self::resolveOutcome($current_status, $quiz_id > 0, $quiz_passed);

    switch ($outcome) {
      case self::OUTCOME_ACTIVATE:
        $badge_request->set('field_badge_status', 'active');
        $badge_request->setNewRevision(TRUE);
        $badge_request->setRevisionUserId((int) $this->currentUser()->id());
        $badge_request->setRevisionLogMessage('Activated by instructor class checkout from event #' . $event_id . '.');
        $badge_request->save();
        $messenger->addStatus($this->t('@student: @badge badge is now active.', [
          '@student' => $student_name,
          '@badge' => $badge_name,
        ]));
        \Drupal::logger('instructor_companion')->notice(
          'Class checkout activated badge_request @nid for uid @uid badge @badge (tid @tid) from event @event by instructor @inst.',
          [
            '@nid' => $badge_request->id(),
            '@uid' => $user_id,
            '@badge' => $badge_name,
            '@tid' => $badge_tid,
            '@event' => $event_id,
            '@inst' => $this->currentUser()->id(),
          ]
        );
        break;

      case self::OUTCOME_AWAIT_QUIZ:
        $this->sendQuizReminderEmail($student, $badge_term, $quiz_id, $event);
        $messenger->addWarning($this->t('@student: class checkout recorded for @badge. They have not passed the quiz yet — the badge activates automatically when they do, and a reminder email with the quiz link was sent.', [
          '@student' => $student_name,
          '@badge' => $badge_name,
        ]));
        break;

      case self::OUTCOME_ALREADY_ACTIVE:
        $messenger->addStatus($this->t('@student: class date recorded. Their @badge badge was already active.', [
          '@student' => $student_name,
          '@badge' => $badge_name,
        ]));
        break;

      default:
        // Suspended / expired / other: leave staff-managed statuses alone.
        $messenger->addWarning($this->t('@student: class date recorded, but their @badge badge is "@status" and was left unchanged. Contact staff if it should be reactivated.', [
          '@student' => $student_name,
          '@badge' => $badge_name,
          '@status' => $current_status,
        ]));
        break;
    }

    if ($is_new) {
      \Drupal::logger('instructor_companion')->notice(
        'Class checkout created badge_request @nid for uid @uid badge @badge (tid @tid) from event @event by instructor @inst.',
        [
          '@nid' => $badge_request->id(),
          '@uid' => $user_id,
          '@badge' => $badge_name,
          '@tid' => $badge_tid,
          '@event' => $event_id,
          '@inst' => $this->currentUser()->id(),
        ]
      );
    }

    return new RedirectResponse(Url::fromRoute('instructor_companion.class_checkout', ['event_id' => $event_id])->toString());
  }

  /**
   * State key: "{event_id}:{uid}:{badge_tid}" => ['time' => int, 'instructor' => int].
   *
   * Records students who attended but did not pass the in-class checkout,
   * so the class-checkout page and the post-event hub can treat them as
   * handled (they need to retake) instead of as forgotten.
   */
  public const NOT_PASSED_STATE_KEY = 'instructor_companion.class_checkout_not_passed';

  public static function notPassedKey(int $event_id, int $uid, int $badge_tid): string {
    return $event_id . ':' . $uid . ':' . $badge_tid;
  }

  protected function getNotPassed(int $event_id, int $uid, int $badge_tid): ?array {
    $map = (array) \Drupal::state()->get(self::NOT_PASSED_STATE_KEY, []);
    $entry = $map[self::notPassedKey($event_id, $uid, $badge_tid)] ?? NULL;
    return is_array($entry) ? $entry : NULL;
  }

  protected function setNotPassed(int $event_id, int $uid, int $badge_tid, bool $failed): void {
    $state = \Drupal::state();
    $map = (array) $state->get(self::NOT_PASSED_STATE_KEY, []);
    $key = self::notPassedKey($event_id, $uid, $badge_tid);
    if ($failed) {
      $map[$key] = ['time' => \Drupal::time()->getRequestTime(), 'instructor' => (int) $this->currentUser()->id()];
    }
    else {
      unset($map[$key]);
    }
    $state->set(self::NOT_PASSED_STATE_KEY, $map);
  }

  /**
   * Instructor attests the student attended but did NOT pass the checkout.
   *
   * Rare but real: attendance is recorded, the badge is left unissued (a
   * class stamp from an earlier mis-click is cleared), and the student is
   * emailed that they need to retake the class to earn the badge.
   */
  public function markNotPassed(int $event_id, int $user_id, int $badge_tid): RedirectResponse {
    $event = $this->loadEvent($event_id);
    $badge_term = $this->entityTypeManager()->getStorage('taxonomy_term')->load($badge_tid);
    $student = $this->entityTypeManager()->getStorage('user')->load($user_id);

    if (!$event || !$badge_term instanceof TermInterface || !$student) {
      throw new NotFoundHttpException();
    }
    if (!in_array($badge_tid, $this->getEventBadgeTids($event), TRUE)) {
      throw new AccessDeniedHttpException('Badge not associated with this class.');
    }
    if (!in_array($user_id, array_keys($this->getParticipantUids($event_id)), TRUE)) {
      throw new AccessDeniedHttpException('User is not a counted participant on this class.');
    }

    $messenger = $this->messenger();
    $student_name = $student->getDisplayName();
    $badge_name = $badge_term->getName();

    $this->markParticipantAttended($event_id, $user_id);

    $badge_request = $this->loadExistingBadgeRequest($user_id, $badge_tid);
    $status = $badge_request ? strtolower(trim((string) ($badge_request->get('field_badge_status')->value ?? ''))) : '';
    if ($badge_request && $status === 'active') {
      $messenger->addError($this->t('@student already holds an active @badge badge, so nothing was changed. If it should be revoked, contact staff to suspend it.', [
        '@student' => $student_name,
        '@badge' => $badge_name,
      ]));
      return new RedirectResponse(Url::fromRoute('instructor_companion.class_checkout', ['event_id' => $event_id])->toString());
    }
    if ($badge_request && !$badge_request->get('field_class_completed_date')->isEmpty()) {
      // Undo an earlier "complete" click for this student.
      $badge_request->set('field_class_completed_date', NULL);
      $badge_request->setNewRevision(TRUE);
      $badge_request->setRevisionUserId((int) $this->currentUser()->id());
      $badge_request->setRevisionLogMessage('Class checkout NOT passed at event #' . $event_id . '; class-completed stamp cleared.');
      $badge_request->save();
    }

    $this->setNotPassed($event_id, $user_id, $badge_tid, TRUE);
    $this->sendNotPassedEmail($student, $badge_term, $event);

    \Drupal::logger('instructor_companion')->notice(
      'Class checkout NOT passed for uid @uid badge @badge (tid @tid) at event @event by instructor @inst.',
      [
        '@uid' => $user_id,
        '@badge' => $badge_name,
        '@tid' => $badge_tid,
        '@event' => $event_id,
        '@inst' => $this->currentUser()->id(),
      ]
    );
    $messenger->addWarning($this->t('@student: recorded as attended but not passed for @badge. No badge issued; they were emailed that they need to retake the class.', [
      '@student' => $student_name,
      '@badge' => $badge_name,
    ]));

    return new RedirectResponse(Url::fromRoute('instructor_companion.class_checkout', ['event_id' => $event_id])->toString());
  }

  protected function sendNotPassedEmail($student, TermInterface $badge_term, $event): void {
    $mail = $student->getEmail();
    if (!$mail) {
      return;
    }
    try {
      $badge_url = $badge_term->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      $badge_url = '';
    }
    \Drupal::service('plugin.manager.mail')->mail(
      'instructor_companion',
      'class_checkout_not_passed',
      $mail,
      $student->getPreferredLangcode(),
      [
        'badge_label' => $badge_term->getName(),
        'badge_url' => $badge_url,
        'event_label' => $event->label(),
        'student_name' => $student->getDisplayName(),
      ]
    );
  }

  public const OUTCOME_ACTIVATE = 'activate';
  public const OUTCOME_AWAIT_QUIZ = 'await_quiz';
  public const OUTCOME_ALREADY_ACTIVE = 'already_active';
  public const OUTCOME_LEAVE = 'leave';

  /**
   * Decides what a class checkout does to the badge_request. Pure; unit tested.
   *
   * @param string $current_status
   *   The badge_request's field_badge_status ('' counts as pending).
   * @param bool $has_quiz
   *   Whether the badge references a quiz at all.
   * @param bool $quiz_passed
   *   Whether the student has a 100% pass on that quiz.
   */
  public static function resolveOutcome(string $current_status, bool $has_quiz, bool $quiz_passed): string {
    $status = strtolower(trim($current_status));
    if ($status === 'active') {
      return self::OUTCOME_ALREADY_ACTIVE;
    }
    if ($status !== '' && $status !== 'pending') {
      return self::OUTCOME_LEAVE;
    }
    if ($has_quiz && !$quiz_passed) {
      return self::OUTCOME_AWAIT_QUIZ;
    }
    return self::OUTCOME_ACTIVATE;
  }

  /**
   * Flips the student's CiviCRM participant row to Attended (idempotent).
   *
   * Best effort: a failure here must not block the badge, so it only logs.
   */
  protected function markParticipantAttended(int $event_id, int $user_id): void {
    if (!\Drupal::hasService('instructor_companion.attendance_manager')) {
      return;
    }
    try {
      $contact_id = (int) \Drupal::database()->select('civicrm_uf_match', 'm')
        ->fields('m', ['contact_id'])
        ->condition('m.uf_id', $user_id)
        ->execute()
        ->fetchField();
      if ($contact_id > 0) {
        \Drupal::service('instructor_companion.attendance_manager')->addWalkIn($event_id, $contact_id);
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('instructor_companion')->warning('Class checkout could not mark uid @uid attended on event @event: @m', [
        '@uid' => $user_id,
        '@event' => $event_id,
        '@m' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Custom access: instructor of the event, or admin.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $event_id = (int) $route_match->getParameter('event_id');
    if ($event_id <= 0) {
      return AccessResult::forbidden();
    }
    if ($account->hasPermission('administer civicrm_event entities')
      || $account->hasPermission('create civicrm_event entities')) {
      return AccessResult::allowed();
    }
    $is_instructor = (bool) \Drupal::database()->select('civicrm_event__field_civi_event_instructor', 'i')
      ->fields('i', ['entity_id'])
      ->condition('i.entity_id', $event_id)
      ->condition('i.field_civi_event_instructor_target_id', $account->id())
      ->execute()
      ->fetchField();
    return $is_instructor ? AccessResult::allowed() : AccessResult::forbidden();
  }

  protected function buildParticipantRow(int $event_id, int $uid, string $participant_name, TermInterface $badge_term): array {
    $badge_request = $this->loadExistingBadgeRequest($uid, (int) $badge_term->id());

    $quiz_id = $badge_term->hasField('field_badge_quiz_reference')
      ? (int) ($badge_term->get('field_badge_quiz_reference')->target_id ?? 0)
      : 0;
    $quiz_passed = $quiz_id > 0 && $this->userHasPassedQuiz($uid, $quiz_id);

    $class_done = $badge_request && !$badge_request->get('field_class_completed_date')->isEmpty();
    $badge_status = $badge_request ? (string) $badge_request->get('field_badge_status')->value : '—';

    $not_passed = $class_done ? NULL : $this->getNotPassed($event_id, $uid, (int) $badge_term->id());
    $is_active = strtolower($badge_status) === 'active';

    if ($class_done) {
      $date_str = $badge_request->get('field_class_completed_date')->value;
      $action_label = $is_active ? $this->t('Re-run checkout') : $this->t('Re-run checkout (activates if quiz passed)');
    }
    elseif ($not_passed) {
      $action_label = $this->t('Passed after all — issue badge');
    }
    elseif ($is_active) {
      $action_label = $this->t('Record class date (badge already active)');
    }
    else {
      $action_label = $this->t('Complete class & issue badge');
    }

    $route_params = ['event_id' => $event_id, 'user_id' => $uid, 'badge_tid' => $badge_term->id()];
    $mark_url = Url::fromRoute('instructor_companion.class_checkout_mark', $route_params, [
      'query' => ['token' => \Drupal::csrfToken()->get('instructor/class-checkout/' . $event_id . '/mark/' . $uid . '/' . $badge_term->id())],
    ]);
    $fail_url = Url::fromRoute('instructor_companion.class_checkout_fail', $route_params, [
      'query' => ['token' => \Drupal::csrfToken()->get('instructor/class-checkout/' . $event_id . '/fail/' . $uid . '/' . $badge_term->id())],
    ]);

    $actions = [
      'mark' => [
        '#type' => 'link',
        '#title' => $action_label,
        '#url' => $mark_url,
        '#attributes' => ['class' => ['button', 'button--small', 'button--primary']],
      ],
    ];
    if (!$is_active && !$not_passed) {
      $actions['fail'] = [
        '#type' => 'link',
        '#title' => $this->t('Attended, did not pass'),
        '#url' => $fail_url,
        '#attributes' => ['class' => ['button', 'button--small', 'button--danger']],
      ];
    }

    if ($class_done) {
      $class_cell = $date_str ?? $this->t('✓');
    }
    elseif ($not_passed) {
      $class_cell = $this->t('Did not pass (@date) — must retake', [
        '@date' => \Drupal::service('date.formatter')->format((int) ($not_passed['time'] ?? 0), 'custom', 'M j'),
      ]);
    }
    else {
      $class_cell = $this->t('—');
    }

    return [
      'student' => $participant_name,
      'quiz' => $quiz_passed ? $this->t('✓') : $this->t('—'),
      'class' => $class_cell,
      'status' => ucfirst($badge_status),
      'action' => ['data' => $actions],
    ];
  }

  protected function loadEvent(int $event_id) {
    if ($event_id <= 0) {
      return NULL;
    }
    $storage = $this->entityTypeManager()->getStorage('civicrm_event');
    return $storage->load($event_id);
  }

  protected function getEventBadgeTids($event): array {
    if (!$event || !$event->hasField('field_civi_event_badges')) {
      return [];
    }
    $tids = [];
    foreach ($event->get('field_civi_event_badges') as $item) {
      $tids[] = (int) $item->target_id;
    }
    return array_filter($tids);
  }

  /**
   * Returns [uid => display_name] for counted, non-test participants.
   */
  protected function getParticipantUids(int $event_id): array {
    $db = \Drupal::database();
    $q = $db->select('civicrm_participant', 'p');
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
    $users = $this->entityTypeManager()->getStorage('user')->loadMultiple($uids);
    $out = [];
    foreach ($users as $uid => $user) {
      $out[(int) $uid] = $user->getDisplayName();
    }
    return $out;
  }

  protected function loadExistingBadgeRequest(int $user_id, int $badge_tid): ?NodeInterface {
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $user_id)
      ->condition('field_badge_requested.target_id', $badge_tid)
      ->condition('field_badge_status.value', ['duplicate', 'Rejected', 'rejected'], 'NOT IN')
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$nids) {
      return NULL;
    }
    $node = Node::load((int) reset($nids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  protected function userHasPassedQuiz(int $member_uid, int $quiz_id): bool {
    if (!$this->entityTypeManager()->hasDefinition('quiz_result')) {
      return FALSE;
    }
    $ids = $this->entityTypeManager()->getStorage('quiz_result')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $member_uid)
      ->condition('qid', $quiz_id)
      ->condition('score', 100)
      ->range(0, 1)
      ->execute();
    return !empty($ids);
  }

  protected function sendQuizReminderEmail($student, TermInterface $badge_term, int $quiz_id, $event): void {
    $mail = $student->getEmail();
    if (!$mail) {
      return;
    }
    $quiz_url = Url::fromRoute('entity.node.canonical', ['node' => $quiz_id], ['absolute' => TRUE])->toString();
    $params = [
      'badge_label' => $badge_term->getName(),
      'quiz_url' => $quiz_url,
      'event_label' => $event->label(),
      'student_name' => $student->getDisplayName(),
    ];
    \Drupal::service('plugin.manager.mail')->mail(
      'instructor_companion',
      'class_checkout_quiz_reminder',
      $mail,
      $student->getPreferredLangcode(),
      $params
    );
  }

}
