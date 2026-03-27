<?php

namespace Drupal\instructor_companion\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Provides a form to select a new parent course for an event.
 */
class EventReassignForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'instructor_companion_event_reassign_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $event_id = NULL) {
    $database = \Drupal::database();
    $event_title = $database->select('civicrm_event', 'e')
      ->fields('e', ['title'])
      ->condition('id', $event_id)
      ->execute()
      ->fetchField();

    if (!$event_title) {
      $this->messenger()->addError($this->t('Invalid event ID.'));
      return ['#markup' => $this->t('Invalid event ID.')];
    }

    $form['event_id'] = [
      '#type' => 'value',
      '#value' => $event_id,
    ];

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Reassigning event: <strong>"@title"</strong> (#@id)', ['@title' => $event_title, '@id' => $event_id]) . '</p>',
    ];

    $form['target_nid'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'node',
      '#selection_settings' => [
        'target_bundles' => ['course'],
      ],
      '#title' => $this->t('Select the New Parent Course'),
      '#required' => TRUE,
      '#description' => $this->t('Search for the course this event instance actually belongs to.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Move Event'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('instructor_companion.event_reassign_execute', [
      'event_id' => $form_state->getValue('event_id'),
      'target_nid' => $form_state->getValue('target_nid'),
    ]);
  }

}
