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
    $build = [];

    // 1. Dashboard Header / Toolkit
    $build['toolkit'] = [
      '#type' => 'details',
      '#title' => $this->t('Instructor Toolkit & Resources'),
      '#open' => FALSE,
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
        $toolkit_items[] = Link::fromTextAndUrl($label, $url);
      }
    }

    if (!empty($toolkit_items)) {
      $build['toolkit']['links'] = [
        '#theme' => 'item_list',
        '#items' => $toolkit_items,
      ];
    }

    // 2. Profile Check
    // Check if the user has a filled-out 'instructor' profile.
    $profile_storage = $this->entityTypeManager()->getStorage('profile');
    $profile_ids = $profile_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $current_user->id())
      ->condition('type', 'instructor')
      ->execute();
    $profiles = $profile_storage->loadMultiple($profile_ids);

    if (empty($profiles)) {
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

    // 3. Upcoming Classes Table
    $header = [
      'date' => $this->t('Date'),
      'title' => $this->t('Class'),
      'enrolled' => $this->t('Enrolled'),
      'actions' => $this->t('Actions'),
    ];

    $rows = [];

    try {
      $storage = $this->entityTypeManager()->getStorage('civicrm_event');
      $query = $storage->getQuery()->accessCheck(FALSE);
      $query->condition('field_civi_event_instructor', $current_user->id());

      // Filter for future events (or recent past).
      // Note: CiviCRM dates are usually Y-m-d H:i:s.
      $query->condition('start_date', date('Y-m-d H:i:s'), '>=');
      $query->sort('start_date', 'ASC');
      // Limit to next 20 events.
      $query->range(0, 20);

      $ids = $query->execute();
      $events = $storage->loadMultiple($ids);

      foreach ($events as $event) {
        $event_id = $event->id();

        // Calculate Enrollment
        // Note: This relies on CiviCRM entity exposing 'participants' or similar count.
        // If not available on the entity, we might need a separate query.
        // For now, we will try to use the max_participants field vs a count query.
        // We'll leave the count calculation as a TODO or simple placeholder if expensive.
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
        // @todo Implement actual count query
          'enrolled' => "? / $capacity",
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

    $parsed = parse_url($value);
    if (!empty($parsed['scheme'])) {
      return Url::fromUri($value);
    }

    return Url::fromUserInput($value);
  }

}
