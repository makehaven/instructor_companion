<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\webform\WebformSubmissionInterface;

/**
 * Staff queue for the Instructor Interest webform (webform_14366).
 *
 * webform_14366 has no built-in review status field yet, so this queue
 * just lists everything by age. Status tracking is a follow-up.
 */
class InstructorInterestQueueController extends SubmissionQueueControllerBase {

  protected function webformId(): string {
    return 'webform_14366';
  }

  protected function title(): string {
    return (string) $this->t('Instructor Interest Queue');
  }

  protected function extraHeaders(): array {
    return [
      'areas' => $this->t('Areas of Interest'),
    ];
  }

  protected function extraCells(WebformSubmissionInterface $submission): array {
    $data = $submission->getData();
    $areas = $this->pickFirst($data, [
      'areas_of_interest_skill',
      'areas_of_interest_skill_6',
    ]) ?? '';
    return [
      'areas' => $areas,
    ];
  }

}
