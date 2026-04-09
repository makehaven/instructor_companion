<?php

namespace Drupal\instructor_companion\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Confirmation form for denying an instructor session proposal.
 *
 * Sends a reason email to the instructor, then deletes the draft event.
 *
 * Route: /admin/structure/proposals/{event_id}/deny
 */
class ProposalDenyForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'instructor_companion_proposal_deny';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $event_id = 0): array {
    $event = \Drupal::entityTypeManager()->getStorage('civicrm_event')->load($event_id);

    if (!$event || $event->get('is_active')->value) {
      $this->messenger()->addError($this->t('Proposal not found or already active.'));
      return $form;
    }

    $form_state->set('event_id', $event_id);

    $instructor_entities = $event->get('field_civi_event_instructor')->referencedEntities();
    $instructor = !empty($instructor_entities) ? reset($instructor_entities) : NULL;
    $instructor_name = $instructor ? $instructor->getDisplayName() : $this->t('the instructor');

    $form['intro'] = [
      '#markup' => '<p>' . $this->t(
        'You are about to deny the proposal <strong>"@title"</strong> submitted by @instructor.
         The proposal record will be removed. An email will be sent to the instructor with your reason.',
        ['@title' => $event->label(), '@instructor' => $instructor_name]
      ) . '</p>',
    ];

    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason for denial (sent to instructor)'),
      '#description' => $this->t('Be specific and constructive. The instructor will receive this text in their notification email.'),
      '#rows' => 5,
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Confirm Denial'),
        '#attributes' => ['style' => 'background:#e74c3c;border-color:#c0392b'],
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('instructor_companion.proposal_review', ['event_id' => $event_id]),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = $form_state->get('event_id');
    $reason   = $form_state->getValue('reason');

    $event = \Drupal::entityTypeManager()->getStorage('civicrm_event')->load($event_id);
    if (!$event) {
      $this->messenger()->addError($this->t('Proposal not found.'));
      $form_state->setRedirect('instructor_companion.proposals_list');
      return;
    }

    $event_title = $event->label();

    // Email the instructor.
    $instructor_entities = $event->get('field_civi_event_instructor')->referencedEntities();
    if (!empty($instructor_entities)) {
      $instructor = reset($instructor_entities);
      $params = [
        'user_name'   => $instructor->getDisplayName(),
        'event_title' => $event_title,
        'reason'      => $reason,
      ];
      \Drupal::service('plugin.manager.mail')->mail(
        'instructor_companion',
        'proposal_denied',
        $instructor->getEmail(),
        $instructor->getPreferredLangcode(),
        $params,
        NULL,
        TRUE
      );
    }

    // Remove the draft proposal.
    $event->delete();

    $this->messenger()->addStatus($this->t('Proposal "@title" denied. The instructor has been notified.', ['@title' => $event_title]));
    $form_state->setRedirect('instructor_companion.proposals_list');
  }

}
