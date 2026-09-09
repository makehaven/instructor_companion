<?php

namespace Drupal\instructor_companion\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
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

    $default_contact_uid = (int) $config->get('proposal_staff_contact_uid');
    $form['proposal_staff_contact'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('Default staff contact for member proposals'),
      '#description' => $this->t('The proposal form hides "Primary Staff Contact For Event" from proposing members and fills in this user (staff can change it during review). If left empty, the account matching the notification email is used; if neither resolves, proposers see the field with guidance.'),
      '#default_value' => $default_contact_uid ? User::load($default_contact_uid) : NULL,
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

    $form['invite'] = [
      '#type' => 'details',
      '#title' => $this->t('Instructor agreement invite email'),
      '#description' => $this->t('Sent when staff use <a href=":url">Invite an instructor</a>. The link signs the person in and opens the agreement; it is valid for 14 days. Edit copy here without a code deploy.', [':url' => Url::fromRoute('instructor_companion.invite_form')->toString()]),
      '#open' => TRUE,
    ];

    $form['invite']['invite_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $config->get('invite_subject'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['invite']['invite_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('invite_body'),
      '#rows' => 16,
      '#required' => TRUE,
      '#description' => $this->t('Plain text. <code>[invite:link]</code> is the personal link and must appear; <code>[invite:sender]</code> is the staff member sending it; <code>[invite:note]</code> is their optional note (blank when none). <code>[user:*]</code> and <code>[site:*]</code> tokens also work.'),
    ];

    $form['interest_approval'] = [
      '#type' => 'details',
      '#title' => $this->t('Instructor Interest approval email'),
      '#description' => $this->t('Sent to the submitter when staff click <strong>Approve</strong> on a webform_14366 submission in the Instructor Interest queue. Used to walk prospective instructors through the orientation → agreement → propose-a-session funnel without requiring them to have a Drupal account yet. Edit copy here without a code deploy.'),
      '#open' => TRUE,
    ];

    $form['interest_approval']['interest_approval_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send the Instructor Interest approval email'),
      '#default_value' => (bool) $config->get('interest_approval_enabled'),
    ];

    $form['interest_approval']['interest_approval_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $config->get('interest_approval_subject'),
      '#maxlength' => 255,
      '#states' => [
        'required' => [':input[name="interest_approval_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['interest_approval']['interest_approval_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('interest_approval_body'),
      '#rows' => 18,
      '#description' => $this->t('Plain text. Tokens like <code>[submission:name]</code>, <code>[submission:email]</code>, and <code>[site:url]</code> are replaced before sending. The submitter may not have a Drupal account yet, so avoid <code>[user:*]</code> tokens here.'),
      '#states' => [
        'required' => [':input[name="interest_approval_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $types = \Drupal\instructor_companion\Service\PostEventStatusService::eventTypeOptions();
    if ($types) {
      $form['closeout'] = [
        '#type' => 'details',
        '#title' => $this->t('Post-class wrap-up'),
        '#description' => $this->t('Which kinds of event owe attendance, badges, feedback and a payment request afterwards. These are the ones listed under "Classes to close out" on the Education console and the ones whose instructor gets the automatic post-class reminder.'),
        '#open' => TRUE,
      ];
      $form['closeout']['attendance_prompt_enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Ask for attendance at the start of the class'),
        '#default_value' => (bool) ($config->get('attendance_prompt_enabled') ?? TRUE),
        '#description' => $this->t('Emails the instructor shortly after the class begins with a direct link to the attendance list. This is the prompt that decides whether attendance data is any good — asked two days later, an instructor is guessing, and a walk-in nobody registered has long gone.'),
      ];

      $form['closeout']['attendance_prompt_offset_minutes'] = [
        '#type' => 'number',
        '#title' => $this->t('Minutes after the start time'),
        '#min' => 1,
        '#max' => 240,
        '#default_value' => $config->get('attendance_prompt_offset_minutes') ?: \Drupal\instructor_companion\Service\AttendancePromptService::DEFAULT_OFFSET_MINUTES,
        '#description' => $this->t('Not zero — at the bell the instructor is greeting people and setting up.'),
        '#states' => [
          'visible' => [':input[name="attendance_prompt_enabled"]' => ['checked' => TRUE]],
        ],
      ];

      $form['closeout']['closeout_event_types'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Event types that owe wrap-up'),
        '#options' => $types,
        '#default_value' => \Drupal\instructor_companion\Service\PostEventStatusService::closeoutEventTypes(),
        '#description' => $this->t('Leave Meetup unticked — a meetup is hosted, not taught, so its host owes no badges or payment. Leave Program unticked too: a program is a multi-week cohort and its first session is not the end of anything. Unticking a type stops both the console listing and the reminder email.'),
      ];
    }

    $form['orientation_step_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require the orientation video and quiz'),
      '#default_value' => (bool) ($config->get('orientation_step_enabled') ?? FALSE),
      '#description' => $this->t('Off while there is no orientation video to watch. Switching this on puts the video and quiz back into onboarding: proposers are told to complete them, staff see the pass/fail line on the review page, and an approved session stays unpublished until the badge is earned. Leave it off until there is a real video.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if (!str_contains((string) $form_state->getValue('invite_body'), '[invite:link]')) {
      $form_state->setErrorByName('invite_body', $this->t('The invite body must contain [invite:link] — without it the email has no way in.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('instructor_companion.settings')
      ->set('notification_email', $form_state->getValue('notification_email'))
      ->set('proposal_staff_contact_uid', (int) $form_state->getValue('proposal_staff_contact'))
      ->set('orientation_step_enabled', (bool) $form_state->getValue('orientation_step_enabled'))
      ->set('attendance_prompt_enabled', $form_state->hasValue('attendance_prompt_enabled')
        ? (bool) $form_state->getValue('attendance_prompt_enabled')
        : $this->config('instructor_companion.settings')->get('attendance_prompt_enabled'))
      ->set('attendance_prompt_offset_minutes', $form_state->hasValue('attendance_prompt_offset_minutes')
        ? (int) $form_state->getValue('attendance_prompt_offset_minutes')
        : $this->config('instructor_companion.settings')->get('attendance_prompt_offset_minutes'))
      ->set('closeout_event_types', $form_state->hasValue('closeout_event_types')
        ? array_values(array_map('intval', array_filter((array) $form_state->getValue('closeout_event_types'))))
        : $this->config('instructor_companion.settings')->get('closeout_event_types'))
      ->set('emergency_procedures_url', $form_state->getValue('emergency_procedures_url'))
      ->set('instructor_handbook_url', $form_state->getValue('instructor_handbook_url'))
      ->set('request_reimbursement_url', $form_state->getValue('request_reimbursement_url'))
      ->set('payment_status_url', $form_state->getValue('payment_status_url'))
      ->set('log_hours_url', $form_state->getValue('log_hours_url'))
      ->set('instructor_welcome_enabled', (bool) $form_state->getValue('instructor_welcome_enabled'))
      ->set('instructor_welcome_subject', $form_state->getValue('instructor_welcome_subject'))
      ->set('instructor_welcome_body', $form_state->getValue('instructor_welcome_body'))
      ->set('invite_subject', $form_state->getValue('invite_subject'))
      ->set('invite_body', $form_state->getValue('invite_body'))
      ->set('interest_approval_enabled', (bool) $form_state->getValue('interest_approval_enabled'))
      ->set('interest_approval_subject', $form_state->getValue('interest_approval_subject'))
      ->set('interest_approval_body', $form_state->getValue('interest_approval_body'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
