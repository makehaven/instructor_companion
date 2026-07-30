<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Weekly staff nudge for instructor submissions stuck without review.
 *
 * The interest (webform_14366) and workshop-proposal (webform_497) queues only
 * trigger their automations when staff set a Review Status — work that
 * historically happened out-of-band by email, leaving submissions unreviewed
 * indefinitely. Once a week, cron emails the education inbox a digest of
 * submissions older than 7 days with no review status so nothing sits silently.
 * Only submissions from the last 90 days are counted — the pre-queue backlog
 * is treated as archived rather than nagged about forever.
 */
class StaleReviewNudge {

  private const STATE_KEY = 'instructor_companion.stale_review_nudge_last';
  private const RUN_INTERVAL = 604800;
  private const MIN_AGE = 604800;
  private const MAX_AGE = 7776000;

  private const WATCHED_WEBFORMS = [
    'webform_14366' => [
      'label' => 'Instructor Interest',
      'route' => 'instructor_companion.instructor_interest_queue',
    ],
    'webform_497' => [
      'label' => 'Workshop Proposal',
      'route' => 'instructor_companion.workshop_proposal_queue',
    ],
  ];

  public function __construct(
    protected Connection $database,
    protected StateInterface $state,
    protected MailManagerInterface $mailManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Cron entry point; sends at most one digest per RUN_INTERVAL.
   */
  public function run(): void {
    $now = \Drupal::time()->getRequestTime();
    $last = (int) $this->state->get(self::STATE_KEY, 0);
    if ($now < $last + self::RUN_INTERVAL) {
      return;
    }
    // Stamp first so a mail failure can't cause a nag loop every cron run.
    $this->state->set(self::STATE_KEY, $now);

    $stale = $this->staleSubmissions($now);
    if (!$stale) {
      return;
    }

    $config = $this->configFactory->get('instructor_companion.settings');
    $to = $config->get('notification_email') ?: $this->configFactory->get('system.site')->get('mail');

    $sections = [];
    $total = 0;
    foreach ($stale as $webform_id => $info) {
      $queue_url = Url::fromRoute(self::WATCHED_WEBFORMS[$webform_id]['route'], [], ['absolute' => TRUE])->toString();
      $sections[] = [
        'label' => self::WATCHED_WEBFORMS[$webform_id]['label'],
        'count' => $info['count'],
        'oldest' => date('M j, Y', $info['oldest']),
        'queue_url' => $queue_url,
      ];
      $total += $info['count'];
    }

    $this->mailManager->mail('instructor_companion', 'stale_review_nudge', $to, 'en', [
      'total' => $total,
      'sections' => $sections,
    ], NULL, TRUE);

    $this->logger->notice('Stale-review nudge sent to @to: @n unreviewed submission(s).', [
      '@to' => $to,
      '@n' => $total,
    ]);
  }

  /**
   * Finds completed submissions 7–90 days old with no review status.
   *
   * @return array
   *   webform_id => ['count' => int, 'oldest' => timestamp], only for forms
   *   that have stale items.
   */
  protected function staleSubmissions(int $now): array {
    $query = $this->database->select('webform_submission', 'ws');
    $query->leftJoin('webform_submission_data', 'sd', "sd.sid = ws.sid AND sd.name = 'review_status_38'");
    $query->fields('ws', ['webform_id', 'created']);
    $query->condition('ws.webform_id', array_keys(self::WATCHED_WEBFORMS), 'IN');
    $query->condition('ws.in_draft', 0);
    $query->condition('ws.created', $now - self::MIN_AGE, '<');
    $query->condition('ws.created', $now - self::MAX_AGE, '>');
    $or = $query->orConditionGroup()
      ->isNull('sd.value')
      ->condition('sd.value', '');
    $query->condition($or);

    $stale = [];
    foreach ($query->execute() as $row) {
      $stale[$row->webform_id]['count'] = ($stale[$row->webform_id]['count'] ?? 0) + 1;
      $stale[$row->webform_id]['oldest'] = min($stale[$row->webform_id]['oldest'] ?? PHP_INT_MAX, (int) $row->created);
    }
    return $stale;
  }

}
