<?php

namespace Drupal\instructor_companion\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Updates the instructor profile with agreement date upon submission.
 *
 * @WebformHandler(
 *   id = "instructor_agreement_handler",
 *   label = @Translation("Instructor Agreement Handler"),
 *   category = @Translation("Makerspace"),
 *   description = @Translation("Updates the instructor profile with the current date when the agreement is signed."),
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

    if (!empty($profiles)) {
      $profile = reset($profiles);
      // Use Drupal format for datetime field.
      $profile->set('field_instructor_agreement_date', date('Y-m-d\TH:i:s'));
      $profile->save();
      
      \Drupal::logger('instructor_companion')->notice('Instructor agreement signed by @name. Profile @id updated.', [
        '@name' => $account->getDisplayName(),
        '@id' => $profile->id(),
      ]);
    }
    else {
      \Drupal::logger('instructor_companion')->warning('Instructor agreement signed by @name, but no instructor profile found to update.', [
        '@name' => $account->getDisplayName(),
      ]);
    }
  }

}
