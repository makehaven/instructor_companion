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

    $form['slack_channel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Slack channel for education notices'),
      '#description' => $this->t('Where new workshop proposals, instructor-interest submissions and post-class shop reports are posted, via the shared Slack Connector webhook. Use the channel name, e.g. <code>#education-team</code>; the channel must exist. Leave empty to post to the webhook\'s default channel.'),
      '#default_value' => $config->get('slack_channel'),
      '#maxlength' => 80,
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
      '#title' => $this->t('Approving an Instructor Interest submission'),
      '#description' => $this->t('Marking a submission <strong>Approved</strong> in the Instructor Interest queue means "we have spoken to this person and they should teach". It creates their MakeHaven account if they do not have one and sends them the instructor agreement invite above — the same email as the <em>Invite an instructor</em> button. It used to send a next-steps email telling them to propose a session, which a non-member cannot do.'),
      '#open' => TRUE,
    ];

    $form['interest_approval']['interest_approval_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send the invite when a submission is approved'),
      '#default_value' => (bool) $config->get('interest_approval_enabled'),
      '#description' => $this->t('Off means approving only records the status; you would then invite them by hand.'),
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

      $form['closeout']['session_evaluation'] = [
        '#type' => 'details',
        '#title' => $this->t('Classes that meet more than once'),
        '#open' => FALSE,
        '#description' => $this->t('A class with a Sessions list on its event (stained glass over six Saturdays, rug tufting over two) is one event whose start date is the first meeting. Attendance is asked for at every session, and the wrap-up reminder, the console backlog and the attendee evaluation wait for the last one. CiviCRM\'s own "Thanks for attending" reminder can only follow the first date, so for these classes it is held and the evaluation below is sent from here instead.'),
      ];
      $form['closeout']['session_evaluation']['session_evaluation_enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Send the attendee evaluation after the last session'),
        '#default_value' => (bool) ($config->get('session_evaluation_enabled') ?? TRUE),
        '#description' => $this->t('Off means CiviCRM\'s reminder goes out after the first session as before, and nothing is held.'),
      ];
      $form['closeout']['session_evaluation']['session_evaluation_delay_hours'] = [
        '#type' => 'number',
        '#title' => $this->t('Hours after the last session'),
        '#min' => 1,
        '#max' => 168,
        '#default_value' => $config->get('session_evaluation_delay_hours') ?: \Drupal\instructor_companion\Service\SessionEvaluationService::DEFAULT_DELAY_HOURS,
        '#description' => $this->t('CiviCRM\'s reminders use 24.'),
      ];
      $form['closeout']['session_evaluation']['session_evaluation_subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('session_evaluation_subject') ?: 'Thank you for attending [event_title]!',
        '#maxlength' => 200,
      ];
      $form['closeout']['session_evaluation']['session_evaluation_body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('session_evaluation_body') ?: \Drupal\instructor_companion\Service\SessionEvaluationService::defaultBody(),
        '#rows' => 12,
        '#description' => $this->t('Plain text. Tokens: <code>[first_name]</code>, <code>[event_title]</code>, <code>[evaluation_url]</code> (must appear).'),
      ];
      $form['closeout']['session_evaluation']['session_evaluation_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Evaluation link'),
        '#default_value' => $config->get('session_evaluation_url') ?: \Drupal\instructor_companion\Service\SessionEvaluationService::DEFAULT_URL,
        '#description' => $this->t('Path or URL; <code>[event_type_id]</code> and <code>[event_id]</code> are replaced. Matches the link in CiviCRM reminder "Thanks for Attending!".'),
      ];

      $form['closeout']['closeout_event_types'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Event types that owe wrap-up'),
        '#options' => $types,
        '#default_value' => \Drupal\instructor_companion\Service\PostEventStatusService::closeoutEventTypes(),
        '#description' => $this->t('Leave Meetup unticked — a meetup is hosted, not taught, so its host owes no badges or payment. Leave Program unticked too: a program is a multi-week cohort and its first session is not the end of anything. Unticking a type stops the console listing, the post-class reminder, the at-start attendance email and the dashboard\'s "Happening now" prompt.'),
      ];
    }

    $followup_types = \Drupal\instructor_companion\Service\PostEventStatusService::eventTypeOptions();
    $form['followup'] = [
      '#type' => 'details',
      '#title' => $this->t('After the class: the follow-up loop'),
      '#open' => FALSE,
      '#description' => $this->t('CiviCRM\'s type-level "Thanks for Attending!" reminder asks every attendee for the survey 24 hours after a class; that stays. A week after the <em>last</em> session this module sends at most one more email per attendee: a join / tour offer to someone with no member account, or "your badge is waiting" to a member whose badge request for the class\'s badge is still pending. Instructors and hosts never get either. Separately, a registration that stalled at payment gets one "your seat is still open" email a day later while a seat is open, and waitlisted events get an offer window so an unpaid waitlist offer cannot hold a seat forever. Dry runs: <code>drush ic-followups</code>, <code>drush ic-abandoned</code>, <code>drush ic-waitlist-expiry</code>, <code>drush ic-reminder-cleanup</code>.'),
    ];
    $form['followup']['followup_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send the post-class follow-up'),
      '#default_value' => (bool) ($config->get('followup_enabled') ?? TRUE),
    ];
    $form['followup']['followup_delay_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days after the last session'),
      '#min' => 1,
      '#max' => 30,
      '#default_value' => $config->get('followup_delay_days') ?: \Drupal\instructor_companion\Service\AttendeeFollowupService::DEFAULT_DELAY_DAYS,
      '#description' => $this->t('The survey email goes out at 24 hours; this one should not compete with it.'),
    ];
    if ($followup_types) {
      $form['followup']['followup_event_types'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Event types whose attendees get it'),
        '#options' => $followup_types,
        '#default_value' => \Drupal::service('instructor_companion.attendee_followup')->eventTypes(),
        '#description' => $this->t('Meetups are worth including: a meetup attendee who is not a member is exactly the person the join offer is for.'),
      ];
    }
    $form['followup']['followup_join_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Join page'),
      '#default_value' => $config->get('followup_join_url') ?: \Drupal\instructor_companion\Service\AttendeeFollowupService::DEFAULT_JOIN_URL,
    ];
    $form['followup']['followup_tour_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tour page'),
      '#default_value' => $config->get('followup_tour_url') ?: \Drupal\instructor_companion\Service\AttendeeFollowupService::DEFAULT_TOUR_URL,
    ];
    $form['followup']['followup_join_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Join / tour offer — subject'),
      '#default_value' => $config->get('followup_join_subject') ?: \Drupal\instructor_companion\Service\AttendeeFollowupService::defaultJoinSubject(),
      '#maxlength' => 200,
    ];
    $form['followup']['followup_join_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Join / tour offer — body'),
      '#default_value' => $config->get('followup_join_body') ?: \Drupal\instructor_companion\Service\AttendeeFollowupService::defaultJoinBody(),
      '#rows' => 12,
      '#description' => $this->t('Plain text. Tokens: <code>[first_name]</code>, <code>[event_title]</code>, <code>[join_url]</code>, <code>[tour_url]</code>.'),
    ];
    $form['followup']['followup_badge_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Badge nudge — subject'),
      '#default_value' => $config->get('followup_badge_subject') ?: \Drupal\instructor_companion\Service\AttendeeFollowupService::defaultBadgeSubject(),
      '#maxlength' => 200,
    ];
    $form['followup']['followup_badge_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Badge nudge — body'),
      '#default_value' => $config->get('followup_badge_body') ?: \Drupal\instructor_companion\Service\AttendeeFollowupService::defaultBadgeBody(),
      '#rows' => 12,
      '#description' => $this->t('Plain text. Tokens: <code>[first_name]</code>, <code>[event_title]</code>, <code>[badge_name]</code>, <code>[badge_url]</code>.'),
    ];
    $form['followup']['abandoned_followup_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email a registration that stalled at payment'),
      '#default_value' => (bool) ($config->get('abandoned_followup_enabled') ?? TRUE),
      '#description' => $this->t('Once per attempt, only while the class is more than six hours away and a seat is open, never to someone who has registered since.'),
    ];
    $form['followup']['abandoned_after_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Hours after the attempt'),
      '#min' => 1,
      '#max' => 168,
      '#default_value' => $config->get('abandoned_after_hours') ?: \Drupal\instructor_companion\Service\AbandonedRegistrationService::DEFAULT_AFTER_HOURS,
    ];
    $form['followup']['abandoned_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Stalled registration — subject'),
      '#default_value' => $config->get('abandoned_subject') ?: \Drupal\instructor_companion\Service\AbandonedRegistrationService::defaultSubject(),
      '#maxlength' => 200,
    ];
    $form['followup']['abandoned_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Stalled registration — body'),
      '#default_value' => $config->get('abandoned_body') ?: \Drupal\instructor_companion\Service\AbandonedRegistrationService::defaultBody(),
      '#rows' => 10,
      '#description' => $this->t('Plain text. Tokens: <code>[first_name]</code>, <code>[event_title]</code>, <code>[event_date]</code>, <code>[register_url]</code> (must appear).'),
    ];
    $form['followup']['waitlist_offer_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Waitlist offer window (hours)'),
      '#min' => 0,
      '#max' => 720,
      '#default_value' => $config->get('waitlist_offer_hours') ?? \Drupal\instructor_companion\Service\EventHygieneService::DEFAULT_WAITLIST_OFFER_HOURS,
      '#description' => $this->t('Written to the event\'s expiration time when a waitlisted event is copied or has none. CiviCRM offers a freed seat to the next person and emails them; with a window it also takes the seat back if they do not pay, and offers it to the next. 0 leaves events alone.'),
    ];

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
    if ($form_state->hasValue('session_evaluation_body') && !str_contains((string) $form_state->getValue('session_evaluation_body'), '[evaluation_url]')) {
      $form_state->setErrorByName('session_evaluation_body', $this->t('The evaluation body must contain [evaluation_url] — without it there is nothing to fill in.'));
    }
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
      ->set('slack_channel', trim((string) $form_state->getValue('slack_channel')))
      ->set('orientation_step_enabled', (bool) $form_state->getValue('orientation_step_enabled'))
      ->set('attendance_prompt_enabled', $form_state->hasValue('attendance_prompt_enabled')
        ? (bool) $form_state->getValue('attendance_prompt_enabled')
        : $this->config('instructor_companion.settings')->get('attendance_prompt_enabled'))
      ->set('attendance_prompt_offset_minutes', $form_state->hasValue('attendance_prompt_offset_minutes')
        ? (int) $form_state->getValue('attendance_prompt_offset_minutes')
        : $this->config('instructor_companion.settings')->get('attendance_prompt_offset_minutes'))
      ->set('session_evaluation_enabled', $form_state->hasValue('session_evaluation_enabled')
        ? (bool) $form_state->getValue('session_evaluation_enabled')
        : $this->config('instructor_companion.settings')->get('session_evaluation_enabled'))
      ->set('session_evaluation_delay_hours', $form_state->hasValue('session_evaluation_delay_hours')
        ? (int) $form_state->getValue('session_evaluation_delay_hours')
        : $this->config('instructor_companion.settings')->get('session_evaluation_delay_hours'))
      ->set('session_evaluation_subject', $form_state->hasValue('session_evaluation_subject')
        ? trim((string) $form_state->getValue('session_evaluation_subject'))
        : $this->config('instructor_companion.settings')->get('session_evaluation_subject'))
      ->set('session_evaluation_body', $form_state->hasValue('session_evaluation_body')
        ? (string) $form_state->getValue('session_evaluation_body')
        : $this->config('instructor_companion.settings')->get('session_evaluation_body'))
      ->set('session_evaluation_url', $form_state->hasValue('session_evaluation_url')
        ? trim((string) $form_state->getValue('session_evaluation_url'))
        : $this->config('instructor_companion.settings')->get('session_evaluation_url'))
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
      ->set('followup_enabled', (bool) $form_state->getValue('followup_enabled'))
      ->set('followup_delay_days', (int) $form_state->getValue('followup_delay_days'))
      ->set('followup_event_types', $form_state->hasValue('followup_event_types')
        ? array_values(array_map('intval', array_filter((array) $form_state->getValue('followup_event_types'))))
        : $config->get('followup_event_types'))
      ->set('followup_join_url', (string) $form_state->getValue('followup_join_url'))
      ->set('followup_tour_url', (string) $form_state->getValue('followup_tour_url'))
      ->set('followup_join_subject', (string) $form_state->getValue('followup_join_subject'))
      ->set('followup_join_body', (string) $form_state->getValue('followup_join_body'))
      ->set('followup_badge_subject', (string) $form_state->getValue('followup_badge_subject'))
      ->set('followup_badge_body', (string) $form_state->getValue('followup_badge_body'))
      ->set('abandoned_followup_enabled', (bool) $form_state->getValue('abandoned_followup_enabled'))
      ->set('abandoned_after_hours', (int) $form_state->getValue('abandoned_after_hours'))
      ->set('abandoned_subject', (string) $form_state->getValue('abandoned_subject'))
      ->set('abandoned_body', (string) $form_state->getValue('abandoned_body'))
      ->set('waitlist_offer_hours', (int) $form_state->getValue('waitlist_offer_hours'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
