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
      ->save();

    parent::submitForm($form, $form_state);
  }

}
