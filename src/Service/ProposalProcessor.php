<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Token;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\node\Entity\Node;
use Drupal\media\Entity\Media;

/**
 * Dispatches the two staff "Approve" actions in the instructor onboarding
 * funnel:
 *
 *  - webform_497 (Workshop Proposal): mint a draft course node + clone a
 *    CiviCRM event template, link via field_parent_course.
 *  - webform_14366 (Instructor Interest): send the next-steps email so the
 *    submitter knows to start the orientation → agreement → propose flow.
 *    No Drupal user is created here; that happens when they actually click
 *    through and sign up.
 */
class ProposalProcessor {

  /**
   * CiviCRM Event Type ID for Ticketed Workshop.
   * Maps to civicrm_option_value entry.
   */
  public const EVENT_TYPE_TICKETED_WORKSHOP = 6;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MessengerInterface $messenger,
    protected LoggerChannelInterface $logger,
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected Token $token,
  ) {}

  /**
   * Entry point called from the webform submission hooks. Dispatches by
   * webform id; unknown forms are silently ignored.
   */
  public function processApproval(WebformSubmissionInterface $submission): void {
    $webform_id = $submission->getWebform()->id();
    if ($webform_id === 'webform_497') {
      $this->processWorkshopProposalApproval($submission);
    }
    elseif ($webform_id === 'webform_14366') {
      $this->processInstructorInterestApproval($submission);
    }
  }

  /**
   * Handles webform_497 (Workshop Proposal) approval: mint course + draft
   * event.
   */
  protected function processWorkshopProposalApproval(WebformSubmissionInterface $submission): void {
    $data = $submission->getData();
    $status = $data['review_status_38'] ?? NULL;
    if ($status !== 'approved') {
      return;
    }

    // 1. Idempotency check:
    // If a course node already references this submission ID, skip.
    $storage = $this->entityTypeManager->getStorage('node');
    $existing = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->condition('field_source_submission', $submission->id())
      ->execute();
    if (!empty($existing)) {
      $this->logger->info('Course already exists for webform submission @sid; skipping duplicate creation.', ['@sid' => $submission->id()]);
      return;
    }

    try {
      // 2. Resolve Instructor:
      $email = $this->pickFirst($data, [
        'e_mail_address',
        'e_mail_address_25',
        'email',
        'email_6',
      ]);

      $instructor_user = NULL;
      $owner = $submission->getOwner();
      if ($owner && !$owner->isAnonymous()) {
        $instructor_user = $owner;
      }
      elseif (!empty($email)) {
        $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $email]);
        if (!empty($users)) {
          $instructor_user = reset($users);
        }
      }

      $author_uid = $instructor_user ? (int) $instructor_user->id() : (int) \Drupal::currentUser()->id();

      // 3. Map Course Fields:
      $course_title = $this->pickFirst($data, [
        'proposed_class_title',
        'proposed_class_title_26',
      ]) ?: t('(untitled proposal @sid)', ['@sid' => $submission->id()]);

      $body_value = $data['class_description_26'] ?? ($data['class_description'] ?? '');
      $students_will_learn = $data['students_will_learn_to'] ?? '';
      if (!empty($students_will_learn)) {
        $body_value .= "\n\n<h3>What students will learn:</h3>\n" . $students_will_learn;
      }

      $materials = $data['consumable_materials_supplies_27'] ?? ($data['consumable_materials_supplies'] ?? '');

      $raw_compensation = $data['instructor_compensation_25'] ?? ($data['instructor_compensation'] ?? NULL);
      $comp = self::parseCompensation($raw_compensation);

      $image_fid = $this->getFirstFileId($submission, 'topic_images');
      $template_id = self::matchTemplateId($course_title);

      $course_fields = [
        'type' => 'course',
        'title' => $course_title,
        'body' => [
          'value' => $body_value,
          'format' => 'basic_html',
        ],
        'field_course_status' => 'draft',
        'field_course_type' => 'workshop',
        'field_course_materials' => $materials,
        'field_payment_amount' => $comp['amount'],
        'field_payment_type' => $comp['type'],
        'field_civicrm_template_id' => $template_id,
        'field_source_submission' => ['target_id' => $submission->id()],
        'uid' => $author_uid,
        'status' => 0, // Unpublished
      ];

      if ($image_fid) {
        $course_fields['field_course_image'] = ['target_id' => $image_fid];
      }

      /** @var \Drupal\node\NodeInterface $course_node */
      $course_node = $storage->create($course_fields);
      $course_node->save();
      $course_nid = (int) $course_node->id();

      // 4. Create Draft CiviCRM Event:
      $new_event_id = NULL;
      \Drupal::service('civicrm')->initialize();

      $template_exists = FALSE;
      if ($template_id) {
        try {
          $template = \civicrm_api3('Event', 'getsingle', ['id' => $template_id]);
          if (!empty($template['is_template'])) {
            $template_exists = TRUE;
          }
        }
        catch (\Exception $e) {
          $this->logger->notice('CiviCRM Event Template @id lookup failed; falling back to bare event. Error: @msg', [
            '@id' => $template_id,
            '@msg' => $e->getMessage(),
          ]);
        }
      }

      if ($template_exists) {
        try {
          $new_event = \CRM_Event_BAO_Event::copy($template_id);
          $new_event_id = (int) $new_event->id;

          // Reset cloned values via Event.create
          \civicrm_api3('Event', 'create', [
            'id' => $new_event_id,
            'title' => '[NEEDS DATE] ' . $course_title,
            'is_template' => 0,
            'is_active' => 0,
            'is_public' => 0,
            'start_date' => '',
            'end_date' => '',
          ]);
          $this->logger->info('Cloned event @new_id from template @tpl_id for course @c', [
            '@new_id' => $new_event_id,
            '@tpl_id' => $template_id,
            '@c' => $course_nid,
          ]);
        }
        catch (\Exception $e) {
          $this->logger->error('Failed to clone CiviCRM template @id: @msg. Falling back to bare event creation.', [
            '@id' => $template_id,
            '@msg' => $e->getMessage(),
          ]);
        }
      }

      // Case B fallback if template clone was not executed or failed
      if (!$new_event_id) {
        $result = \Civi\Api4\Event::create(FALSE)
          ->addValue('title', '[NEEDS DATE] ' . $course_title)
          ->addValue('event_type_id', self::EVENT_TYPE_TICKETED_WORKSHOP)
          ->addValue('is_active', FALSE)
          ->addValue('is_public', FALSE)
          ->addValue('is_online_registration', TRUE)
          ->addValue('default_role_id', 1)
          ->execute();
        $new_event_id = (int) $result->first()['id'];
        $this->logger->info('Created brand new bare CiviCRM event @new_id for course @c', [
          '@new_id' => $new_event_id,
          '@c' => $course_nid,
        ]);
      }

      // 5. Update Drupal mirror event entity:
      $event_storage = $this->entityTypeManager->getStorage('civicrm_event');
      $event_entity = $event_storage->load($new_event_id);
      if ($event_entity) {
        if ($event_entity->hasField('field_parent_course')) {
          $event_entity->set('field_parent_course', ['target_id' => $course_nid]);
        }

        $raw_capacity = $this->pickFirst($data, [
          'maximum_number_of_students',
          'maximum_number_of_students_26',
          'maximum_number_of_students_27',
          'maximum_students',
        ]);
        $capacity = self::parseCapacity($raw_capacity);
        if ($capacity !== NULL && $event_entity->hasField('field_civi_event_capacity')) {
          $event_entity->set('field_civi_event_capacity', $capacity);
        }

        if ($instructor_user && $event_entity->hasField('field_civi_event_instructor')) {
          $event_entity->set('field_civi_event_instructor', ['target_id' => $instructor_user->id()]);
        }

        // Handle Media Image creation:
        if ($image_fid && $event_entity->hasField('field_civi_event_media_image')) {
          try {
            $media = Media::create([
              'bundle' => 'event_image',
              'uid' => $author_uid,
              'field_media_image_1' => [
                'target_id' => $image_fid,
                'alt' => $course_title,
              ],
            ]);
            $media->setName($course_title);
            $media->status = 1;
            $media->save();
            $event_entity->set('field_civi_event_media_image', ['target_id' => $media->id()]);
          }
          catch (\Exception $e) {
            $this->logger->error('Failed to create media event_image for CiviCRM event @eid: @msg', [
              '@eid' => $new_event_id,
              '@msg' => $e->getMessage(),
            ]);
          }
        }

        $event_entity->save();
      }

      $this->messenger->addStatus(t('Approved proposal "@title". Minted course node #@nid and created draft event #@eid (cloned from template @tpl_id).', [
        '@title' => $course_title,
        '@nid' => $course_nid,
        '@eid' => $new_event_id,
        '@tpl_id' => $template_id,
      ]));
    }
    catch (\Throwable $e) {
      $this->logger->error('Exception processing approved submission @sid: @msg. Trace: @trace', [
        '@sid' => $submission->id(),
        '@msg' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      $this->messenger->addError(t('An error occurred during submission approval: @msg', [
        '@msg' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Handles webform_14366 (Instructor Interest) approval: send the
   * configurable next-steps email to the submitter.
   *
   * Idempotency: writes a timestamp into the `interest_outreach_sent_at`
   * submission element on success. Re-approving a submission that already
   * has a timestamp is a no-op. To resend, staff clear that timestamp on
   * the submission and re-approve.
   */
  protected function processInstructorInterestApproval(WebformSubmissionInterface $submission): void {
    $data = $submission->getData();
    if (($data['review_status_38'] ?? NULL) !== 'approved') {
      return;
    }

    $config = $this->configFactory->get('instructor_companion.settings');
    if (!$config->get('interest_approval_enabled')) {
      $this->logger->info('Interest approval email disabled in settings; not sending for submission @sid.', ['@sid' => $submission->id()]);
      return;
    }

    if (!empty($data['interest_outreach_sent_at'])) {
      $this->logger->info('Outreach email already sent for submission @sid at @when; skipping.', [
        '@sid' => $submission->id(),
        '@when' => $data['interest_outreach_sent_at'],
      ]);
      return;
    }

    $email = $this->pickFirst($data, ['email', 'email_6', 'e_mail_address', 'e_mail_address_25']);
    if (!$email) {
      $this->logger->error('Cannot send interest approval email for submission @sid: no email address on submission.', ['@sid' => $submission->id()]);
      $this->messenger->addWarning(t('Approval saved, but no email address was found on this submission. Outreach email was not sent.'));
      return;
    }

    $name = $this->pickFirst($data, ['name', 'name_6', 'your_name', 'your_name_25']) ?? t('there');

    $subject_tpl = (string) $config->get('interest_approval_subject');
    $body_tpl = (string) $config->get('interest_approval_body');

    // [submission:name] / [submission:email] aren't standard tokens — do
    // the direct substitution BEFORE token->replace, because clear:TRUE
    // strips unrecognised tokens and would erase them.
    $submission_substitutions = [
      '[submission:name]' => (string) $name,
      '[submission:email]' => $email,
    ];
    $subject_tpl = strtr($subject_tpl, $submission_substitutions);
    $body_tpl = strtr($body_tpl, $submission_substitutions);

    $token_data = ['webform_submission' => $submission];
    $token_options = ['clear' => TRUE];
    $subject = $this->token->replace($subject_tpl, $token_data, $token_options);
    $body = $this->token->replace($body_tpl, $token_data, $token_options);

    try {
      $result = $this->mailManager->mail(
        'instructor_companion',
        'interest_approval',
        $email,
        \Drupal::languageManager()->getDefaultLanguage()->getId(),
        ['subject' => $subject, 'body' => $body, 'submission' => $submission],
        NULL,
        TRUE,
      );
      if (empty($result['result'])) {
        $this->logger->error('Mail manager reported failure sending interest_approval to @email for submission @sid.', [
          '@email' => $email,
          '@sid' => $submission->id(),
        ]);
        $this->messenger->addError(t('Approval saved but the next-steps email failed to send. Check logs and resend manually.'));
        return;
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Exception sending interest_approval email for submission @sid: @msg', [
        '@sid' => $submission->id(),
        '@msg' => $e->getMessage(),
      ]);
      $this->messenger->addError(t('Approval saved but the next-steps email threw an error. Check logs.'));
      return;
    }

    // Record outreach on the submission so the queue shows it and we don't
    // resend on subsequent saves. Format mirrors webform's datetime widget.
    $data['interest_outreach_sent_at'] = date('Y-m-d\TH:i:s');
    $submission->setData($data);
    $submission->resave();

    $this->logger->info('Sent interest_approval email to @email for submission @sid.', [
      '@email' => $email,
      '@sid' => $submission->id(),
    ]);
    $this->messenger->addStatus(t('Approved interest from @name. Next-steps email sent to @email.', [
      '@name' => $name,
      '@email' => $email,
    ]));
  }

  /**
   * Parse the instructor compensation text to extract payment amount and type.
   *
   * @param string|null $compensation
   *   The raw compensation string.
   *
   * @return array{amount: float|null, type: string|null}
   */
  public static function parseCompensation(?string $compensation): array {
    if (empty($compensation)) {
      return ['amount' => NULL, 'type' => NULL];
    }

    $compensation = trim($compensation);

    // Hourly patterns like "$50/hour", "$50/hr", "50/hr", "50 / hour", "50 per hour", "50/hour (from saved profile)"
    if (preg_match('/\$?\s*([0-9]+(?:\.[0-9]+)?)\s*(?:\/|per)\s*(?:hour|hr\b)/i', $compensation, $matches)) {
      return [
        'amount' => (float) $matches[1],
        'type' => 'hourly',
      ];
    }

    // Fixed patterns like "$200 flat", "$200 total", "$200 fixed", "200 flat"
    if (preg_match('/\$?\s*([0-9]+(?:\.[0-9]+)?)\s*(?:flat|fixed|total)/i', $compensation, $matches)) {
      return [
        'amount' => (float) $matches[1],
        'type' => 'fixed',
      ];
    }

    // Fallback: just try to extract the first decimal/integer number
    if (preg_match('/\$?\s*([0-9]+(?:\.[0-9]+)?)/', $compensation, $matches)) {
      // If the string contains "hour" or "hr" anywhere, assume hourly, else fixed
      $type = preg_match('/(?:hour|hr)/i', $compensation) ? 'hourly' : 'fixed';
      return [
        'amount' => (float) $matches[1],
        'type' => $type,
      ];
    }

    return ['amount' => NULL, 'type' => NULL];
  }

  /**
   * Parse the maximum number of students to extract an integer.
   *
   * @param string|null $capacity
   *   The raw capacity string.
   *
   * @return int|null
   */
  public static function parseCapacity(?string $capacity): ?int {
    if (empty($capacity)) {
      return NULL;
    }
    if (preg_match('/([0-9]+)/', $capacity, $matches)) {
      return (int) $matches[1];
    }
    return NULL;
  }

  /**
   * Match a course title to a CiviCRM template event ID.
   *
   * @param string $title
   *   The course title.
   *
   * @return int
   */
  public static function matchTemplateId(string $title): int {
    $rules = [
      ['pattern' => '/\bGEMS\b/i', 'template_id' => 1],
      ['pattern' => '/\b(meetup|gathering|office hours)\b/i', 'template_id' => 72],
      ['pattern' => '/\b(tour|field trip|shop tour)\b/i', 'template_id' => 54],
      ['pattern' => '/^Foundations of\b/i', 'template_id' => 166],
      ['pattern' => '/\bPathways?\b/i', 'template_id' => 174],
    ];

    foreach ($rules as $rule) {
      if (preg_match($rule['pattern'], $title)) {
        return $rule['template_id'];
      }
    }

    return 3; // Default is Standard Workshop
  }

  /**
   * Returns the first non-empty value from $data among $keys.
   */
  protected function pickFirst(array $data, array $keys): ?string {
    foreach ($keys as $key) {
      if (!empty($data[$key]) && is_string($data[$key])) {
        return $data[$key];
      }
    }
    return NULL;
  }

  /**
   * Helper to resolve the first file ID (fid) from a webform field.
   */
  protected function getFirstFileId(WebformSubmissionInterface $submission, string $field_name): ?int {
    $data = $submission->getData();
    if (empty($data[$field_name])) {
      return NULL;
    }
    $val = $data[$field_name];
    if (is_array($val)) {
      $first = reset($val);
      return !empty($first) ? (int) $first : NULL;
    }
    return (int) $val;
  }

}
