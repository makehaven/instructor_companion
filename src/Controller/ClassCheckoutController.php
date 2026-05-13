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
 * Mirrors the facilitator checkout pattern: instructor attests that a student
 * completed the in-person class portion. Creates or stamps a badge_request
 * with field_class_completed_date; final activation still flows through the
 * existing /badge-request/{node}/approve route.
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
        '#markup' => '<p>' . $this->t('Mark each student as having completed the in-person portion. They still need to pass the video + quiz at 100% before the badge becomes usable. The final activation is done by the badge issuer.') . '</p>',
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
    $messenger->addStatus($this->t('@student: class checkout recorded for @badge.', [
      '@student' => $student->getDisplayName(),
      '@badge' => $badge_term->getName(),
    ]));

    // If they haven't passed the quiz yet, nudge them by email.
    $quiz_id = $badge_term->hasField('field_badge_quiz_reference')
      ? (int) ($badge_term->get('field_badge_quiz_reference')->target_id ?? 0)
      : 0;
    if ($quiz_id > 0 && !$this->userHasPassedQuiz($user_id, $quiz_id)) {
      $this->sendQuizReminderEmail($student, $badge_term, $quiz_id, $event);
      $messenger->addWarning($this->t('@student has not passed the quiz yet. A reminder email was sent.', [
        '@student' => $student->getDisplayName(),
      ]));
    }

    if ($is_new) {
      \Drupal::logger('instructor_companion')->notice(
        'Class checkout created badge_request @nid for uid @uid badge @badge (tid @tid) from event @event by instructor @inst.',
        [
          '@nid' => $badge_request->id(),
          '@uid' => $user_id,
          '@badge' => $badge_term->getName(),
          '@tid' => $badge_tid,
          '@event' => $event_id,
          '@inst' => $this->currentUser()->id(),
        ]
      );
    }

    return new RedirectResponse(Url::fromRoute('instructor_companion.class_checkout', ['event_id' => $event_id])->toString());
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

    if ($class_done) {
      $date_str = $badge_request->get('field_class_completed_date')->value;
      $action_label = $this->t('Re-stamp class date');
    }
    else {
      $action_label = $this->t('Mark class complete');
    }

    $token_key = 'instructor/class-checkout/' . $event_id . '/mark/' . $uid . '/' . $badge_term->id();
    $mark_url = Url::fromRoute('instructor_companion.class_checkout_mark', [
      'event_id' => $event_id,
      'user_id' => $uid,
      'badge_tid' => $badge_term->id(),
    ], ['query' => ['token' => \Drupal::csrfToken()->get($token_key)]]);

    return [
      'student' => $participant_name,
      'quiz' => $quiz_passed ? $this->t('✓') : $this->t('—'),
      'class' => $class_done ? ($date_str ?? $this->t('✓')) : $this->t('—'),
      'status' => ucfirst($badge_status),
      'action' => [
        'data' => [
          '#type' => 'link',
          '#title' => $action_label,
          '#url' => $mark_url,
          '#attributes' => ['class' => ['button', 'button--small']],
        ],
      ],
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
