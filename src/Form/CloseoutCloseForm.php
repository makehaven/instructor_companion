<?php

namespace Drupal\instructor_companion\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Staff take one class off the close-out list by hand.
 *
 * For wrap-up done outside the site (paid another way, feedback given in
 * person). Records who, when and why; stops the automatic instructor
 * reminder. Students still owed a badge stay on the badges-owed list.
 *
 * Route: /admin/education/closeout/{event_id}/close
 */
class CloseoutCloseForm extends ConfirmFormBase {

  /**
   * The CiviCRM event id.
   */
  protected int $eventId = 0;

  /**
   * The event title, for the question.
   */
  protected string $eventTitle = '';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'instructor_companion_closeout_close';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $event_id = 0): array {
    $this->eventId = $event_id;
    $title = \Drupal::database()->select('civicrm_event', 'e')
      ->fields('e', ['title'])
      ->condition('e.id', $event_id)
      ->execute()
      ->fetchField();
    $this->eventTitle = (string) ($title ?: $this->t('this class'));

    $form = parent::buildForm($form, $form_state);
    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Why (optional)'),
      '#description' => $this->t('For example: "Paid by invoice", "Feedback given in person".'),
      '#maxlength' => 255,
      '#weight' => -5,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Mark "@title" closed out?', ['@title' => $this->eventTitle]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The class leaves the close-out list and the instructor gets no more automatic reminders. Nothing is sent. Students still owed a badge stay on the "Badges owed after class" list.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Mark closed out');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('instructor_companion.education_console', [], ['fragment' => 'closeout']);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $reason = trim((string) $form_state->getValue('reason'));
    \Drupal::service('instructor_companion.post_event_status')
      ->markClosedByStaff($this->eventId, (int) $this->currentUser()->id(), $reason);
    \Drupal::logger('instructor_companion')->notice('Close-out for event @e marked done by @user. Reason: @reason', [
      '@e' => $this->eventId,
      '@user' => $this->currentUser()->getAccountName(),
      '@reason' => $reason ?: '(none given)',
    ]);
    $this->messenger()->addStatus($this->t('"@title" is closed out.', ['@title' => $this->eventTitle]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
