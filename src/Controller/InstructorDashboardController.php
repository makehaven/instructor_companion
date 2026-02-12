<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Returns responses for Instructor Companion routes.
 */
class InstructorDashboardController extends ControllerBase {

  /**
   * Builds the Instructor Dashboard.
   */
  public function build() {
    $current_user = $this->currentUser();
    $config = $this->config('instructor_companion.settings');
    $build = [];

    $build['#attached']['library'][] = 'instructor_companion/dashboard';

    // 1. Dashboard Header / Stats & Profile.
    $build['header_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['instructor-dashboard-header']],
    ];

    // Stats Section.
    $stats = $this->getInstructorStats((int) $current_user->id());
    $build['header_container']['stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['instructor-stats-grid']],
    ];

    $build['header_container']['stats']['classes'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-card']],
      'value' => ['#markup' => '<div class="stat-value">' . $stats['classes_count'] . '</div>'],
      'label' => ['#markup' => '<div class="stat-label">' . $this->t('Classes Taught') . '</div>'],
    ];

    $build['header_container']['stats']['students'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-card']],
      'value' => ['#markup' => '<div class="stat-value">' . $stats['students_count'] . '</div>'],
      'label' => ['#markup' => '<div class="stat-label">' . $this->t('Students Impacted') . '</div>'],
    ];

    if ($stats['avg_rating'] > 0) {
      $build['header_container']['stats']['rating'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['stat-card']],
        'value' => ['#markup' => '<div class="stat-value">' . number_format($stats['avg_rating'], 1) . ' / 5.0</div>'],
        'label' => ['#markup' => '<div class="stat-label">' . $this->t('Average Satisfaction') . '</div>'],
      ];
    }

    // Profile Actions
    $build['header_container']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['instructor-actions']],
    ];

    $build['header_container']['actions']['edit_profile'] = [
      '#type' => 'link',
      '#title' => $this->t('Edit Instructor Profile'),
      '#url' => Url::fromUserInput('/user/' . $current_user->id() . '/instructor'),
      '#attributes' => ['class' => ['button', 'button--primary', 'button--small']],
    ];

    // 2. Dashboard Toolkit
    $build['toolkit'] = [
      '#type' => 'details',
      '#title' => $this->t('Instructor Toolkit & Resources'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['instructor-toolkit']],
    ];

    $toolkit_items = [];
    $toolkit_links = [
      'Payment Status' => $config->get('payment_status_url'),
      'Request Reimbursement' => $config->get('request_reimbursement_url'),
      'Log Hours' => $config->get('log_hours_url'),
      'Emergency Procedures' => $config->get('emergency_procedures_url'),
      'Instructor Handbook' => $config->get('instructor_handbook_url'),
    ];

    foreach ($toolkit_links as $label => $value) {
      $url = $this->buildToolkitUrl($value);
      if ($url) {
        $toolkit_items[] = [
          '#type' => 'link',
          '#title' => $label,
          '#url' => $url,
          '#attributes' => ['class' => ['toolkit-link-card']],
        ];
      }
    }

    if (!empty($toolkit_items)) {
      $build['toolkit']['links'] = [
        '#theme' => 'item_list',
        '#items' => $toolkit_items,
        '#attributes' => ['class' => ['toolkit-links-list']],
      ];
    }

    // 3. Profile Check (Warning only if missing)
    $profile_storage = $this->entityTypeManager()->getStorage('profile');
    $profile_ids = $profile_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $current_user->id())
      ->condition('type', 'instructor')
      ->execute();

    if (empty($profile_ids)) {
      $profile_link = Link::fromTextAndUrl(
        $this->t('Click here to create it.'),
        Url::fromUserInput('/user/' . $current_user->id() . '/instructor')
      );
      $warning_message = new FormattableMarkup(
        'You have not set up your Instructor Profile yet. @link',
        ['@link' => $profile_link->toString()]
      );
      $build['profile_warning'] = [
        '#type' => 'messagelist',
        '#messages' => [
          'warning' => [
            $warning_message,
          ],
        ],
      ];
    }

    // 4. Classes tables.
    $header = [
      'date' => $this->t('Date'),
      'title' => $this->t('Class'),
      'enrolled' => $this->t('Enrolled'),
      'payments' => $this->t('Payment Status'),
      'actions' => $this->t('Actions'),
    ];

    $upcoming_rows = [];
    $completed_rows = [];

    try {
      $upcoming_events = $this->loadInstructorEvents((int) $current_user->id(), '>=', 'ASC', 20);
      $completed_events = $this->loadInstructorEvents((int) $current_user->id(), '<', 'DESC', 20);
      $all_event_ids = array_unique(array_merge(array_keys($upcoming_events), array_keys($completed_events)));
      $payment_status_by_event = $this->getPaymentStatusSummaryByEvent((int) $current_user->id(), $all_event_ids);

      foreach ($upcoming_events as $event_id => $event) {
        $upcoming_rows[] = $this->buildEventRow(
          $event,
          $payment_status_by_event[$event_id] ?? $this->t('No requests logged'),
          FALSE
        );
      }

      foreach ($completed_events as $event_id => $event) {
        $completed_rows[] = $this->buildEventRow(
          $event,
          $payment_status_by_event[$event_id] ?? $this->t('No requests logged'),
          TRUE
        );
      }
    }
    catch (\Exception $e) {
      // Fail gracefully if CiviCRM Entity is not ready or field missing.
      \Drupal::logger('instructor_companion')->error($e->getMessage());
    }

    $build['upcoming_classes_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $upcoming_rows,
      '#empty' => $this->t('You have no upcoming classes assigned.'),
      '#caption' => $this->t('My Upcoming Classes'),
    ];

    $build['completed_classes_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $completed_rows,
      '#empty' => $this->t('You have no recently completed classes.'),
      '#caption' => $this->t('Recent / Completed Classes'),
    ];

    return $build;
  }

  /**
   * Loads instructor events by date direction relative to now.
   */
  protected function loadInstructorEvents(int $uid, string $operator, string $sort_direction, int $limit): array {
    $query = \Drupal::database()->select('civicrm_event', 'e');
    $query->addField('e', 'id');
    $query->innerJoin('civicrm_event__field_civi_event_instructor', 'i', 'e.id = i.entity_id AND i.deleted = 0');
    $query->condition('i.field_civi_event_instructor_target_id', $uid);
    $query->condition('e.start_date', gmdate('Y-m-d H:i:s'), $operator);
    $query->condition('e.is_active', 1);
    $query->condition('e.is_template', 0);
    $query->orderBy('e.start_date', $sort_direction);
    $query->range(0, $limit);

    $ids = $query->execute()->fetchCol();
    if (empty($ids)) {
      return [];
    }

    $storage = $this->entityTypeManager()->getStorage('civicrm_event');
    return $storage->loadMultiple($ids);
  }

  /**
   * Builds a dashboard row for a class event.
   */
  protected function buildEventRow($event, TranslatableMarkup|string $payment_status, bool $allow_feedback): array {
    $event_id = (int) $event->id();
    $database = \Drupal::database();
    $config = $this->config('instructor_companion.settings');

    $enrollment_query = $database->select('civicrm_participant', 'p');
    $enrollment_query->addExpression('COUNT(p.id)', 'count');
    $enrollment_query->innerJoin('civicrm_participant_status_type', 'pst', 'p.status_id = pst.id');
    $enrollment_query->condition('p.event_id', $event_id);
    $enrollment_query->condition('pst.is_counted', 1);
    $enrollment_query->condition('p.is_test', 0);
    $enrolled_count = (int) $enrollment_query->execute()->fetchField();

    $capacity = $event->get('max_participants')->value ?? '∞';
    $formatted_date = $this->formatEventDate((string) $event->get('start_date')->value);

    $roster_url = Url::fromUri('internal:/civicrm/event/participant', [
      'query' => [
        'reset' => 1,
        'id' => $event_id,
      ],
    ]);

    $event_context = $event->label() . ($formatted_date ? ' (' . $formatted_date . ')' : '');
    $payment_url = $this->buildToolkitUrlWithQuery($config->get('log_hours_url'), [
      'event' => $event_id,
      'description' => $this->t('Instructor payment for @event', ['@event' => $event_context]),
    ]);
    $reimbursement_url = $this->buildToolkitUrlWithQuery($config->get('request_reimbursement_url'), [
      'event' => $event_id,
      'description' => $this->t('Reimbursement for @event', ['@event' => $event_context]),
    ]);
    $payment_status_url = $this->buildToolkitUrl($config->get('payment_status_url'));
    $feedback_url = Url::fromUserInput('/form/instructor_feedback', [
      'query' => [
        'event_id' => $event_id,
      ],
    ]);

    $links = [
      'roster' => [
        'title' => $this->t('Roster'),
        'url' => $roster_url,
      ],
    ];
    if ($payment_url) {
      $links['log_hours'] = [
        'title' => $this->t('Request Contractor Payment'),
        'url' => $payment_url,
      ];
    }
    if ($reimbursement_url) {
      $links['reimburse'] = [
        'title' => $this->t('Request Reimbursement'),
        'url' => $reimbursement_url,
      ];
    }
    if ($payment_status_url) {
      $links['payment_status'] = [
        'title' => $this->t('View Payment Status'),
        'url' => $payment_status_url,
      ];
    }
    if ($allow_feedback) {
      $links['feedback'] = [
        'title' => $this->t('Submit Feedback'),
        'url' => $feedback_url,
      ];
    }

    return [
      'date' => $formatted_date,
      'title' => $event->label(),
      'enrolled' => "$enrolled_count / $capacity",
      'payments' => ['data' => ['#markup' => (string) $payment_status]],
      'actions' => [
        'data' => [
          '#type' => 'dropbutton',
          '#links' => $links,
        ],
      ],
    ];
  }

  /**
   * Builds payment status summary strings keyed by event ID.
   */
  protected function getPaymentStatusSummaryByEvent(int $uid, array $event_ids): array {
    if (empty($event_ids)) {
      return [];
    }

    $query = \Drupal::database()->select('payment_request_field_data', 'pr');
    $query->innerJoin('payment_request__field_payee', 'payee', 'pr.id = payee.entity_id AND payee.deleted = 0');
    $query->innerJoin('payment_request__field_event', 'event_ref', 'pr.id = event_ref.entity_id AND event_ref.deleted = 0');
    $query->leftJoin('payment_request__field_status', 'status', 'pr.id = status.entity_id AND status.deleted = 0');
    $query->addField('event_ref', 'field_event_target_id', 'event_id');
    $query->addField('status', 'field_status_value', 'status_value');
    $query->addExpression('COUNT(pr.id)', 'request_count');
    $query->condition('payee.field_payee_target_id', $uid);
    $query->condition('event_ref.field_event_target_id', $event_ids, 'IN');
    $query->groupBy('event_ref.field_event_target_id');
    $query->groupBy('status.field_status_value');

    $records = $query->execute()->fetchAll();
    $grouped = [];
    foreach ($records as $record) {
      $event_id = (int) $record->event_id;
      $status = (string) ($record->status_value ?: 'unknown');
      $count = (int) $record->request_count;
      $grouped[$event_id][$status] = $count;
    }

    $weight = [
      'paid' => 1,
      'approved' => 2,
      'submitted' => 3,
      'draft' => 4,
      'rejected' => 5,
      'unknown' => 6,
    ];
    $label_map = [
      'paid' => $this->t('Paid'),
      'approved' => $this->t('Approved'),
      'submitted' => $this->t('Submitted'),
      'draft' => $this->t('Draft'),
      'rejected' => $this->t('Rejected'),
      'unknown' => $this->t('Unknown'),
    ];

    $summary = [];
    foreach ($grouped as $event_id => $status_counts) {
      uksort($status_counts, static function (string $a, string $b) use ($weight): int {
        return ($weight[$a] ?? 99) <=> ($weight[$b] ?? 99);
      });

      $parts = [];
      foreach ($status_counts as $status => $count) {
        $parts[] = (string) ($label_map[$status] ?? new TranslatableMarkup('Unknown')) . ' (' . $count . ')';
      }
      $summary[$event_id] = implode(', ', $parts);
    }

    return $summary;
  }

  /**
   * Fetches statistics for an instructor.
   */
  protected function getInstructorStats(int $uid): array {
    $database = \Drupal::database();
    $stats = [
      'classes_count' => 0,
      'students_count' => 0,
      'avg_rating' => 0.0,
    ];

    try {
      // 1. Classes and Students
      $query = $database->select('civicrm_event', 'e');
      $query->addExpression('COUNT(DISTINCT e.id)', 'classes_count');
      $query->addExpression('COUNT(p.id)', 'students_count');
      $query->innerJoin('civicrm_event__field_civi_event_instructor', 'i', 'e.id = i.entity_id AND i.deleted = 0');
      $query->leftJoin('civicrm_participant', 'p', 'e.id = p.event_id');
      $query->leftJoin('civicrm_participant_status_type', 'pst', 'p.status_id = pst.id');
      $query->condition('i.field_civi_event_instructor_target_id', $uid);
      $query->condition('e.is_active', 1);
      $query->condition('e.is_template', 0);
      $query->condition('e.start_date', date('Y-m-d H:i:s'), '<');
      
      $record = $query->execute()->fetchAssoc();
      $stats['classes_count'] = (int) ($record['classes_count'] ?? 0);
      $stats['students_count'] = (int) ($record['students_count'] ?? 0);

      // 2. Average Rating from Satisfaction surveys (webform_1181)
      $rating_query = $database->select('webform_submission_data', 'd1');
      $rating_query->addExpression('AVG(CAST(d1.value as DECIMAL(10,2)))', 'avg_val');
      $rating_query->innerJoin('webform_submission_data', 'd2', 'd1.sid = d2.sid');
      $rating_query->innerJoin('civicrm_event__field_civi_event_instructor', 'i', 'd2.value = i.entity_id AND i.deleted = 0');
      $rating_query->condition('d1.webform_id', 'webform_1181');
      $rating_query->condition('d1.name', 'overall_how_satisfied_were_you_with_the_event');
      $rating_query->condition('d2.name', 'event_id');
      $rating_query->condition('i.field_civi_event_instructor_target_id', $uid);
      $avg_rating = $rating_query->execute()->fetchField();
      $stats['avg_rating'] = (float) $avg_rating;
    }
    catch (\Exception $e) {
      \Drupal::logger('instructor_companion')->warning('Could not fetch instructor stats: @message', ['@message' => $e->getMessage()]);
    }

    return $stats;
  }

  /**
   * Builds a Url object for a toolkit link value.
   *
   * @param string|null $value
   *   The configured link value.
   *
   * @return \Drupal\Core\Url|null
   *   The Url object or NULL if empty/invalid.
   */
  protected function buildToolkitUrl(?string $value): ?Url {
    $value = trim((string) $value);
    if ($value === '') {
      return NULL;
    }

    // If it looks like a full URL, use fromUri.
    if (preg_match('/^https?:\/\//', $value)) {
      return Url::fromUri($value);
    }

    // Ensure internal paths start with a valid character for fromUserInput.
    if (!preg_match('/^[\/\?#]/', $value)) {
      $value = '/' . $value;
    }

    try {
      return Url::fromUserInput($value);
    }
    catch (\Exception $e) {
      \Drupal::logger('instructor_companion')->warning('Invalid toolkit URL configured: @value', ['@value' => $value]);
      return NULL;
    }
  }

  /**
   * Builds a URL and merges query args with existing params.
   */
  protected function buildToolkitUrlWithQuery(?string $value, array $query): ?Url {
    $url = $this->buildToolkitUrl($value);
    if (!$url) {
      return NULL;
    }

    $existing_query = (array) $url->getOption('query');
    $url->setOption('query', array_merge($existing_query, $query));
    return $url;
  }

  /**
   * Formats an event start date in the site timezone.
   */
  protected function formatEventDate(string $start_date_value): string {
    if ($start_date_value === '') {
      return '';
    }

    $site_timezone = (string) $this->config('system.date')->get('timezone.default');
    if ($site_timezone === '') {
      $site_timezone = date_default_timezone_get();
    }

    $date = DrupalDateTime::createFromFormat('Y-m-d H:i:s', $start_date_value, 'UTC');
    if (!$date) {
      $date = new DrupalDateTime($start_date_value);
    }
    $date->setTimezone(new \DateTimeZone($site_timezone));

    return $date->format('D, M j, Y \a\t g:ia T');
  }

}
