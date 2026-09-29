<?php

namespace Drupal\instructor_companion\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Closes, without email, every draft session proposal whose date has passed.
 *
 * These were settled in conversation long ago (Ashley, 2026-09-29) and only
 * cluttered Needs attention. Closing one is what ProposalDenyForm does with
 * "notify" unticked: the draft event is deleted and the decision is logged.
 * Held proposals (approved, waiting on onboarding) are never touched.
 *
 * Route: /admin/education/proposals/close-expired
 */
class ExpiredProposalsCloseForm extends ConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'instructor_companion_expired_proposals_close';
  }

  /**
   * Draft proposals (not held) whose start date has passed.
   *
   * @return array<int, string>
   *   Titles with date, keyed by event id.
   */
  public static function expiredProposals(): array {
    $db = \Drupal::database();
    if (!$db->schema()->tableExists('civicrm_event__field_parent_course')) {
      return [];
    }
    $q = $db->select('civicrm_event', 'e');
    $q->join('civicrm_event__field_parent_course', 'pc', 'pc.entity_id = e.id');
    $q->fields('e', ['id', 'title', 'start_date'])
      ->condition('e.is_active', 0)
      ->condition('e.is_template', 0)
      ->condition('e.start_date', date('Y-m-d H:i:s'), '<')
      ->orderBy('e.start_date');
    $holds = \Drupal::service('instructor_companion.proposal_hold_manager')->allHolds();
    $out = [];
    foreach ($q->execute() as $r) {
      if (isset($holds[$r->id])) {
        continue;
      }
      $out[(int) $r->id] = $r->title . ' (' . date('M j, Y', strtotime($r->start_date)) . ')';
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Close the session proposals that are past their date?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $items = self::expiredProposals();
    if (!$items) {
      return $this->t('There are none.');
    }
    $list = [
      '#theme' => 'item_list',
      '#items' => array_values($items),
    ];
    return $this->t('These draft sessions can no longer run on the date proposed. Closing removes each draft and sends nothing to the instructor, the same as "close quietly" on one proposal.')
      . \Drupal::service('renderer')->renderInIsolation($list);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Close them quietly');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('instructor_companion.education_console');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $items = self::expiredProposals();
    $storage = \Drupal::entityTypeManager()->getStorage('civicrm_event');
    $closed = 0;
    foreach ($storage->loadMultiple(array_keys($items)) as $event) {
      // Re-check: only drafts are ever removed here.
      if ($event->get('is_active')->value) {
        continue;
      }
      $event->delete();
      $closed++;
    }
    \Drupal::logger('instructor_companion')->notice('@n past-date proposal(s) closed quietly by @user: @titles', [
      '@n' => $closed,
      '@user' => \Drupal::currentUser()->getAccountName(),
      '@titles' => implode('; ', $items),
    ]);
    $this->messenger()->addStatus($this->formatPlural($closed, 'Closed 1 proposal. No email was sent.', 'Closed @count proposals. No email was sent.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
