<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\PostEventStatusService;
use Drupal\instructor_companion\Service\SessionSchedule;
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
    protected ?SessionSchedule $sessions = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('instructor_companion.post_event_status'),
      $container->get('instructor_companion.sessions'),
    );
  }

  /**
   * Builds the post-class hub page for one event.
   */
  public function build(int $event_id): array {
    $event = $this->entityTypeManager()->getStorage('civicrm_event')->load($event_id);
    if (!$event) {
      throw new NotFoundHttpException();
    }

    $uid = $event->hasField('field_civi_event_instructor')
      ? (int) $event->get('field_civi_event_instructor')->target_id
      : 0;
    $status = $this->postEventStatus->getStatus($event_id, $uid);

    $build = [];
    $build['#attached']['library'][] = 'instructor_companion/dashboard';
    $build['#cache']['max-age'] = 0;

    $date = $this->formatEventDate((string) $event->get('start_date')->value);
    $sessions = $this->sessions;
    $schedule = $sessions ? $sessions->getSchedule($event_id) : [];
    $shape = count($schedule) > 1
      ? ' <span class="peh-date">· ' . SessionSchedule::summary($schedule) . '</span>'
      : '';
    $build['header'] = [
      // The page title already says "Post-Class Tasks".
      '#markup' => '<div class="peh-header">'
      . '<p class="peh-event">' . $event->label()
      . ($date ? ' <span class="peh-date">· ' . $date . '</span>' : '') . $shape . '</p>'
      . '<p class="peh-progress"><span class="peh-pill">' . $status['progress'] . ' '
      . $this->t('done') . '</span></p>'
      . '</div>',
    ];

    $now_local = date('Y-m-d H:i:s');
    if ($sessions && $sessions->hasSessionAfter($event_id, $now_local)) {
      $next = $sessions->nextSession($event_id, $now_local);
      $last = end($schedule);
      $build['in_progress'] = [
        '#markup' => '<div class="messages messages--status peh-inprogress">'
        . $this->t('This class is still running: next session @next, last session @last. Save attendance and log your hours after each session; badges and feedback are due after the last one — we will remind you then.', [
          '@next' => $next ? SessionSchedule::label($next['start']) : '',
          '@last' => SessionSchedule::label($last['start']),
        ]) . '</div>',
      ];
    }

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
    // Optional, so it is not counted in "n/4 done" — but it belongs in the
    // list rather than floating above it.
    $build['steps']['email_class'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['peh-step', 'peh-step--optional']],
      'icon' => ['#markup' => '<span class="peh-step-icon" aria-hidden="true">✉</span>'],
      'body' => [
        '#markup' => '<div class="peh-step-body"><span class="peh-step-title">'
        . (count($status['steps']) + 1) . '. ' . $this->t('Email your class (optional)') . '</span>'
        . '<span class="peh-step-detail">' . $this->t('A thank-you, the slides, or what to bring next time.') . '</span></div>',
      ],
      'action' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['peh-step-action']],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Write an email'),
          '#url' => Url::fromRoute('instructor_companion.class_email', ['event_id' => $event_id]),
          '#attributes' => ['class' => ['button', 'button--small', 'button--secondary']],
        ],
      ],
    ];

    $staff = $this->currentUser()->hasPermission('access education console');
    $build['back'] = [
      '#type' => 'link',
      '#title' => $staff ? $this->t('← Back to Education') : $this->t('← Back to Instructor Dashboard'),
      '#url' => Url::fromRoute($staff ? 'instructor_companion.education_console' : 'instructor_companion.dashboard'),
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
        $label = $done ? $this->t('Review / edit') : $this->t('Fill out feedback');
        break;

      case PostEventStatusService::STEP_PAYMENT:
        $url = $this->paymentUrl($event_id);
        $label = $done ? $this->t('View / update') : $this->t('Log hours');
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
          . $this->t('You can request it now. Finishing steps 1 to 3 first helps us pay you sooner.')
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
      // Entity values ("T", UTC) vs CiviCRM's own local strings.
      $date = (str_contains($start_date_value, 'T')
        ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', substr($start_date_value, 0, 19), new \DateTimeZone('UTC'))
        : \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start_date_value, $tz))
        ?: new \DateTimeImmutable($start_date_value, $tz);
      return $date->setTimezone($tz)->format('D, M j, Y \a\t g:ia T');
    }
    catch (\Exception $e) {
      return $start_date_value;
    }
  }

}
