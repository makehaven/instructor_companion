<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\ClientInterface;

/**
 * Posts Slack notifications for new workshop proposal / instructor interest
 * submissions.
 *
 * Uses the same webhook pattern as slack_task_poster: reads the Slack
 * Connector webhook URL from config and POSTs a blocks payload directly.
 * Kept as a service (not a bare hook) so message construction is unit-
 * testable.
 */
class ProposalNotifier {

  /**
   * Webform IDs this notifier handles, mapped to their display metadata.
   */
  public const HANDLED_WEBFORMS = [
    'webform_497' => [
      'label' => 'Workshop Proposal',
      'channel' => '#workshop-proposals',
      'queue_route' => 'instructor_companion.workshop_proposal_queue',
      'icon' => '🎓',
    ],
    'webform_14366' => [
      'label' => 'Instructor Interest',
      'channel' => '#workshop-proposals',
      'queue_route' => 'instructor_companion.instructor_interest_queue',
      'icon' => '👋',
    ],
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Sends a Slack notification for a webform submission, if it's one we handle.
   *
   * Silently returns for unrelated submissions or when the webhook is
   * unconfigured, so callers can invoke this unconditionally from a hook.
   */
  public function notifyForSubmission(WebformSubmissionInterface $submission): void {
    $webform_id = $submission->getWebform()->id();
    if (!isset(self::HANDLED_WEBFORMS[$webform_id])) {
      return;
    }
    $meta = self::HANDLED_WEBFORMS[$webform_id];

    $webhook_url = (string) $this->configFactory
      ->get('slack_connector.settings')
      ->get('webhook_url');
    if ($webhook_url === '') {
      $this->logger->warning('Slack webhook URL not configured; skipping @label notification.', ['@label' => $meta['label']]);
      return;
    }

    $text = $this->buildMessageText($submission, $meta);

    $payload = [
      'channel' => $meta['channel'],
      'blocks' => [
        [
          'type' => 'section',
          'text' => ['type' => 'mrkdwn', 'text' => $text],
        ],
      ],
    ];

    try {
      $this->httpClient->post($webhook_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'json' => $payload,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Error posting @label to Slack: @msg', [
        '@label' => $meta['label'],
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Builds the Slack message body. Public for unit testing.
   *
   * @param array $meta
   *   Entry from HANDLED_WEBFORMS for this submission's webform.
   */
  public function buildMessageText(WebformSubmissionInterface $submission, array $meta): string {
    $data = $submission->getData();

    $title = $this->pickFirst($data, [
      'proposed_class_title',
      'proposed_class_title_26',
      'name',
      'name_6',
      'your_name',
      'your_name_25',
    ]) ?: '(no title)';

    $submitter = $this->pickFirst($data, [
      'your_name',
      'your_name_25',
      'name',
      'name_6',
    ]);
    $email = $this->pickFirst($data, [
      'e_mail_address',
      'e_mail_address_25',
      'email',
      'email_6',
    ]);

    $queue_url = Url::fromRoute($meta['queue_route'], [], ['absolute' => TRUE])->toString();
    $review_url = Url::fromRoute(
      'entity.webform_submission.canonical',
      [
        'webform' => $submission->getWebform()->id(),
        'webform_submission' => $submission->id(),
      ],
      ['absolute' => TRUE]
    )->toString();

    $who = $submitter ? " from *{$submitter}*" : '';
    if ($email) {
      $who .= " ({$email})";
    }

    return sprintf(
      "%s New %s%s: *%s*\nReview: %s · Queue: %s",
      $meta['icon'],
      $meta['label'],
      $who,
      $title,
      $review_url,
      $queue_url,
    );
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

}
