<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Course browse page for prospective instructors.
 *
 * Shows publicly-listed courses with filters by expertise area and sort options.
 * Aspiring instructors can identify a course they want to teach before or after
 * signing the base agreement.
 *
 * Route: /become-instructor/courses
 */
class CoursePickerController extends ControllerBase {

  /**
   * Builds the course picker page.
   */
  public function build(Request $request): array {
    $current_user = $this->currentUser();
    $db = \Drupal::database();
    $entity_type_manager = $this->entityTypeManager();

    // Determine if this user has signed the instructor agreement.
    $profile_storage = $entity_type_manager->getStorage('profile');
    $has_agreement = FALSE;
    $profiles = $profile_storage->loadByProperties([
      'uid' => $current_user->id(),
      'type' => 'instructor',
    ]);
    if (!empty($profiles)) {
      $profile = reset($profiles);
      $has_agreement = !$profile->get('field_instructor_agreement_date')->isEmpty();
    }

    // Active filter: taxonomy term ID from query string.
    $filter_tid = (int) $request->query->get('expertise', 0);
    $sort = $request->query->get('sort', 'interest');

    // Load all area_of_interest terms for the filter dropdown.
    $term_storage = $entity_type_manager->getStorage('taxonomy_term');
    $expertise_terms = $term_storage->loadByProperties(['vid' => 'area_of_interest']);
    usort($expertise_terms, fn($a, $b) => strcmp($a->label(), $b->label()));

    // Build the course query.
    $query = $entity_type_manager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'course')
      ->condition('status', 1)
      ->condition('field_publicly_listed', 1);

    if ($filter_tid > 0) {
      $query->condition('field_instructor_expertise', $filter_tid);
    }

    $nids = $query->execute();

    // Load interest counts for all matching courses.
    $interest_counts = [];
    if (!empty($nids)) {
      try {
        $rows = $db->select('flag_counts', 'fc')
          ->fields('fc', ['entity_id', 'count'])
          ->condition('fc.flag_id', 'course_interest')
          ->condition('fc.entity_id', array_values($nids), 'IN')
          ->execute()
          ->fetchAllKeyed();
        $interest_counts = array_map('intval', $rows);
      }
      catch (\Exception $e) {
        // Flag module may not be configured.
      }
    }

    $nodes = $nids ? $entity_type_manager->getStorage('node')->loadMultiple($nids) : [];

    // Sort.
    uasort($nodes, function ($a, $b) use ($interest_counts, $sort) {
      $nid_a = (int) $a->id();
      $nid_b = (int) $b->id();
      if ($sort === 'interest') {
        $diff = ($interest_counts[$nid_b] ?? 0) <=> ($interest_counts[$nid_a] ?? 0);
        if ($diff !== 0) {
          return $diff;
        }
      }
      if ($sort === 'runs' || $sort === 'interest') {
        $diff = (int) $b->get('field_stat_runs')->value <=> (int) $a->get('field_stat_runs')->value;
        if ($diff !== 0) {
          return $diff;
        }
      }
      if ($sort === 'new') {
        $diff = (int) $b->id() <=> (int) $a->id();
        if ($diff !== 0) {
          return $diff;
        }
      }
      return strcmp($a->label(), $b->label());
    });

    $build = [];

    // Page intro.
    $build['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['course-picker-intro']],
      'heading' => ['#markup' => '<h2>' . $this->t('Browse Existing Workshops to Teach') . '</h2>'],
      'body' => [
        '#markup' => '<p>' . $this->t(
          'These are MakeHaven workshops that members are interested in.
           You can propose to teach any of them — either as a returning run of an existing class
           or as your first time leading it. After proposing a session, staff will review and
           reach out to coordinate the details.'
        ) . '</p>',
      ],
    ];

    if (!$has_agreement) {
      $build['agreement_notice'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#markup' => '<p>' . $this->t(
          'You need to sign the base instructor agreement before proposing a session.
           You can browse courses now, but the "Propose to Teach" button will take you
           to the agreement first. <a href="/webform/webform_5220">Sign the agreement</a>.'
        ) . '</p>',
      ];
    }

    // Filter form.
    $filter_items = ['#markup' => ''];
    $base_url = Url::fromRoute('instructor_companion.course_picker')->toString();

    $filter_options = '<option value=""' . ($filter_tid === 0 ? ' selected' : '') . '>' . $this->t('All topics') . '</option>';
    foreach ($expertise_terms as $term) {
      $selected = ((int) $term->id() === $filter_tid) ? ' selected' : '';
      $filter_options .= '<option value="' . $term->id() . '"' . $selected . '>' . htmlspecialchars($term->label()) . '</option>';
    }

    $sort_options = '';
    foreach ([
      'interest' => $this->t('Most Member Interest'),
      'runs' => $this->t('Most Offered'),
      'new' => $this->t('Newest'),
    ] as $val => $label) {
      $selected = ($sort === $val) ? ' selected' : '';
      $sort_options .= '<option value="' . $val . '"' . $selected . '>' . $label . '</option>';
    }

    $build['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['course-picker-filters']],
      '#markup' => '<form method="get" action="' . $base_url . '" style="display:flex;gap:1em;align-items:center;flex-wrap:wrap;margin-bottom:1.5em;">'
        . '<label>' . $this->t('Topic:') . ' <select name="expertise" onchange="this.form.submit()">' . $filter_options . '</select></label>'
        . '<label>' . $this->t('Sort by:') . ' <select name="sort" onchange="this.form.submit()">' . $sort_options . '</select></label>'
        . '<noscript><button type="submit">' . $this->t('Filter') . '</button></noscript>'
        . '</form>',
    ];

    // Build the course table rows.
    $rows = [];
    foreach ($nodes as $node) {
      $nid = (int) $node->id();
      $interest = $interest_counts[$nid] ?? 0;
      $runs = (int) $node->get('field_stat_runs')->value;
      $last_run = $node->get('field_stat_last_run')->value
        ? date('M Y', strtotime($node->get('field_stat_last_run')->value))
        : $this->t('Never run');
      $upcoming = (int) $node->get('field_stat_upcoming')->value;

      $interest_cell = $interest > 0
        ? $interest . ' ' . ($interest === 1 ? $this->t('person') : $this->t('people'))
        : '—';

      $upcoming_cell = $upcoming > 0
        ? '<span style="color:#c0392b">' . $this->t('@n upcoming — another instructor is scheduled', ['@n' => $upcoming]) . '</span>'
        : '<span style="color:#27ae60">' . $this->t('No upcoming sessions') . '</span>';

      // Action button — goes to agreement if not signed, proposal if signed.
      if ($has_agreement) {
        $action_url = Url::fromRoute('entity.civicrm_event.add_form', ['bundle' => 'civicrm_event'], [
          'query' => ['course_id' => $nid, 'propose' => 1],
        ]);
        $action_title = $this->t('Propose to Teach');
        $action_class = 'button button--primary button--small';
      }
      else {
        $action_url = Url::fromRoute('entity.webform.canonical', ['webform' => 'webform_5220']);
        $action_title = $this->t('Sign Agreement First');
        $action_class = 'button button--small';
      }

      // Expertise tags.
      $expertise_tags = [];
      foreach ($node->get('field_instructor_expertise')->referencedEntities() as $term) {
        $expertise_tags[] = '<span class="tag">' . htmlspecialchars($term->label()) . '</span>';
      }

      $rows[] = [
        'title' => [
          'data' => [
            '#type' => 'link',
            '#title' => $node->label(),
            '#url' => $node->toUrl(),
          ],
        ],
        'topics' => ['data' => ['#markup' => implode(' ', $expertise_tags) ?: '—']],
        'interest' => $interest_cell,
        'runs' => $runs > 0 ? $this->t('@n runs, last @date', ['@n' => $runs, '@date' => $last_run]) : $this->t('New — never run'),
        'upcoming' => ['data' => ['#markup' => (string) $upcoming_cell]],
        'actions' => [
          'data' => [
            '#type' => 'link',
            '#title' => $action_title,
            '#url' => $action_url,
            '#attributes' => ['class' => explode(' ', $action_class)],
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'title' => $this->t('Workshop'),
        'topics' => $this->t('Topics'),
        'interest' => $this->t('Member Interest'),
        'runs' => $this->t('History'),
        'upcoming' => $this->t('Status'),
        'actions' => $this->t(''),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No workshops found for the selected topic.'),
      '#caption' => $this->t('@count workshops', ['@count' => count($rows)]),
    ];

    return $build;
  }

}
