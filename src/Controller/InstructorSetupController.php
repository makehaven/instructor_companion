<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\instructor_companion\Service\InstructorSetupChecklist;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The checklist an invited instructor works through, at /instructor/setup.
 */
class InstructorSetupController extends ControllerBase {

  public function __construct(protected InstructorSetupChecklist $checklist) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('instructor_companion.setup_checklist'));
  }

  /**
   * The page. Once everything is done it is just the way to the dashboard.
   */
  public function build(Request $request): array|RedirectResponse {
    $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    $token = $request->hasSession() ? $request->getSession()->get('pass_reset_' . $user->id()) : NULL;
    $steps = $this->checklist->steps($user, is_string($token) ? $token : NULL);

    if (InstructorSetupChecklist::isComplete($steps) && $user->hasRole('instructor')) {
      $this->messenger()->addStatus($this->t('Your instructor setup is complete.'));
      return $this->redirect('instructor_companion.dashboard');
    }

    return [
      '#cache' => ['max-age' => 0],
      'intro' => [
        '#markup' => '<p class="lead">' . $this->t('Welcome to teaching at MakeHaven. Work down this list; each step takes a few minutes, and it saves your place, so you can stop and come back. Questions: <a href="mailto:education@makehaven.org">education@makehaven.org</a>.') . '</p>',
      ],
      'steps' => self::renderSteps($steps),
    ];
  }

  /**
   * The checklist as an ordered list. Shared with the dashboard.
   */
  public static function renderSteps(array $steps): array {
    $items = [];
    $next_marked = FALSE;
    foreach ($steps as $key => $step) {
      $is_next = !$step['done'] && !$next_marked && $step['locked'] === '';
      $next_marked = $next_marked || $is_next;
      $title = $step['url'] && $step['locked'] === '' && !$step['done']
        ? [
          '#type' => 'link',
          '#title' => $step['title'],
          '#url' => $step['url'],
          '#attributes' => $is_next ? ['class' => ['button', 'button--primary']] : [],
        ]
        : ['#markup' => '<strong>' . htmlspecialchars($step['title']) . '</strong>'];
      $items[] = [
        '#wrapper_attributes' => [
          'class' => array_filter([
            'instructor-setup__step',
            $step['done'] ? 'is-done' : NULL,
            $is_next ? 'is-next' : NULL,
            $step['locked'] !== '' ? 'is-locked' : NULL,
          ]),
          'data-step' => $key,
        ],
        'state' => ['#markup' => '<span class="instructor-setup__state">' . ($step['done'] ? '✓' : '○') . '</span> '],
        'title' => $title,
        'help' => ['#markup' => '<div class="instructor-setup__help">' . htmlspecialchars($step['locked'] !== '' ? $step['locked'] : $step['help']) . '</div>'],
      ];
    }
    return [
      '#theme' => 'item_list',
      '#list_type' => 'ol',
      '#items' => $items,
      '#attributes' => ['class' => ['instructor-setup']],
      '#attached' => ['library' => ['instructor_companion/setup']],
    ];
  }

}
