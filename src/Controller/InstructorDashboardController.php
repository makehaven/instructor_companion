<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Url;

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
    $database = \Drupal::database();
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
      'Emergency Procedures' => $config->get('emergency_procedures_url'),
      'Instructor Handbook' => $config->get('instructor_handbook_url'),
      'Request Reimbursement' => $config->get('request_reimbursement_url'),
      'Log Hours' => $config->get('log_hours_url'),
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

    // 4. Upcoming Classes Table.
    $header = [
      'date' => $this->t('Date'),
      'title' => $this->t('Class'),
      'enrolled' => $this->t('Enrolled'),
      'actions' => $this->t('Actions'),
    ];

    $rows = [];

    try {
      $query = $database->select('civicrm_event', 'e');
      $query->addField('e', 'id');
      $query->innerJoin('civicrm_event__field_civi_event_instructor', 'i', 'e.id = i.entity_id AND i.deleted = 0');
      $query->condition('i.field_civi_event_instructor_target_id', $current_user->id());
      $query->condition('e.start_date', date('Y-m-d H:i:s'), '>=');
      $query->condition('e.is_active', 1);
      $query->condition('e.is_template', 0);
      $query->orderBy('e.start_date', 'ASC');
      $query->range(0, 20);

      $ids = $query->execute()->fetchCol();
      if (empty($ids)) {
        $events = [];
      }
      else {
        $storage = $this->entityTypeManager()->getStorage('civicrm_event');
        $events = $storage->loadMultiple($ids);
      }

      foreach ($events as $event) {
        $event_id = $event->id();

        // Calculate Enrollment
        $enrollment_query = $database->select('civicrm_participant', 'p');
        $enrollment_query->addExpression('COUNT(p.id)', 'count');
        $enrollment_query->innerJoin('civicrm_participant_status_type', 'pst', 'p.status_id = pst.id');
        $enrollment_query->condition('p.event_id', $event_id);
        $enrollment_query->condition('pst.is_counted', 1);
        $enrollment_query->condition('p.is_test', 0);
        $enrolled_count = $enrollment_query->execute()->fetchField();

        $capacity = $event->get('max_participants')->value ?? '∞';

        // Roster Link (Deep link to CiviCRM participant list)
        $roster_url = Url::fromUri('internal:/civicrm/event/participant', [
          'query' => [
            'reset' => 1,
            'id' => $event_id,
          ],
        ]);

        // Feedback Link (Deep link to new Webform)
        $feedback_url = Url::fromUserInput('/form/instructor_feedback', [
          'query' => [
            'event_id' => $event_id,
          ],
        ]);

        // Format the date nicely.
        $start_date_value = $event->get('start_date')->value;
        $formatted_date = '';
        if ($start_date_value) {
          $date = new DrupalDateTime($start_date_value);
          $formatted_date = $date->format('D, M j, Y \a\t g:ia');
        }

        $rows[] = [
          'date' => $formatted_date,
          'title' => $event->label(),
          'enrolled' => "$enrolled_count / $capacity",
          'actions' => [
            'data' => [
              '#type' => 'dropbutton',
              '#links' => [
                'roster' => [
                  'title' => $this->t('Roster'),
                  'url' => $roster_url,
                ],
                'feedback' => [
                  'title' => $this->t('Submit Feedback'),
                  'url' => $feedback_url,
                ],
              ],
            ],
          ],
        ];
      }
    }
    catch (\Exception $e) {
      // Fail gracefully if CiviCRM Entity is not ready or field missing.
      \Drupal::logger('instructor_companion')->error($e->getMessage());
    }

    $build['classes_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('You have no upcoming classes assigned.'),
      '#caption' => $this->t('My Upcoming Classes'),
    ];

    return $build;
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

}
