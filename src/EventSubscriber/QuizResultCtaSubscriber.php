<?php

namespace Drupal\instructor_companion\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\quiz\Entity\QuizResult;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds a "Sign the Instructor Agreement" CTA to the quiz result page.
 *
 * Only fires when:
 *   - The result page is for the Instructor Orientation Quiz (looked up by
 *     the badge term's field_badge_quiz_reference, so we don't hard-code
 *     a quiz id here).
 *   - The user scored 100.
 *   - The user does not yet have the `instructor` role (so re-takers don't
 *     get pestered to re-sign).
 *
 * Runs at priority 50 — after assign_badge_from_quiz's QuizResultPageSubscriber
 * (priority 100) so its success message renders first, then our CTA appears
 * underneath.
 */
final class QuizResultCtaSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructor.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::VIEW => ['onView', 50]];
  }

  /**
   * Injects the agreement-signing CTA into the quiz result render array.
   */
  public function onView(ViewEvent $event): void {
    if ($this->routeMatch->getRouteName() !== 'entity.quiz_result.canonical') {
      return;
    }
    $quiz_result = $this->routeMatch->getParameter('quiz_result');
    if (!($quiz_result instanceof QuizResult)) {
      return;
    }
    $controller_result = $event->getControllerResult();
    if ($controller_result instanceof Response || !is_array($controller_result)) {
      return;
    }
    if ((int) $quiz_result->get('score')->value !== 100) {
      return;
    }
    if (in_array('instructor', $this->currentUser->getRoles(), TRUE)) {
      return;
    }

    $instructor_quiz_id = $this->getInstructorQuizId();
    if ($instructor_quiz_id === NULL) {
      return;
    }
    if ((int) $quiz_result->getQuiz()->id() !== $instructor_quiz_id) {
      return;
    }

    // Render the CTA as a banner at the very top of the result page, ahead
    // of the per-question breakdown. Negative weight beats the default
    // question-list weight of 0, and inline styles give it visible presence
    // without requiring a theme library wired in for this single banner.
    $controller_result['instructor_companion_agreement_cta'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['instructor-agreement-cta'],
        'style' => 'background:#fff7e6;border:2px solid #d4923c;border-radius:8px;padding:1.25em 1.5em;margin:0 0 1.5em 0;display:flex;align-items:center;justify-content:space-between;gap:1.5em;flex-wrap:wrap',
      ],
      '#weight' => -100,
      'text' => [
        '#type' => 'container',
        '#attributes' => ['style' => 'flex:1 1 auto;min-width:280px'],
        'heading' => [
          '#markup' => '<h2 style="margin:0 0 0.25em 0;font-size:1.4em;color:#7a4300">' . $this->t('🎉 You passed — one more step to teach.') . '</h2>',
        ],
        'body' => [
          '#markup' => '<p style="margin:0">' . $this->t('Sign the Master Instructor Agreement and the instructor role activates immediately. You can review your quiz answers below.') . '</p>',
        ],
      ],
      'cta' => [
        '#type' => 'link',
        '#title' => $this->t('Sign the Instructor Agreement →'),
        '#url' => Url::fromRoute('entity.webform.canonical', ['webform' => 'webform_5220']),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'button--action'],
          'style' => 'font-size:1.1em;padding:0.75em 1.5em;flex:0 0 auto',
        ],
      ],
    ];

    $event->setControllerResult($controller_result);
  }

  /**
   * Looks up the Instructor Orientation Quiz id via the badge term linkage.
   */
  private function getInstructorQuizId(): ?int {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $badges = $term_storage->loadByProperties([
      'vid' => 'badges',
      'field_badge_text_id' => 'instructor_orientation',
    ]);
    if (empty($badges)) {
      return NULL;
    }
    $quiz_id = (int) reset($badges)->get('field_badge_quiz_reference')->target_id;
    return $quiz_id ?: NULL;
  }

}
