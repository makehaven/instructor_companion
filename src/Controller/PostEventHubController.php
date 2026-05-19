<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\PostEventStatusService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-class post-event hub.
 *
 * One page that walks an instructor through the four wrap-up tasks for a
 * single class with live completion ticks. Soft gate only — incomplete steps
 * are flagged, never blocked. The +48h reminder email deep-links straight here.
 */
class PostEventHubController extends ControllerBase {

  public function __construct(
    protected PostEventStatusService $postEventStatus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('instructor_companion.post_event_status'));
  }

  /**
   * Builds the post-class hub page for one event.
   */
  public function build(int $event_id): array {
    $event = $this->entityTypeManager()->getStorage('civicrm_event')->load($event_id);
    if (!$event) {
      throw new NotFoundHttpException();
    }

    $uid = (int) $this->currentUser()->id();
    $status = $this->postEventStatus->getStatus($event_id, $uid);

    $build = [];
    $build['#attached']['library'][] = 'instructor_companion/dashboard';
    $build['#cache']['max-age'] = 0;

    $date = $this->formatEventDate((string) $event->get('start_date')->value);
    $build['header'] = [
      '#markup' => '<div class="peh-header">'
      . '<h2>' . $this->t('Post-Class Tasks') . '</h2>'
      . '<p class="peh-event">' . $event->label()
      . ($date ? ' <span class="peh-date">· ' . $date . '</span>' : '') . '</p>'
      . '<p class="peh-progress"><span class="peh-pill">' . $status['progress'] . ' '
      . $this->t('done') . '</span></p>'
      . '</div>',
    ];

    if ($status['all_complete']) {
      $build['done'] = [
        '#markup' => '<div class="messages messages--status peh-alldone">'
        . $this->t('🎉 All wrap-up tasks for this class are done. Thank you!') . '</div>',
      ];
    }

    $build['steps'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['peh-steps']],
    ];
    foreach ($status['steps'] as $i => $step) {
      $build['steps'][$step['key']] = $this->buildStep($event_id, $i + 1, $step, $status);
    }

    $build['back'] = [
      '#type' => 'link',
      '#title' => $this->t('← Back to Instructor Dashboard'),
      '#url' => Url::fromRoute('instructor_companion.dashboard'),
      '#attributes' => ['class' => ['button', 'button--small', 'peh-back']],
    ];

    return $build;
  }

  /**
   * Renders one checklist step row with its state icon and action.
   */
  protected function buildStep(int $event_id, int $n, array $step, array $status): array {
    if (!$step['applicable']) {
      $icon = '—';
      $state_class = 'peh-step--na';
    }
    elseif ($step['complete']) {
      $icon = '✓';
      $state_class = 'peh-step--done';
    }
    else {
      $icon = '◷';
      $state_class = 'peh-step--todo';
    }

    $action = $this->stepAction($event_id, $step, $status);
    $row = [
      '#type' => 'container',
      '#attributes' => ['class' => ['peh-step', $state_class]],
      'icon' => ['#markup' => '<span class="peh-step-icon">' . $icon . '</span>'],
      'body' => [
        '#markup' => '<div class="peh-step-body"><span class="peh-step-title">'
        . $n . '. ' . $step['label'] . '</span>'
        . '<span class="peh-step-detail">' . $step['detail'] . '</span></div>',
      ],
    ];
    if ($action) {
      $row['action'] = $action;
    }
    return $row;
  }

  /**
   * Builds the action link for a step (NULL when there's nothing to do).
   */
  protected function stepAction(int $event_id, array $step, array $status): ?array {
    if (!$step['applicable']) {
      return NULL;
    }
    $done = $step['complete'];

    switch ($step['key']) {
      case PostEventStatusService::STEP_ATTENDANCE:
        $url = Url::fromRoute('instructor_companion.attendance', ['event_id' => $event_id]);
        $label = $done ? $this->t('Review / fix') : $this->t('Take attendance');
        break;

      case PostEventStatusService::STEP_BADGES:
        $url = Url::fromRoute('instructor_companion.class_checkout', ['event_id' => $event_id]);
        $label = $done ? $this->t('Review') : $this->t('Approve badges');
        break;

      case PostEventStatusService::STEP_FEEDBACK:
        // Mirrors the dashboard's existing pattern of pointing at the
        // (soon-to-exist) per-event webform path. event_id prefills the form.
        $url = Url::fromUserInput('/form/instructor-feedback', ['query' => ['event_id' => $event_id]]);
        $label = $done ? $this->t('Edit / resubmit') : $this->t('Fill out feedback');
        break;

      case PostEventStatusService::STEP_PAYMENT:
        $url = $this->paymentUrl($event_id);
        $label = $done ? $this->t('View / update') : $this->t('Request payment');
        break;

      default:
        return NULL;
    }

    $action = [
      '#type' => 'container',
      '#attributes' => ['class' => ['peh-step-action']],
      'link' => [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $url,
        '#attributes' => [
          'class' => ['button', 'button--small', $done ? 'button--secondary' : 'button--primary'],
        ],
      ],
    ];

    // Soft gate: nudge (never block) ordering on the payment step.
    if ($step['key'] === PostEventStatusService::STEP_PAYMENT && !$done) {
      $pending = array_intersect($status['incomplete'], [
        PostEventStatusService::STEP_ATTENDANCE,
        PostEventStatusService::STEP_BADGES,
        PostEventStatusService::STEP_FEEDBACK,
      ]);
      if ($pending) {
        $action['tip'] = [
          '#markup' => '<p class="peh-step-tip">'
          . $this->t('Tip: finishing attendance, badges and feedback first helps staff process your payment faster — but you can request it now.')
          . '</p>',
        ];
      }
    }

    return $action;
  }

  /**
   * Builds the payment-request URL.
   *
   * Mirrors the dashboard's link contract: configured Log Hours URL plus an
   * event query arg, falling back to /timesheet.
   */
  protected function paymentUrl(int $event_id): Url {
    $configured = (string) $this->config('instructor_companion.settings')->get('log_hours_url');
    $query = ['event' => $event_id];
    $configured = trim($configured);
    if ($configured !== '' && preg_match('#^https?://#', $configured)) {
      return Url::fromUri($configured, ['query' => $query]);
    }
    $path = $configured !== '' ? $configured : '/timesheet';
    if (!str_starts_with($path, '/')) {
      $path = '/' . $path;
    }
    try {
      return Url::fromUri('internal:' . $path, ['query' => $query]);
    }
    catch (\Exception $e) {
      return Url::fromUri('internal:/timesheet', ['query' => $query]);
    }
  }

  /**
   * Formats a CiviCRM event start date in the site timezone.
   *
   * Same contract as InstructorDashboardController::formatEventDate().
   */
  protected function formatEventDate(string $start_date_value): string {
    if ($start_date_value === '') {
      return '';
    }
    $tz_name = (string) $this->config('system.date')->get('timezone.default');
    if ($tz_name === '') {
      $tz_name = date_default_timezone_get();
    }
    try {
      $tz = new \DateTimeZone($tz_name);
      $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start_date_value, $tz)
        ?: new \DateTimeImmutable($start_date_value, $tz);
      return $date->setTimezone($tz)->format('D, M j, Y \a\t g:ia T');
    }
    catch (\Exception $e) {
      return $start_date_value;
    }
  }

}
