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

    $controller_result['instructor_companion_agreement_cta'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['instructor-agreement-cta']],
      '#weight' => 50,
      'heading' => [
        '#markup' => '<h3>' . $this->t('Almost there — one more step.') . '</h3>',
      ],
      'body' => [
        '#markup' => '<p>' . $this->t('You passed the Instructor Orientation Quiz. Sign the Master Instructor Agreement and the instructor role activates immediately.') . '</p>',
      ],
      'cta' => [
        '#type' => 'link',
        '#title' => $this->t('Sign the Instructor Agreement'),
        '#url' => Url::fromRoute('entity.webform.canonical', ['webform' => 'webform_5220']),
        '#attributes' => ['class' => ['button', 'button--primary', 'button--action']],
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
