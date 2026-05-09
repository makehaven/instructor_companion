<?php

namespace Drupal\instructor_companion\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Instructor Companion settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'instructor_companion_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['instructor_companion.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('instructor_companion.settings');

    $form['notification_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Instructor notification email'),
      '#description' => $this->t('Email address to notify when a new instructor application is submitted.'),
      '#default_value' => $config->get('notification_email'),
      '#required' => TRUE,
    ];

    $form['toolkit_links'] = [
      '#type' => 'details',
      '#title' => $this->t('Instructor toolkit links'),
      '#open' => TRUE,
    ];

    $form['toolkit_links']['emergency_procedures_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Emergency procedures link'),
      '#description' => $this->t('Use a full URL (https://...) or an internal path (e.g. /admin).'),
      '#default_value' => $config->get('emergency_procedures_url'),
    ];

    $form['toolkit_links']['instructor_handbook_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Instructor handbook link'),
      '#description' => $this->t('Use a full URL (https://...) or an internal path (e.g. /admin).'),
      '#default_value' => $config->get('instructor_handbook_url'),
    ];

    $form['toolkit_links']['request_reimbursement_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Request reimbursement link'),
      '#description' => $this->t('Use a full URL (https://...) or an internal path (e.g. /admin).'),
      '#default_value' => $config->get('request_reimbursement_url'),
    ];

    $form['toolkit_links']['payment_status_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Payment status link'),
      '#description' => $this->t('Use a full URL (https://...) or an internal path (e.g. /admin).'),
      '#default_value' => $config->get('payment_status_url'),
    ];

    $form['toolkit_links']['log_hours_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Log hours link'),
      '#description' => $this->t('Use a full URL (https://...) or an internal path (e.g. /admin).'),
      '#default_value' => $config->get('log_hours_url'),
    ];

    $form['instructor_welcome'] = [
      '#type' => 'details',
      '#title' => $this->t('Instructor welcome email'),
      '#description' => $this->t('Sent to a new applicant after they register via the <code>?profile=instructor</code> path. Edit copy here without a code deploy.'),
      '#open' => TRUE,
    ];

    $form['instructor_welcome']['instructor_welcome_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send the instructor welcome email'),
      '#default_value' => (bool) $config->get('instructor_welcome_enabled'),
    ];

    $form['instructor_welcome']['instructor_welcome_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $config->get('instructor_welcome_subject'),
      '#maxlength' => 255,
      '#states' => [
        'required' => [':input[name="instructor_welcome_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['instructor_welcome']['instructor_welcome_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('instructor_welcome_body'),
      '#rows' => 14,
      '#description' => $this->t('Plain text. Tokens like <code>[user:field_first_name]</code> and <code>[site:url]</code> are replaced before sending.'),
      '#states' => [
        'required' => [':input[name="instructor_welcome_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    if (\Drupal::moduleHandler()->moduleExists('token')) {
      $form['instructor_welcome']['token_help'] = [
        '#theme' => 'token_tree_link',
        '#token_types' => ['user', 'site'],
        '#show_restricted' => FALSE,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('instructor_companion.settings')
      ->set('notification_email', $form_state->getValue('notification_email'))
      ->set('emergency_procedures_url', $form_state->getValue('emergency_procedures_url'))
      ->set('instructor_handbook_url', $form_state->getValue('instructor_handbook_url'))
      ->set('request_reimbursement_url', $form_state->getValue('request_reimbursement_url'))
      ->set('payment_status_url', $form_state->getValue('payment_status_url'))
      ->set('log_hours_url', $form_state->getValue('log_hours_url'))
      ->set('instructor_welcome_enabled', (bool) $form_state->getValue('instructor_welcome_enabled'))
      ->set('instructor_welcome_subject', $form_state->getValue('instructor_welcome_subject'))
      ->set('instructor_welcome_body', $form_state->getValue('instructor_welcome_body'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
