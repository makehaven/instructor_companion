<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\instructor_companion\Service\InstructorSetupChecklist;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "I have read this guide" button at the foot of the Guidebook.
 *
 * The guide is an ordinary policy page anyone can read; signed-in readers get
 * this button so the education team can see who has read it.
 */
class StartupGuideAcknowledgeForm extends FormBase {

  public function __construct(protected InstructorSetupChecklist $checklist) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('instructor_companion.setup_checklist'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'instructor_companion_startup_guide_ack';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $uid = (int) $this->currentUser()->id();
    $ack = $this->checklist->guideAcknowledgement($uid);
    $form['#attributes']['class'][] = 'instructor-guide-ack';
    $form['#cache'] = ['contexts' => ['user'], 'max-age' => 0];

    if ($ack) {
      $form['done'] = [
        '#markup' => '<p class="instructor-guide-ack__done">' . $this->t('✓ You confirmed reading this guide on @date.', ['@date' => date('M j, Y', (int) $ack['at'])]) . '</p>',
      ];
      return $form;
    }

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I have read the Instructor Guidebook and will follow it when I teach.'),
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm and continue'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->checklist->acknowledgeGuide((int) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t('Thanks — noted that you have read the guidebook.'));
    $form_state->setRedirect('instructor_companion.setup');
  }

}
