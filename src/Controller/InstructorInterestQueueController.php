<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\webform\WebformSubmissionInterface;

/**
 * Staff queue for the Instructor Interest webform (webform_14366).
 *
 * Uses the review_status_38 element as the status source to allow filtering
 * and hiding already-processed submissions.
 */
class InstructorInterestQueueController extends SubmissionQueueControllerBase {

  protected function webformId(): string {
    return 'webform_14366';
  }

  protected function title(): string {
    return (string) $this->t('Instructor Interest Queue');
  }

  protected function statusElement(): ?string {
    return 'review_status_38';
  }

  protected function extraHeaders(): array {
    return [
      'areas' => $this->t('Areas of Interest'),
      'status' => $this->t('Status'),
      'outreach' => $this->t('Outreach'),
    ];
  }

  protected function extraCells(WebformSubmissionInterface $submission): array {
    $data = $submission->getData();
    $areas = $this->pickFirst($data, [
      'areas_of_interest_skill',
      'areas_of_interest_skill_6',
    ]) ?? '';
    $status = (string) ($data['review_status_38'] ?? '');
    $outreach_at = (string) ($data['interest_outreach_sent_at'] ?? '');
    if ($outreach_at !== '') {
      $ts = strtotime($outreach_at);
      $outreach_cell = $ts ? date('M j, Y', $ts) : $outreach_at;
    }
    else {
      $outreach_cell = (string) $this->t('—');
    }
    return [
      'areas' => $areas,
      'status' => $status === '' ? (string) $this->t('— (unreviewed)') : $status,
      'outreach' => $outreach_cell,
    ];
  }

}
