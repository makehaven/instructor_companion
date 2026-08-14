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
        'You are about to close the proposal <strong>"@title"</strong> submitted by @instructor. The proposal record will be removed.',
        ['@title' => $event->label(), '@instructor' => $instructor_name]
      ) . '</p>',
    ];

    // Much of the queue is old proposals that were settled by conversation
    // months ago and never closed out. Emailing those people now would be
    // confusing at best, so closing an item and telling someone about it are
    // separate choices.
    $form['notify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email the instructor a reason'),
      '#default_value' => TRUE,
      '#description' => $this->t('Leave this unticked to close the proposal quietly — nothing is sent. Use that for items already handled by conversation, so clearing the backlog does not reopen settled conversations.'),
    ];

    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason'),
      '#description' => $this->t('Sent to the instructor when the box above is ticked. Kept in the log either way.'),
      '#rows' => 5,
      '#states' => [
        'visible' => [':input[name="notify"]' => ['checked' => TRUE]],
        'required' => [':input[name="notify"]' => ['checked' => TRUE]],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Close proposal'),
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
      $form_state->setRedirectUrl(Url::fromUserInput('/admin/structure/proposals'));
      return;
    }

    $event_title = $event->label();
    $notify = (bool) $form_state->getValue('notify');

    // Email the instructor, unless staff chose to close this quietly.
    $instructor_entities = $event->get('field_civi_event_instructor')->referencedEntities();
    if ($notify && !empty($instructor_entities)) {
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

    \Drupal::logger('instructor_companion')->notice('Proposal "@title" closed by @user. Instructor notified: @notified. Reason: @reason', [
      '@title' => $event_title,
      '@user' => \Drupal::currentUser()->getAccountName(),
      '@notified' => $notify ? 'yes' : 'no',
      '@reason' => $reason ?: '(none given)',
    ]);

    $this->messenger()->addStatus($notify
      ? $this->t('Proposal "@title" closed. The instructor has been notified.', ['@title' => $event_title])
      : $this->t('Proposal "@title" closed quietly. No email was sent.', ['@title' => $event_title]));
    $form_state->setRedirectUrl(Url::fromUserInput('/admin/structure/proposals'));
  }

}
