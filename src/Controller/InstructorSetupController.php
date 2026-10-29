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
      'deadline' => self::deadlineNote($this->checklist->nextClass((int) $user->id())),
      'steps' => self::renderSteps($steps),
    ];
  }

  /**
   * "Finish these before your class on …", or nothing when none is booked.
   */
  public static function deadlineNote(?array $class): array {
    if (!$class) {
      return [];
    }
    return [
      '#markup' => '<p class="instructor-setup__deadline">' . t('Finish these before you teach @title on @when.', [
        '@title' => $class['title'],
        '@when' => date('D, M j \a\t g:ia', strtotime($class['start'])),
      ]) . '</p>',
    ];
  }

  /**
   * The checklist, drawn like the post-class tasks page. Shared with the
   * dashboard.
   *
   * One look for both lists: numbered rows, a state icon, the action on the
   * right. The next step to do gets the primary button.
   */
  public static function renderSteps(array $steps): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['peh-steps', 'instructor-setup']],
      '#attached' => ['library' => ['instructor_companion/dashboard', 'instructor_companion/setup']],
    ];
    $next_marked = FALSE;
    $n = 0;
    foreach ($steps as $key => $step) {
      $n++;
      $pending = !empty($step['pending']);
      $locked = $step['locked'] !== '';
      $is_next = !$step['done'] && !$next_marked && !$locked;
      $next_marked = $next_marked || $is_next;

      if ($pending || $locked) {
        [$icon, $state] = [$pending ? '…' : '—', 'peh-step--na'];
      }
      elseif ($step['done']) {
        [$icon, $state] = ['✓', 'peh-step--done'];
      }
      else {
        [$icon, $state] = ['◷', 'peh-step--todo'];
      }

      $row = [
        '#type' => 'container',
        '#attributes' => [
          'class' => array_filter(['peh-step', $state, 'instructor-setup__step', $is_next ? 'is-next' : NULL]),
          'data-step' => $key,
        ],
        'icon' => ['#markup' => '<span class="peh-step-icon" aria-hidden="true">' . $icon . '</span>'],
        'body' => [
          '#markup' => '<div class="peh-step-body"><span class="peh-step-title">' . $n . '. ' . htmlspecialchars($step['title']) . '</span>'
          . '<span class="peh-step-detail">' . htmlspecialchars($locked ? $step['locked'] : $step['help']) . '</span></div>',
        ],
      ];
      if ($step['url'] && !$locked && !$step['done']) {
        $row['action'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['peh-step-action']],
          'link' => [
            '#type' => 'link',
            '#title' => $step['action'] ?? $step['title'],
            '#url' => $step['url'],
            '#attributes' => ['class' => ['button', 'button--small', $is_next ? 'button--primary' : 'button--secondary']],
          ],
        ];
      }
      $build[$key] = $row;
    }
    return $build;
  }

}
