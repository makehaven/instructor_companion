<?php

namespace Drupal\instructor_companion\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Post-class feedback bridge: reorder queue + Slack.
 *
 * On submission of the instructor_feedback webform:
 *  - every material the instructor flagged is pushed into the store's
 *    existing reorder queue via makerspace_material_store (no duplicate
 *    reorder mechanism — we reuse ReorderRequestCreator);
 *  - if any materials were flagged or a tool was reported for maintenance,
 *    a note is posted to #shop-updates so staff see it without digging
 *    through submissions.
 *
 * @WebformHandler(
 *   id = "instructor_feedback_handler",
 *   label = @Translation("Post-class feedback bridge"),
 *   category = @Translation("Makerspace"),
 *   description = @Translation("Sends flagged materials to the reorder queue and posts low-supply / tool-maintenance flags to Slack."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */
class InstructorFeedbackHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    // Only act on first save — re-saves shouldn't re-queue reorders.
    if ($update) {
      return;
    }

    $data = $webform_submission->getData();
    $logger = \Drupal::logger('instructor_companion');

    $material_ids = array_filter(array_map('intval', (array) ($data['materials_to_reorder'] ?? [])));
    $tools_text = trim((string) ($data['tools_needing_maintenance'] ?? ''));
    $event_id = (int) ($data['event_id'] ?? 0);

    $reordered = $this->queueReorders($material_ids, $logger);

    if ($reordered || $tools_text !== '') {
      $this->postToSlack($webform_submission, $reordered, $tools_text, $event_id, $logger);
    }
  }

  /**
   * Pushes each flagged material into the store reorder queue.
   *
   * @return string[]
   *   Labels of materials successfully queued.
   */
  protected function queueReorders(array $material_ids, $logger): array {
    if (!$material_ids) {
      return [];
    }
    if (!\Drupal::hasService('makerspace_material_store.reorder_request_creator')) {
      $logger->warning('Reorder request creator service unavailable; @n flagged materials not queued.', ['@n' => count($material_ids)]);
      return [];
    }
    $creator = \Drupal::service('makerspace_material_store.reorder_request_creator');
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $queued = [];
    foreach ($material_ids as $nid) {
      $node = $node_storage->load($nid);
      if (!$node || $node->bundle() !== 'material') {
        continue;
      }
      try {
        if ($creator->createForMaterial($node)) {
          $queued[] = $node->label();
        }
      }
      catch (\Throwable $e) {
        $logger->error('Reorder bridge failed for material @id: @m', [
          '@id' => $nid,
          '@m' => $e->getMessage(),
        ]);
      }
    }
    if ($queued) {
      $logger->notice('Instructor feedback queued @n material(s) for reorder: @list', [
        '@n' => count($queued),
        '@list' => implode(', ', $queued),
      ]);
    }
    return $queued;
  }

  /**
   * Posts a low-supply / tool-maintenance summary to Slack.
   *
   * Reuses the slack_connector webhook pattern (see ProposalNotifier). No-ops
   * silently when the webhook is unconfigured.
   */
  protected function postToSlack(WebformSubmissionInterface $submission, array $reordered, string $tools_text, int $event_id, $logger): void {
    $webhook_url = (string) \Drupal::config('slack_connector.settings')->get('webhook_url');
    if ($webhook_url === '') {
      return;
    }

    $lines = ['🧰 *Post-class shop report*'];
    $event = $event_id
      ? \Drupal::entityTypeManager()->getStorage('civicrm_event')->load($event_id)
      : NULL;
    if ($event) {
      $lines[] = '*Class:* ' . $event->label();
    }
    if ($reordered) {
      $lines[] = '*Materials sent to reorder queue:* ' . implode(', ', $reordered);
    }
    if ($tools_text !== '') {
      $lines[] = '*Tools needing maintenance:* ' . $tools_text;
    }
    try {
      $review_url = $submission->toUrl('canonical', ['absolute' => TRUE])->toString();
      $lines[] = 'Submission: ' . $review_url;
    }
    catch (\Throwable $e) {
      // Non-fatal — the report text is still useful without the link.
    }

    $payload = [
      'channel' => '#shop-updates',
      'blocks' => [
        [
          'type' => 'section',
          'text' => ['type' => 'mrkdwn', 'text' => implode("\n", $lines)],
        ],
      ],
    ];

    try {
      \Drupal::httpClient()->post($webhook_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'json' => $payload,
      ]);
    }
    catch (\Throwable $e) {
      $logger->error('Error posting post-class shop report to Slack: @m', ['@m' => $e->getMessage()]);
    }
  }

}
