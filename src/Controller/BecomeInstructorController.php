<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Universal entry point for the instructor onboarding funnel.
 *
 * One link Ashley (or anyone) can share — the page detects who's looking and
 * routes them to the right next step:
 *
 *  - Existing instructor → redirect to dashboard.
 *  - Authenticated member (no instructor role) → "Start onboarding" CTA
 *    pointing at `/video-instructor` (watch → quiz → sign).
 *  - Anonymous OR authenticated non-member → "Tell us you're interested"
 *    CTA pointing at `/instructor` (the express-interest webform). Anonymous
 *    visitors also see a small "already a member? log in" hint.
 */
class BecomeInstructorController extends ControllerBase {

  /**
   * Builds the page.
   */
  public function build(): array {
    $current_user = $this->currentUser();

    // Already an active instructor — send them straight to their dashboard.
    if ($current_user->hasRole('instructor')) {
      return $this->redirect('instructor_companion.dashboard')->send()
        ?: $this->buildRedirectBuild('instructor_companion.dashboard');
    }

    $is_anonymous = $current_user->isAnonymous();
    $is_member = !$is_anonymous && $current_user->hasRole('member');

    $build = [];

    $build['hero'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['become-instructor-hero']],
      'heading' => ['#markup' => '<h1>' . $this->t('Become a MakeHaven Instructor') . '</h1>'],
      'intro' => [
        '#markup' => '<p class="lead">' . $this->t(
          'Share your skills, grow our community, and get paid to teach what you love.
           MakeHaven instructors run workshops on everything from woodworking and welding
           to electronics, textiles, and digital fabrication.'
        ) . '</p>',
      ],
    ];

    $build['what_to_expect'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['become-instructor-details']],
      'heading' => ['#markup' => '<h2>' . $this->t('What to Expect') . '</h2>'],
      'steps' => [
        '#theme' => 'item_list',
        '#list_type' => 'ol',
        '#items' => [
          $this->t('<strong>Watch the orientation video</strong> — the same one every Makehaven member watches, so you know what your students have already been told about safety, etiquette, and how the space works.'),
          $this->t('<strong>Take the Instructor Orientation Quiz</strong> — three quick true/false questions covering the instructor-specific expectations on top of the basics.'),
          $this->t('<strong>Sign the Master Instructor Agreement</strong> — covers conduct, IP, and independent contractor status. About 5 minutes.'),
          $this->t('<strong>Propose your first session</strong> — pitch a new class or pick from existing workshops that need an instructor.'),
          $this->t('<strong>Teach &amp; get paid</strong> — we handle registration and marketing; you focus on a great experience.'),
        ],
      ],
    ];

    $build['teaching_options'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['teaching-options']],
      'heading' => ['#markup' => '<h2>' . $this->t('What Could You Teach?') . '</h2>'],
      'body' => [
        '#markup' => '<p>' . $this->t(
          'You can propose a brand-new class you\'ve designed, or volunteer to run an existing
           Makehaven workshop that\'s currently without an instructor. Once you\'re onboarded,
           your dashboard will show high-demand courses that members are interested in and
           that need someone to teach them.'
        ) . '</p>',
      ],
    ];

    if ($is_member) {
      // Tier 1 — member fast lane. Skip the staff vetting; go straight to the
      // orientation video and quiz.
      $build['cta'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['become-instructor-cta']],
        'heading' => ['#markup' => '<h2>' . $this->t('Ready to Get Started?') . '</h2>'],
        'body' => [
          '#markup' => '<p>' . $this->t(
            'Since you\'re already a Makehaven member, you can start onboarding now —
             watch the orientation video, take the short quiz, and sign the Instructor
             Agreement. About 10 minutes total.'
          ) . '</p>',
        ],
        'button' => [
          '#type' => 'link',
          '#title' => $this->t('Start instructor onboarding'),
          '#url' => Url::fromUserInput('/video-instructor'),
          '#attributes' => ['class' => ['button', 'button--primary', 'button--large']],
        ],
      ];
    }
    else {
      // Anonymous OR authenticated-but-not-a-member. Both go through the
      // staff-vetted route: express interest, meet with staff, then get
      // invited to onboard. (Sub-task 6.4 will tokenize that invite.)
      $build['cta'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['become-instructor-cta']],
        'heading' => ['#markup' => '<h2>' . $this->t('Ready to Get Started?') . '</h2>'],
        'body' => [
          '#markup' => '<p>' . $this->t(
            'Tell us a bit about yourself and what you\'d like to teach. Education staff
             will review your interest, set up a quick meeting, and walk you through
             onboarding.'
          ) . '</p>',
        ],
        'button' => [
          '#type' => 'link',
          '#title' => $this->t('Tell us you\'re interested'),
          '#url' => Url::fromUserInput('/instructor'),
          '#attributes' => ['class' => ['button', 'button--primary', 'button--large']],
        ],
      ];

      if ($is_anonymous) {
        $build['cta']['member_hint'] = [
          '#markup' => '<p class="become-instructor-member-hint"><em>' . $this->t(
            'Already a Makehaven member? <a href=":login">Log in</a> to start onboarding directly.',
            [':login' => Url::fromRoute('user.login', [], ['query' => ['destination' => '/become-instructor']])->toString()]
          ) . '</em></p>',
        ];
      }
    }

    $build['catalog'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['become-instructor-catalog']],
      'heading' => ['#markup' => '<h2>' . $this->t('Not Sure What to Teach?') . '</h2>'],
      'body' => [
        '#markup' => '<p>' . $this->t(
          'Browse existing Makehaven workshops — filtered by topic — to find one you could lead.
           You can also propose an entirely new class once you\'re onboarded.'
        ) . '</p>',
      ],
      'button' => [
        '#type' => 'link',
        '#title' => $this->t('Browse Workshops Available to Teach'),
        '#url' => Url::fromRoute('instructor_companion.course_picker'),
        '#attributes' => ['class' => ['button', 'button--secondary']],
      ],
    ];

    return $build;
  }

  /**
   * Fallback render array redirect (for cases where send() is not available).
   */
  protected function buildRedirectBuild(string $route): array {
    return [
      '#markup' => '<meta http-equiv="refresh" content="0;url=' .
        Url::fromRoute($route)->toString() . '">',
    ];
  }

}
