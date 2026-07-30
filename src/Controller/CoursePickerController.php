<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\InstructorApprovalGate;
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

    // Determine where this user is in the onboarding funnel. Onboarding no
    // longer gates proposing (proposal-first funnel; ProposalHoldManager
    // enforces it at publication instead) — the state here only drives the
    // informational notice about which onboarding steps remain.
    //   1. Has watched orientation + passed quiz → Instructor Orientation badge
    //   2. Has signed the master agreement → field_instructor_agreement_date set
    $has_orientation_badge = $this->userHasOrientationBadge((int) $current_user->id());

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
    $sort = $request->query->get('sort', 'opportunity');

    // Load the area_of_interest hierarchy as a tree. Top-level (depth 0)
    // terms are indicator-only group headings; leaf (depth 1) terms are the
    // selectable filter values.
    $term_storage = $entity_type_manager->getStorage('taxonomy_term');
    $expertise_tree = $term_storage->loadTree('area_of_interest', 0, NULL, FALSE);

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

    // Current user's own area-of-interest tids (from main profile). Used to
    // rank topic-aligned workshops above the rest and visually mark matches.
    $user_interest_tids = [];
    if (!$current_user->isAnonymous()) {
      $main_profiles = $profile_storage->loadByProperties([
        'uid' => $current_user->id(),
        'type' => 'main',
      ]);
      if ($main_profiles) {
        $main_profile = reset($main_profiles);
        if ($main_profile->hasField('field_member_interests')) {
          foreach ($main_profile->get('field_member_interests') as $item) {
            if ($item->target_id) {
              $user_interest_tids[(int) $item->target_id] = TRUE;
            }
          }
        }
      }
    }

    $nodes = $nids ? $entity_type_manager->getStorage('node')->loadMultiple($nids) : [];

    // Hide the dead-ends: courses that ran fewer than 2 times, have zero
    // member interest, and have no upcoming sessions. They're rarely revival
    // candidates and just pad the list. Set field_publicly_listed = 0 to
    // suppress one explicitly; the auto-hide just trims noise.
    $nodes = array_filter($nodes, function ($node) use ($interest_counts) {
      $nid = (int) $node->id();
      $interest = $interest_counts[$nid] ?? 0;
      $runs = (int) $node->get('field_stat_runs')->value;
      $upcoming = (int) $node->get('field_stat_upcoming')->value;
      return ($interest > 0) || ($runs >= 2) || ($upcoming > 0);
    });

    // Tier-based opportunity ranking. The intent: surface workshops that
    // NEED an instructor right now, ahead of ones already covered.
    //   Tier 0 (top) — no upcoming sessions AND (member interest OR topic match)
    //   Tier 1       — no upcoming sessions (revival pool: ran in the past, uncovered)
    //   Tier 2 (bot) — already has upcoming sessions (someone else is teaching it)
    // Within a tier: topic-match desc → interest desc → runs desc → title.
    $tier_by_nid = [];
    $topic_match_by_nid = [];
    $course_expertise_tids = [];
    foreach ($nodes as $node) {
      $nid = (int) $node->id();
      $interest = $interest_counts[$nid] ?? 0;
      $upcoming = (int) $node->get('field_stat_upcoming')->value;
      $tids = [];
      foreach ($node->get('field_instructor_expertise') as $item) {
        if ($item->target_id) {
          $tids[] = (int) $item->target_id;
        }
      }
      $course_expertise_tids[$nid] = $tids;
      $match = 0;
      foreach ($tids as $tid) {
        if (isset($user_interest_tids[$tid])) {
          $match++;
        }
      }
      $topic_match_by_nid[$nid] = $match;
      if ($upcoming > 0) {
        $tier_by_nid[$nid] = 2;
      }
      elseif ($interest > 0 || $match > 0) {
        $tier_by_nid[$nid] = 0;
      }
      else {
        $tier_by_nid[$nid] = 1;
      }
    }

    // Sort.
    uasort($nodes, function ($a, $b) use ($interest_counts, $tier_by_nid, $topic_match_by_nid, $sort) {
      $nid_a = (int) $a->id();
      $nid_b = (int) $b->id();
      if ($sort === 'opportunity') {
        $diff = ($tier_by_nid[$nid_a] ?? 2) <=> ($tier_by_nid[$nid_b] ?? 2);
        if ($diff !== 0) {
          return $diff;
        }
        $diff = ($topic_match_by_nid[$nid_b] ?? 0) <=> ($topic_match_by_nid[$nid_a] ?? 0);
        if ($diff !== 0) {
          return $diff;
        }
        $diff = ($interest_counts[$nid_b] ?? 0) <=> ($interest_counts[$nid_a] ?? 0);
        if ($diff !== 0) {
          return $diff;
        }
        $diff = (int) $b->get('field_stat_runs')->value <=> (int) $a->get('field_stat_runs')->value;
        if ($diff !== 0) {
          return $diff;
        }
      }
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
          "These are MakeHaven workshops with proven member interest or strong run history that could use another instructor. The top of the list highlights workshops that ran successfully in the past but aren't currently covered — that's where the biggest opportunity is. After proposing a session, staff will review and reach out to coordinate the details."
        ) . '</p>',
      ],
    ];

    if ($current_user->isAnonymous()) {
      $build['agreement_notice'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#markup' => '<p>' . $this->t(
          'You can browse workshops now. To propose teaching one, <a href=":login">log in</a> as a MakeHaven member or <a href=":interest">tell us you\'re interested</a> if you\'re not a member yet.',
          [
            ':login' => Url::fromRoute('user.login', [], ['query' => ['destination' => '/become-instructor/courses']])->toString(),
            ':interest' => Url::fromUserInput('/instructor')->toString(),
          ]
        ) . '</p>',
      ];
    }
    elseif (!$current_user->hasRole('member')) {
      $build['agreement_notice'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#markup' => '<p>' . $this->t(
          'You can browse workshops now, but non-members need staff review before onboarding. <a href=":interest">Tell us what you want to teach</a> and education staff will follow up.',
          [':interest' => Url::fromUserInput('/instructor')->toString()]
        ) . '</p>',
      ];
    }
    elseif (!$has_agreement) {
      if (!$has_orientation_badge) {
        $notice = $this->t(
          'You can propose a session right away — staff review every proposal. If yours is approved, you\'ll finish two quick onboarding steps before it\'s published: <strong>(1) watch the orientation video and pass the short quiz</strong>, then <strong>(2) sign the master instructor agreement</strong>. Want to knock them out now? <a href="/video-instructor">Start with the orientation video</a>.'
        );
      }
      else {
        $notice = $this->t(
          'You can propose a session right away. One onboarding step remains before an approved session is published: <strong>sign the master instructor agreement</strong>. <a href="/webform/webform_5220">Sign the agreement</a>.'
        );
      }
      $build['agreement_notice'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status']],
        '#markup' => '<p>' . $notice . '</p>',
      ];
    }

    // Filter form.
    $filter_items = ['#markup' => ''];
    $base_url = Url::fromRoute('instructor_companion.course_picker')->toString();

    // Build the topic dropdown with <optgroup> for parent categories.
    // Parents (depth 0) become disabled group headings; children (depth 1)
    // are the selectable options. Anything deeper (rare) is treated as a leaf
    // under its nearest parent.
    $filter_options = '<option value=""' . ($filter_tid === 0 ? ' selected' : '') . '>' . $this->t('All topics') . '</option>';
    $current_group_open = FALSE;
    foreach ($expertise_tree as $term) {
      if ((int) $term->depth === 0) {
        if ($current_group_open) {
          $filter_options .= '</optgroup>';
        }
        $filter_options .= '<optgroup label="' . htmlspecialchars($term->name) . '">';
        $current_group_open = TRUE;
        continue;
      }
      $selected = ((int) $term->tid === $filter_tid) ? ' selected' : '';
      $filter_options .= '<option value="' . (int) $term->tid . '"' . $selected . '>' . htmlspecialchars($term->name) . '</option>';
    }
    if ($current_group_open) {
      $filter_options .= '</optgroup>';
    }

    $sort_options = '';
    foreach ([
      'opportunity' => $this->t('Best Opportunity'),
      'interest' => $this->t('Most Member Interest'),
      'runs' => $this->t('Most Offered'),
      'new' => $this->t('Newest'),
    ] as $val => $label) {
      $selected = ($sort === $val) ? ' selected' : '';
      $sort_options .= '<option value="' . $val . '"' . $selected . '>' . $label . '</option>';
    }

    $filter_html = '<form method="get" action="' . $base_url . '" style="display:flex;gap:1em;align-items:center;flex-wrap:wrap;margin-bottom:1.5em;">'
      . '<label>' . $this->t('Topic:') . ' <select name="expertise" onchange="this.form.submit()">' . $filter_options . '</select></label>'
      . '<label>' . $this->t('Sort by:') . ' <select name="sort" onchange="this.form.submit()">' . $sort_options . '</select></label>'
      . '<button type="submit" class="button button--small">' . $this->t('Filter') . '</button>'
      . '</form>';
    $build['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['course-picker-filters']],
      '#markup' => Markup::create($filter_html),
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

      // Proposal-first funnel: any member can propose immediately. Staff
      // review each proposal, and approval is held until onboarding (quiz +
      // agreement) completes — see ProposalHoldManager. Non-members still go
      // through staff vetting via the interest form.
      if ($current_user->isAnonymous()) {
        $action_url = Url::fromRoute('user.login', [], [
          'query' => ['destination' => '/become-instructor/courses'],
        ]);
        $action_title = $this->t('Log In to Start');
        $action_class = 'button button--small';
      }
      elseif (!$current_user->hasRole('member') && !$current_user->hasRole('instructor')) {
        $action_url = Url::fromUserInput('/instructor');
        $action_title = $this->t('Tell Us You\'re Interested');
        $action_class = 'button button--small';
      }
      else {
        $action_url = Url::fromRoute('entity.civicrm_event.add_form', ['bundle' => 'civicrm_event'], [
          'query' => ['course_id' => $nid, 'propose' => 1],
        ]);
        $action_title = $this->t('Propose to Teach');
        $action_class = 'button button--primary button--small';
      }

      // Expertise tags — matches against the current user's own interests
      // are highlighted so the topic-alignment ranking is visible to the user.
      $expertise_tags = [];
      foreach ($course_expertise_tids[$nid] ?? [] as $tid) {
        $term = $term_storage->load($tid);
        if (!$term) {
          continue;
        }
        if (isset($user_interest_tids[$tid])) {
          $expertise_tags[] = '<span class="tag tag--matches-me" style="background:#d4edda;color:#155724;font-weight:600;padding:2px 6px;border-radius:3px;margin-right:4px;display:inline-block">' . htmlspecialchars($term->label()) . '</span>';
        }
        else {
          $expertise_tags[] = '<span class="tag" style="padding:2px 6px;border-radius:3px;margin-right:4px;display:inline-block;background:#f1f1f1">' . htmlspecialchars($term->label()) . '</span>';
        }
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

  /**
   * Whether the user holds an active Instructor Orientation badge.
   *
   * Mirrors AgreementAccessSubscriber::userHasOrientationBadge so the picker's
   * action button labels match what the agreement-access gate actually does.
   * Anonymous returns FALSE; the route requires login so this is mostly a
   * safety check.
   */
  private function userHasOrientationBadge(int $uid): bool {
    if (!$uid) {
      return FALSE;
    }
    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $badges = $term_storage->loadByProperties([
      'vid' => 'badges',
      'field_badge_text_id' => 'instructor_orientation',
    ]);
    if (empty($badges)) {
      // Badge term missing — install hook hasn't run. Fail open so we don't
      // block users while the system catches up; the access subscriber on
      // the agreement form will still gate them server-side.
      return TRUE;
    }
    $badge = reset($badges);
    $nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_requested.target_id', $badge->id())
      ->condition('field_badge_status', 'active')
      ->range(0, 1)
      ->execute();
    return !empty($nids);
  }

}
