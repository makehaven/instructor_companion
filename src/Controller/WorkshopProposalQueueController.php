<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\webform\WebformSubmissionInterface;

/**
 * Staff queue for the Workshop Proposal webform (webform_497).
 *
 * Exists to surface the ~100 unreviewed submissions currently sitting
 * behind email-only delivery. Uses the existing review_status_38 element
 * as the status source until the state machine refactor lands.
 */
class WorkshopProposalQueueController extends SubmissionQueueControllerBase {

  protected function webformId(): string {
    return 'webform_497';
  }

  protected function title(): string {
    return (string) $this->t('Workshop Proposal Queue');
  }

  protected function statusElement(): ?string {
    return 'review_status_38';
  }

  protected function extraHeaders(): array {
    return [
      'title' => $this->t('Proposed Class'),
      'status' => $this->t('Status'),
    ];
  }

  protected function extraCells(WebformSubmissionInterface $submission): array {
    $data = $submission->getData();
    $title = $this->pickFirst($data, [
      'proposed_class_title',
      'proposed_class_title_26',
    ]) ?? $this->t('(untitled)');
    $status = (string) ($data['review_status_38'] ?? '');
    return [
      'title' => $title,
      'status' => $status === '' ? (string) $this->t('— (unreviewed)') : $status,
    ];
  }

}
