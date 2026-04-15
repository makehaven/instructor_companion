<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\Html;
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
   * Rolling window (months) for satisfaction stats and comment surfacing.
   */
  protected const SATISFACTION_WINDOW_MONTHS = 12;

  /**
   * Days an event must be in the past before its comments can be shown.
   *
   * Matches the facilitator dashboard delay pattern — creates separation in
   * time between a submission and its surfacing so attribution is harder.
   */
  protected const FEEDBACK_DELAY_DAYS = 30;

  /**
   * Minimum comment count required before any comments are shown.
   */
  protected const FEEDBACK_MINIMUM_COUNT = 3;

  /**
   * Maximum number of comments to render.
   */
  protected const FEEDBACK_DISPLAY_LIMIT = 6;

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
      $window = self::SATISFACTION_WINDOW_MONTHS;
      $detail_parts = [];
      $detail_parts[] = $this->formatPlural(
        $stats['rating_count'],
        '1 response',
        '@count of @total',
        ['@total' => $stats['eligible_attendees'] ?: $stats['rating_count']]
      );
      $detail_parts[] = $this->t('last @m mo', ['@m' => $window]);
      if ($stats['eligible_attendees'] > 0) {
        $pct = (int) round(($stats['rating_count'] / $stats['eligible_attendees']) * 100);
        $detail_parts[] = $this->t('@pct% response', ['@pct' => $pct]);
      }
      // Render as a top label + smaller detail line so the card stays compact.
      $detail_markup = implode(' · ', array_map('strval', $detail_parts));
      $build['header_container']['stats']['rating'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['stat-card']],
        'value' => ['#markup' => '<div class="stat-value">' . number_format($stats['avg_rating'], 1) . ' / 5.0</div>'],
        'label' => ['#markup' => '<div class="stat-label">' . $this->t('Avg Satisfaction') . '</div>'],
        'detail' => ['#markup' => '<div class="stat-detail">' . $detail_markup . '</div>'],
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

    // 1b. Satisfaction breakdown (distribution + anonymized comments).
    if ($stats['rating_count'] > 0) {
      $build['satisfaction'] = $this->buildSatisfactionSection((int) $current_user->id(), $stats);
    }

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
    else {
      // Check for signed agreement.
      $profile = $profile_storage->load(reset($profile_ids));
      if ($profile->get('field_instructor_agreement_date')->isEmpty()) {
        $agreement_link = Link::fromTextAndUrl(
          $this->t('Please click here to sign the MakeHaven Instructor Agreement.'),
          Url::fromRoute('entity.webform.canonical', ['webform' => 'webform_5220'])
        );
        $warning_message = new FormattableMarkup(
          'Our records indicate you have not yet signed the master instructor agreement. This is required before scheduling or proposing new sessions. @link',
          ['@link' => $agreement_link->toString()]
        );
        $build['agreement_warning'] = [
          '#type' => 'messagelist',
          '#messages' => [
            'warning' => [
              $warning_message,
            ],
          ],
          '#weight' => -100,
        ];
      }
    }

    // 4. High Demand Workshops.
    $high_demand_rows = [];
    $demand_courses = $this->getHighDemandCourses((int) $current_user->id());
    $interest_counts = $this->getCourseInterestCounts(array_keys($demand_courses));
    foreach ($demand_courses as $course) {
      $nid = (int) $course->id();
      $interest = $interest_counts[$nid] ?? 0;
      $feedback = $this->getCourseFeedbackSummary($nid);
      $last_run = $course->get('field_stat_last_run')->value
        ? date('M Y', strtotime($course->get('field_stat_last_run')->value))
        : $this->t('Never');

      $interest_cell = $interest > 0
        ? $this->t('@count @noun following', [
            '@count' => $interest,
            '@noun'  => $interest === 1 ? 'person' : 'people',
          ])
        : $this->t('—');

      $rating_cell = $feedback['count'] > 0
        ? $this->t('@avg / 5 (@n reviews)', [
            '@avg'   => number_format($feedback['avg'], 1),
            '@n'     => $feedback['count'],
          ])
        : $this->t('—');

      $high_demand_rows[] = [
        'title'   => $course->label(),
        'interest' => $interest_cell,
        'rating'  => $rating_cell,
        'last_run' => $last_run,
        'actions' => [
          'data' => [
            '#type'       => 'link',
            '#title'      => $this->t('Propose Session'),
            '#url'        => Url::fromRoute('entity.civicrm_event.add_form', ['bundle' => 'civicrm_event'], [
              'query' => [
                'course_id' => $nid,
                'propose'   => 1,
              ],
            ]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
        ],
      ];
    }

    $build['high_demand_table'] = [
      '#type'    => 'table',
      '#header'  => [
        'title'    => $this->t('Workshop'),
        'interest' => $this->t('Member Interest'),
        'rating'   => $this->t('Avg Rating'),
        'last_run' => $this->t('Last Run'),
        'actions'  => $this->t('Actions'),
      ],
      '#rows'    => $high_demand_rows,
      '#empty'   => $this->t('No high-demand workshops identified at this time.'),
      '#caption' => $this->t('Workshops with High Member Interest (Previously Taught by You)'),
      '#weight'  => 5,
    ];

    // 5. Classes tables.
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

      // Badge prerequisite check: warn if any upcoming class awards a badge the
      // instructor does not yet hold. Non-blocking — just a heads-up.
      $badge_gaps = $this->getBadgePrerequisiteGaps((int) $current_user->id(), $upcoming_events);
      if (!empty($badge_gaps)) {
        $rows = [];
        foreach ($badge_gaps as $gap) {
          $rows[] = [
            'class' => $gap['event_label'] . ($gap['event_date'] ? ' (' . $gap['event_date'] . ')' : ''),
            'badge' => $gap['badge_label'],
            'action' => [
              'data' => [
                '#type' => 'link',
                '#title' => $this->t('Book a badge checkout'),
                '#url' => Url::fromUserInput('/appointment'),
                '#attributes' => ['class' => ['button', 'button--small']],
              ],
            ],
          ];
        }
        $build['badge_prerequisites'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['messages', 'messages--warning']],
          '#weight' => -15,
          'heading' => [
            '#markup' => '<strong>' . $this->t('Badger Status Required') . '</strong><p>'
              . $this->t("You're scheduled to teach classes that award badges, but you're not yet an approved badger for the badge(s) listed below. To become a badger: (1) earn the badge, (2) shadow an existing badger, (3) run a session under supervision, then (4) staff will grant you badger status. Contact education@makehaven.org to start the process.")
              . '</p>',
          ],
          'table' => [
            '#type' => 'table',
            '#header' => [
              'class' => $this->t('Class'),
              'badge' => $this->t('Badge Needed'),
              'action' => $this->t('Get Started'),
            ],
            '#rows' => $rows,
          ],
        ];
      }
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
    // civicrm_event.start_date is stored in the site's local timezone,
    // so compare against local "now" (date()) rather than UTC (gmdate()).
    $query->condition('e.start_date', date('Y-m-d H:i:s'), $operator);
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
      'rating_count' => 0,
      'eligible_attendees' => 0,
      'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
    ];
    $window_start = date('Y-m-d H:i:s', strtotime('-' . self::SATISFACTION_WINDOW_MONTHS . ' months'));
    $now = date('Y-m-d H:i:s');

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

      // 2. Rating distribution from satisfaction surveys (webform_1181),
      // scoped to the rolling window. Excludes star values of 0 (skipped).
      $dist_query = $database->select('webform_submission_data', 'd1');
      $dist_query->addField('d1', 'value');
      $dist_query->addExpression('COUNT(*)', 'n');
      $dist_query->innerJoin('webform_submission_data', 'd2', 'd1.sid = d2.sid');
      $dist_query->innerJoin('civicrm_event__field_civi_event_instructor', 'i', 'd2.value = i.entity_id AND i.deleted = 0');
      $dist_query->innerJoin('civicrm_event', 'e', 'e.id = i.entity_id');
      $dist_query->condition('d1.webform_id', 'webform_1181');
      $dist_query->condition('d1.name', 'overall_how_satisfied_were_you_with_the_event');
      $dist_query->condition('d1.value', 0, '>');
      $dist_query->condition('d2.name', 'event_id');
      $dist_query->condition('i.field_civi_event_instructor_target_id', $uid);
      $dist_query->condition('e.is_active', 1);
      $dist_query->condition('e.start_date', $window_start, '>=');
      $dist_query->groupBy('d1.value');
      $sum = 0;
      $total = 0;
      foreach ($dist_query->execute() as $row) {
        $val = (int) $row->value;
        $n = (int) $row->n;
        if ($val >= 1 && $val <= 5) {
          $stats['distribution'][$val] = $n;
          $sum += $val * $n;
          $total += $n;
        }
      }
      $stats['rating_count'] = $total;
      $stats['avg_rating'] = $total > 0 ? $sum / $total : 0.0;

      // 3. Eligible attendees in the same window — the denominator for the
      // response rate. Only past events, counted/non-test participants.
      $elig_query = $database->select('civicrm_event', 'e');
      $elig_query->addExpression('COUNT(p.id)', 'n');
      $elig_query->innerJoin('civicrm_event__field_civi_event_instructor', 'i', 'e.id = i.entity_id AND i.deleted = 0');
      $elig_query->innerJoin('civicrm_participant', 'p', 'e.id = p.event_id');
      $elig_query->innerJoin('civicrm_participant_status_type', 'pst', 'p.status_id = pst.id');
      $elig_query->condition('i.field_civi_event_instructor_target_id', $uid);
      $elig_query->condition('e.is_active', 1);
      $elig_query->condition('e.is_template', 0);
      $elig_query->condition('e.start_date', $window_start, '>=');
      $elig_query->condition('e.start_date', $now, '<');
      $elig_query->condition('pst.is_counted', 1);
      $elig_query->condition('p.is_test', 0);
      $stats['eligible_attendees'] = (int) $elig_query->execute()->fetchField();
    }
    catch (\Exception $e) {
      \Drupal::logger('instructor_companion')->warning('Could not fetch instructor stats: @message', ['@message' => $e->getMessage()]);
    }

    return $stats;
  }

  /**
   * Builds the satisfaction details section: distribution + anonymized comments.
   *
   * @param int $uid
   *   The instructor user ID.
   * @param array $stats
   *   Pre-computed stats array from getInstructorStats().
   */
  protected function buildSatisfactionSection(int $uid, array $stats): array {
    $section = [
      '#type' => 'details',
      '#title' => $this->t('Satisfaction Details (last @m months)', ['@m' => self::SATISFACTION_WINDOW_MONTHS]),
      '#open' => FALSE,
      '#attributes' => ['class' => ['instructor-satisfaction']],
      '#weight' => -5,
    ];

    // Rating distribution as simple stacked bars.
    $max = max($stats['distribution']) ?: 1;
    $rows = [];
    for ($star = 5; $star >= 1; $star--) {
      $n = (int) $stats['distribution'][$star];
      $pct = (int) round(($n / $max) * 100);
      $label = str_repeat('★', $star) . str_repeat('☆', 5 - $star);
      $bar = '<div class="isat-bar-track"><div class="isat-bar-fill" style="width:' . $pct . '%"></div></div>';
      $rows[] = [
        ['data' => ['#markup' => '<span class="isat-stars">' . $label . '</span>']],
        ['data' => ['#markup' => $bar]],
        ['data' => $n],
      ];
    }

    $section['distribution'] = [
      '#type' => 'table',
      '#rows' => $rows,
      '#attributes' => ['class' => ['isat-distribution']],
      '#caption' => $this->t('Rating Distribution'),
    ];

    // Explainer and response rate context.
    $explainer_parts = [
      $this->t('Based on @n completed ratings.', ['@n' => $stats['rating_count']]),
    ];
    if ($stats['eligible_attendees'] > 0) {
      $pct = (int) round(($stats['rating_count'] / $stats['eligible_attendees']) * 100);
      $explainer_parts[] = $this->t(
        '@n of @total attendees responded (@pct%). Low response rates can make a few strong opinions look like a trend.',
        [
          '@n' => $stats['rating_count'],
          '@total' => $stats['eligible_attendees'],
          '@pct' => $pct,
        ]
      );
    }
    $section['explainer'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['isat-explainer']],
      'text' => ['#markup' => '<p>' . implode(' ', array_map('strval', $explainer_parts)) . '</p>'],
    ];

    // Anonymized comments.
    $comments = $this->loadAnonymizedInstructorComments($uid);
    $section['comments_heading'] = [
      '#markup' => '<h4>' . $this->t('Recent Comments (anonymized)') . '</h4>',
    ];
    $section['comments_note'] = [
      '#markup' => '<p class="isat-note"><em>'
        . $this->t('Comments are shown only after events are at least @d days old, require at least @min total comments before anything is shown, and are displayed in a randomized order with no link back to specific students or classes.', [
          '@d' => self::FEEDBACK_DELAY_DAYS,
          '@min' => self::FEEDBACK_MINIMUM_COUNT,
        ])
        . '</em></p>',
    ];

    if (is_string($comments)) {
      $section['comments'] = ['#markup' => '<p>' . $comments . '</p>'];
    }
    else {
      $section['comments'] = [
        '#theme' => 'item_list',
        '#items' => $comments,
        '#attributes' => ['class' => ['isat-comment-list']],
      ];
    }

    return $section;
  }

  /**
   * Loads anonymized free-text comments from webform_1181 submissions.
   *
   * Privacy protections mirror the facilitator dashboard:
   *  - Only events whose start date is older than FEEDBACK_DELAY_DAYS are
   *    eligible, creating time separation from the original submission.
   *  - Submission IDs, user IDs, and event IDs are dropped — only the raw
   *    comment text is kept, and sid is never exposed to the caller.
   *  - If fewer than FEEDBACK_MINIMUM_COUNT comments exist, nothing is shown.
   *  - Results are shuffled (time-seeded per-instructor) and capped at
   *    FEEDBACK_DISPLAY_LIMIT so individual classes are hard to identify.
   *
   * @return array|string
   *   An array of render-ready comment strings, or a string message.
   */
  protected function loadAnonymizedInstructorComments(int $uid) {
    $database = \Drupal::database();
    $window_start = date('Y-m-d H:i:s', strtotime('-' . self::SATISFACTION_WINDOW_MONTHS . ' months'));
    $delay_cutoff = date('Y-m-d H:i:s', strtotime('-' . self::FEEDBACK_DELAY_DAYS . ' days'));
    $comment_fields = [
      'what_was_the_most_valuable_part_of_this_event_for_you',
      'was_there_anything_that_could_have_been_improved',
      'do_you_have_any_additional_feedback_or_suggestions',
    ];

    $comments = [];
    try {
      $q = $database->select('webform_submission_data', 'd1');
      $q->addField('d1', 'value', 'comment');
      $q->addField('d1', 'name', 'field');
      $q->innerJoin('webform_submission_data', 'd2', 'd1.sid = d2.sid');
      $q->innerJoin('civicrm_event__field_civi_event_instructor', 'i', 'd2.value = i.entity_id AND i.deleted = 0');
      $q->innerJoin('civicrm_event', 'e', 'e.id = i.entity_id');
      $q->condition('d1.webform_id', 'webform_1181');
      $q->condition('d1.name', $comment_fields, 'IN');
      $q->condition('d2.name', 'event_id');
      $q->condition('i.field_civi_event_instructor_target_id', $uid);
      $q->condition('e.is_active', 1);
      $q->condition('e.start_date', $window_start, '>=');
      $q->condition('e.start_date', $delay_cutoff, '<=');
      $labels = [
        'what_was_the_most_valuable_part_of_this_event_for_you' => $this->t('Most valuable'),
        'was_there_anything_that_could_have_been_improved' => $this->t('Could improve'),
        'do_you_have_any_additional_feedback_or_suggestions' => $this->t('Additional'),
      ];
      foreach ($q->execute() as $row) {
        $text = trim((string) $row->comment);
        if ($text === '') {
          continue;
        }
        $tag = (string) ($labels[$row->field] ?? '');
        $comments[] = [
          '#markup' => '<blockquote class="isat-comment">'
            . '<span class="isat-comment-tag">' . Html::escape($tag) . '</span> '
            . nl2br(Html::escape($text))
            . '</blockquote>',
        ];
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('instructor_companion')->warning('Could not load anonymized comments: @m', ['@m' => $e->getMessage()]);
      return (string) $this->t('Comments are temporarily unavailable.');
    }

    if (count($comments) < self::FEEDBACK_MINIMUM_COUNT) {
      return (string) $this->t(
        'More delayed feedback is needed before anonymous comments can be shown (at least @min required, @have so far).',
        ['@min' => self::FEEDBACK_MINIMUM_COUNT, '@have' => count($comments)]
      );
    }

    // Shuffle deterministically-per-5-min so page caches are stable but order
    // still changes over time, further weakening any attribution guesses.
    $seed = ((int) floor(\Drupal::time()->getRequestTime() / 300)) + $uid;
    mt_srand($seed);
    shuffle($comments);
    mt_srand();

    return array_slice($comments, 0, self::FEEDBACK_DISPLAY_LIMIT);
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

    // CiviCRM stores civicrm_event.start_date in the site's local timezone
    // (not UTC). Values may arrive as "Y-m-d H:i:s" or ISO 8601
    // (for example "2026-02-15T20:00:00").
    try {
      $tz = new \DateTimeZone($site_timezone);
      $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start_date_value, $tz);
      if (!$date) {
        $date = new \DateTimeImmutable($start_date_value, $tz);
      }
      // Ensure formatting uses the site timezone even if the input string
      // carried its own offset (ISO 8601 with a trailing "Z" or "+00:00").
      $date = $date->setTimezone($tz);
    }
    catch (\Exception $e) {
      \Drupal::logger('instructor_companion')->warning('Could not parse event date "@value": @message', [
        '@value' => $start_date_value,
        '@message' => $e->getMessage(),
      ]);
      return $start_date_value;
    }

    return $date->format('D, M j, Y \a\t g:ia T');
  }

  /**
   * Identifies high-demand courses previously taught by this instructor.
   *
   * Returns courses the instructor has taught that currently have no upcoming
   * sessions, sorted by member interest (flag count) then offer frequency.
   */
  protected function getHighDemandCourses(int $uid): array {
    $database = \Drupal::database();

    // Find NIDs of courses previously taught by this user.
    $q = $database->select('civicrm_event__field_civi_event_instructor', 'i');
    $q->join('civicrm_event__field_parent_course', 'f', 'i.entity_id = f.entity_id');
    $q->fields('f', ['field_parent_course_target_id']);
    $q->condition('i.field_civi_event_instructor_target_id', $uid);
    $q->distinct();
    $nids = $q->execute()->fetchCol();

    if (empty($nids)) {
      return [];
    }

    // Courses with no upcoming sessions, at least one past run.
    $storage = $this->entityTypeManager()->getStorage('node');
    $candidate_nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->condition('nid', $nids, 'IN')
      ->condition('field_stat_upcoming', 0)
      ->condition('field_stat_runs', 0, '>')
      ->execute();

    if (empty($candidate_nids)) {
      return [];
    }

    // Sort by interest flag count DESC, then runs DESC.
    $interest_counts = $this->getCourseInterestCounts(array_values($candidate_nids));
    $nodes = $storage->loadMultiple($candidate_nids);

    uasort($nodes, function ($a, $b) use ($interest_counts) {
      $ai = $interest_counts[(int) $a->id()] ?? 0;
      $bi = $interest_counts[(int) $b->id()] ?? 0;
      if ($ai !== $bi) {
        return $bi <=> $ai;
      }
      return (int) $b->get('field_stat_runs')->value <=> (int) $a->get('field_stat_runs')->value;
    });

    return array_slice($nodes, 0, 8, TRUE);
  }

  /**
   * Returns flag_counts for course_interest for a set of node IDs.
   *
   * @param int[] $nids
   *
   * @return array<int, int>  Keyed by NID, value is interest count.
   */
  protected function getCourseInterestCounts(array $nids): array {
    if (empty($nids)) {
      return [];
    }
    try {
      $rows = \Drupal::database()
        ->select('flag_counts', 'fc')
        ->fields('fc', ['entity_id', 'count'])
        ->condition('fc.flag_id', 'course_interest')
        ->condition('fc.entity_id', $nids, 'IN')
        ->execute()
        ->fetchAllKeyed();
      return array_map('intval', $rows);
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  /**
   * Finds upcoming events where the instructor is not yet an approved badger.
   *
   * Being a badger means appearing in field_badge_issuer on the badge term —
   * a status staff grant after: earn badge → shadow a badger → supervised
   * session. Simply holding a badge_request is not sufficient.
   *
   * @param int $uid
   * @param array $upcoming_events  Keyed CiviCRM event entities.
   * @return array[] Each entry: ['event_label', 'event_date', 'badge_label'].
   */
  protected function getBadgePrerequisiteGaps(int $uid, array $upcoming_events): array {
    $gaps = [];
    foreach ($upcoming_events as $event) {
      if (!$event->hasField('field_civi_event_badges') || $event->get('field_civi_event_badges')->isEmpty()) {
        continue;
      }
      $badge_terms = $event->get('field_civi_event_badges')->referencedEntities();
      foreach ($badge_terms as $badge) {
        $issuer_uids = array_map(
          'intval',
          array_column($badge->get('field_badge_issuer')->getValue(), 'target_id')
        );
        if (!in_array($uid, $issuer_uids, TRUE)) {
          $gaps[] = [
            'event_label' => $event->label(),
            'event_date' => $this->formatEventDate((string) $event->get('start_date')->value),
            'badge_label' => $badge->label(),
          ];
        }
      }
    }
    return $gaps;
  }

  /**
   * Returns aggregated satisfaction rating from event evaluation webforms.
   *
   * Matches submissions by the CiviCRM event titles linked to this course via
   * field_parent_course. Uses webforms 'webform_26478' (Event Evaluation) and
   * 'evaluation' (Meetup Evaluation), both of which store an 'overall' score
   * (1–5) and a 'name'/'workshop' event title field.
   *
   * @return array{avg: float, count: int}
   */
  protected function getCourseFeedbackSummary(int $nid): array {
    $result = ['avg' => 0.0, 'count' => 0];
    try {
      $db = \Drupal::database();

      // Get all CiviCRM event titles linked to this course.
      $linked_ids = $db->select('civicrm_event__field_parent_course', 'pc')
        ->fields('pc', ['entity_id'])
        ->condition('pc.field_parent_course_target_id', $nid)
        ->execute()
        ->fetchCol();

      if (empty($linked_ids)) {
        return $result;
      }

      $titles = $db->select('civicrm_event', 'e')
        ->fields('e', ['title'])
        ->condition('e.id', $linked_ids, 'IN')
        ->execute()
        ->fetchCol();

      if (empty($titles)) {
        return $result;
      }

      // webform_26478 stores event title in 'workshop'; 'evaluation' uses 'name'.
      $total = 0;
      $count = 0;
      foreach ([['webform_26478', 'workshop'], ['evaluation', 'name']] as [$wid, $title_key]) {
        $sids = $db->select('webform_submission', 'ws')
          ->fields('ws', ['sid'])
          ->condition('ws.webform_id', $wid)
          ->execute()
          ->fetchCol();

        if (empty($sids)) {
          continue;
        }

        // Load data from webform_submission_data for matching submissions.
        $title_matches = $db->select('webform_submission_data', 'd')
          ->fields('d', ['sid'])
          ->condition('d.webform_id', $wid)
          ->condition('d.name', $title_key)
          ->condition('d.value', $titles, 'IN')
          ->condition('d.sid', $sids, 'IN')
          ->execute()
          ->fetchCol();

        if (empty($title_matches)) {
          continue;
        }

        $ratings = $db->select('webform_submission_data', 'r')
          ->fields('r', ['value'])
          ->condition('r.webform_id', $wid)
          ->condition('r.name', 'overall')
          ->condition('r.sid', $title_matches, 'IN')
          ->execute()
          ->fetchCol();

        foreach ($ratings as $rating) {
          $val = (int) $rating;
          if ($val >= 1 && $val <= 5) {
            $total += $val;
            $count++;
          }
        }
      }

      if ($count > 0) {
        $result = ['avg' => $total / $count, 'count' => $count];
      }
    }
    catch (\Throwable $e) {
      // Return empty on any DB error.
    }
    return $result;
  }

}
