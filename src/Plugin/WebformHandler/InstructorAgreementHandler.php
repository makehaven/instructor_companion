<?php

namespace Drupal\instructor_companion\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Updates (or creates) the instructor profile with agreement date on submission.
 *
 * @WebformHandler(
 *   id = "instructor_agreement_handler",
 *   label = @Translation("Instructor Agreement Handler"),
 *   category = @Translation("Makerspace"),
 *   description = @Translation("Creates an instructor profile if needed and records the agreement sign date."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */
class InstructorAgreementHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $account = $webform_submission->getOwner();
    if (!$account || $account->isAnonymous()) {
      return;
    }

    $profile_storage = \Drupal::entityTypeManager()->getStorage('profile');
    $profiles = $profile_storage->loadByProperties([
      'uid' => $account->id(),
      'type' => 'instructor',
    ]);

    $now = date('Y-m-d\TH:i:s');

    if (!empty($profiles)) {
      $profile = reset($profiles);
      $profile->set('field_instructor_agreement_date', $now);
      if ($profile->hasField('field_instructor_status') && $profile->get('field_instructor_status')->isEmpty()) {
        $profile->set('field_instructor_status', 'inactive');
      }
      $profile->save();
      \Drupal::logger('instructor_companion')->notice('Instructor agreement signed by @name. Profile @id updated.', [
        '@name' => $account->getDisplayName(),
        '@id' => $profile->id(),
      ]);
    }
    else {
      // Create the instructor profile for an existing member who signed via
      // the /become-instructor flow (no profile exists yet).
      $profile = $profile_storage->create([
        'uid' => $account->id(),
        'type' => 'instructor',
        'status' => 1,
        'field_instructor_agreement_date' => $now,
        'field_instructor_status' => 'inactive',
      ]);
      $profile->save();
      \Drupal::logger('instructor_companion')->notice('Instructor profile created and agreement signed for @name.', [
        '@name' => $account->getDisplayName(),
      ]);
    }

    // Grant the instructor role so the dashboard and profile tools become
    // accessible immediately. Staff approval still gates actual teaching via
    // field_instructor_status on the profile.
    if (!$account->hasRole('instructor')) {
      $account->addRole('instructor');
      $account->save();
      \Drupal::logger('instructor_companion')->notice('Granted instructor role to @name on agreement signing.', [
        '@name' => $account->getDisplayName(),
      ]);
    }

    // Provision a *pending* door badge so building access becomes a deliberate
    // staff decision rather than an automatic side effect of signing. The
    // service no-ops when the user already holds a door badge (every
    // member-instructor does), so this only creates work for the non-member
    // instructors who otherwise have no path to door access. Staff approve it
    // at /admin/people/prospective-instructors; approval flips it to active
    // and unifi_access_sync pushes them to UniFi.
    $door_request = \Drupal::service('instructor_companion.door_access')
      ->ensurePendingRequest((int) $account->id());
    $needs_door_review = $door_request !== NULL;

    // Proposal-first funnel: signing is usually the last onboarding step, so
    // publish any approved sessions that were held on this instructor's
    // onboarding (no-op when there are none — see ProposalHoldManager).
    $released = \Drupal::service('instructor_companion.proposal_hold_manager')
      ->releaseFor((int) $account->id());
    if ($released) {
      \Drupal::messenger()->addStatus(t('Your approved session@plural now live on the MakeHaven calendar: @titles', [
        '@plural' => count($released) > 1 ? 's are' : ' is',
        '@titles' => implode(', ', $released),
      ]));
    }

    // Notify staff via the module mail system.
    $config = \Drupal::config('instructor_companion.settings');
    $to = $config->get('notification_email') ?: \Drupal::config('system.site')->get('mail');
    $params = [
      'user_name' => $account->getDisplayName(),
      'user_email' => $account->getEmail(),
      'user_link' => $account->toUrl()->setAbsolute()->toString(),
      'needs_door_review' => $needs_door_review,
    ];
    \Drupal::service('plugin.manager.mail')->mail(
      'instructor_companion',
      'instructor_agreement_signed',
      $to,
      'en',
      $params,
      NULL,
      TRUE
    );
  }

}
