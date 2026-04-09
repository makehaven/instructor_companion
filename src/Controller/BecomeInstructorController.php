<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Landing page for members who want to become instructors.
 *
 * Handles three states:
 *  - Already has instructor role → redirect to dashboard.
 *  - Already signed agreement (profile exists with date) → show status.
 *  - Neither → show info + link to agreement webform.
 */
class BecomeInstructorController extends ControllerBase {

  /**
   * Builds the page.
   */
  public function build(): array {
    $current_user = $this->currentUser();

    // Already an active instructor — send to dashboard.
    if ($current_user->hasRole('instructor')) {
      return $this->redirect('instructor_companion.dashboard')->send()
        ?: $this->buildRedirectBuild('instructor_companion.dashboard');
    }

    // Check for existing instructor profile.
    $profile_storage = $this->entityTypeManager()->getStorage('profile');
    $profiles = $profile_storage->loadByProperties([
      'uid' => $current_user->id(),
      'type' => 'instructor',
    ]);

    $has_profile = !empty($profiles);
    $has_agreement = FALSE;
    if ($has_profile) {
      $profile = reset($profiles);
      $has_agreement = !$profile->get('field_instructor_agreement_date')->isEmpty();
    }

    $build = [];

    // Hero section.
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

    if ($has_agreement) {
      // Signed but awaiting staff approval.
      $build['status'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status']],
        '#markup' => '<p><strong>' . $this->t('Your agreement is on file.') . '</strong> ' .
          $this->t('Staff will review your application and contact you to discuss next steps. Questions? Email') .
          ' <a href="mailto:education@makehaven.org">education@makehaven.org</a>.</p>',
      ];
    }
    else {
      // Not yet signed — show what to expect, then CTA.
      $build['what_to_expect'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['become-instructor-details']],
        'heading' => ['#markup' => '<h2>' . $this->t('What to Expect') . '</h2>'],
        'steps' => [
          '#theme' => 'item_list',
          '#list_type' => 'ol',
          '#items' => [
            $this->t('<strong>Sign the base instructor agreement</strong> — covers conduct, IP, and independent contractor status. Takes about 5 minutes.'),
            $this->t('<strong>Staff review</strong> — Education staff will reach out to discuss your background, interests, and a potential first class.'),
            $this->t('<strong>Propose your first session</strong> — Either pitch a new class idea or pick from our existing catalog of workshops that need instructors.'),
            $this->t('<strong>Teach &amp; get paid</strong> — We handle registration and marketing. You focus on delivering a great experience.'),
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
             MakeHaven workshop that is currently without an instructor. After approval, your
             dashboard will show high-demand courses that members are interested in and that
             need someone to teach them.'
          ) . '</p>',
        ],
      ];

      $build['cta'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['become-instructor-cta']],
        'heading' => ['#markup' => '<h2>' . $this->t('Ready to Get Started?') . '</h2>'],
        'body' => [
          '#markup' => '<p>' . $this->t(
            'The first step is signing the base instructor agreement. Read it carefully — it covers
             your obligations, payment terms, IP ownership, and liability. Once signed, staff will
             follow up within a few business days.'
          ) . '</p>',
        ],
        'button' => [
          '#type' => 'link',
          '#title' => $this->t('Sign the Instructor Agreement'),
          '#url' => Url::fromRoute('entity.webform.canonical', ['webform' => 'webform_5220']),
          '#attributes' => ['class' => ['button', 'button--primary', 'button--large']],
        ],
      ];
    }

    // Browse existing workshops CTA.
    $build['catalog'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['become-instructor-catalog']],
      'heading' => ['#markup' => '<h2>' . $this->t('Not Sure What to Teach?') . '</h2>'],
      'body' => [
        '#markup' => '<p>' . $this->t(
          'Browse existing MakeHaven workshops — filtered by topic — to find one you could lead.
           You can also propose an entirely new class once your agreement is approved.'
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
