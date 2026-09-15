<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\instructor_companion\Service\CourseInterestGroups;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tell-me-when-this-program-next-runs, for people without an account.
 *
 * Members use the Notify Me flag on the program page; this is the same list
 * for everyone else — one email field, into the program's CiviCRM group.
 */
final class CourseInterestForm extends FormBase {

  public function __construct(
    private readonly CourseInterestGroups $groups,
    private readonly AccountInterface $account,
    private readonly ?object $honeypot = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('instructor_companion.course_interest_groups'),
      $container->get('current_user'),
      $container->has('honeypot') ? $container->get('honeypot') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'instructor_companion_course_interest_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node) {
      return $form;
    }
    $form_state->set('course_nid', (int) $node->id());
    $form['#title'] = $this->t('Hear when @program next runs', ['@program' => $node->label()]);
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Leave your email and we will send you one message when the next cohort of @program is announced. No account needed.', ['@program' => $node->label()]) . '</p>',
    ];
    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#maxlength' => 64,
      '#default_value' => $this->account->isAuthenticated() ? $this->account->getDisplayName() : '',
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
      '#default_value' => $this->account->isAuthenticated() ? (string) $this->account->getEmail() : '',
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Add me to the interest list'),
        '#button_type' => 'primary',
      ],
    ];
    $form['back'] = [
      '#markup' => '<p class="small mt-3"><a href="' . $node->toUrl()->toString() . '">'
      . $this->t('Back to @program', ['@program' => $node->label()]) . '</a></p>',
    ];
    if ($this->honeypot) {
      $this->honeypot->addFormProtection($form, $form_state, ['honeypot', 'time_restriction']);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = (int) $form_state->get('course_nid');
    $email = (string) $form_state->getValue('email');
    $first = (string) $form_state->getValue('first_name');
    try {
      $cid = $this->groups->contactForEmail($email, $first, TRUE);
      if ($cid) {
        $this->groups->add($nid, $cid);
      }
    }
    catch (\Throwable $e) {
      $this->logger('instructor_companion')->error('Interest form for course @n failed: @m', [
        '@n' => $nid,
        '@m' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t(
        'Sorry, that did not save. Please email education@makehaven.org and we will add you by hand.'
      ));
      return;
    }
    $this->messenger()->addStatus($this->t('Thanks — you are on the list. We will email you when the next cohort is announced.'));
    $form_state->setRedirect('entity.node.canonical', ['node' => $nid]);
  }

}
