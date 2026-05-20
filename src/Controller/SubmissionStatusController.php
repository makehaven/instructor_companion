<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller to handle quick status updates for webform submissions.
 */
class SubmissionStatusController extends ControllerBase {

  /**
   * Updates the review status of a webform submission.
   */
  public function setStatus(Request $request, WebformSubmissionInterface $submission, string $status): RedirectResponse {
    $webform = $submission->getWebform();
    $webform_id = $webform->id();
    
    // Determine the status tracking element based on the webform.
    $status_element = NULL;
    if ($webform_id === 'webform_497' || $webform_id === 'webform_14366') {
      $status_element = 'review_status_38';
    }

    if (!$status_element) {
      $this->messenger()->addError($this->t('This webform does not support quick status reviews.'));
    }
    else {
      $data = $submission->getData();
      $data[$status_element] = $status;
      $submission->setData($data);
      $submission->save();
      
      $name = !empty($data['name_6']) ? $data['name_6'] : 
              (!empty($data['your_name']) ? $data['your_name'] : 
              (!empty($data['your_name_25']) ? $data['your_name_25'] : 
              (!empty($data['name']) ? $data['name'] : $this->t('Anonymous'))));
              
      $this->messenger()->addStatus($this->t('Submission by @name has been marked as <strong>@status</strong>.', [
        '@name' => $name,
        '@status' => ucfirst($status),
      ]));
    }

    // Redirect to the destination or back to the current page.
    $destination = $request->query->get('destination');
    if ($destination) {
      return new RedirectResponse($destination);
    }

    return $this->redirect('<current>');
  }

}
