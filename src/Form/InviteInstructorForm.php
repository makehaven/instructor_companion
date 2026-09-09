<?php

namespace Drupal\instructor_companion\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\InstructorInviteManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Staff form: invite a vetted person to sign the instructor agreement.
 *
 * Route: /admin/education/invite. This is the "I've met them, get them in the
 * system" action — the account is created if needed and the person gets one
 * email with a link straight into the agreement.
 */
class InviteInstructorForm extends FormBase {

  public function __construct(protected InstructorInviteManager $invites) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('instructor_companion.invite'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'instructor_companion_invite_instructor';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t(
        'For someone you have already talked to and want teaching. They get one
         email with a personal link that signs them in and opens the instructor
         agreement — no registration form, no password to set first. Signing
         creates their instructor profile, grants the Instructor role, opens
         their dashboard, and (for non-members) queues building access for
         your approval. The link works for 14 days; you can resend it from
         <a href=":prospective">Prospective Instructors</a>.',
        [':prospective' => Url::fromRoute('instructor_companion.prospective_instructors')->toString()]
      ) . '</p>',
    ];

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full name'),
      '#required' => TRUE,
      '#maxlength' => 100,
      '#description' => $this->t('Used to greet them and, if they have no account yet, to name it.'),
    ];

    $form['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
      '#description' => $this->t('If an account already exists with this address — member or not — the invite goes to that account.'),
    ];

    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Personal note (optional)'),
      '#rows' => 3,
      '#description' => $this->t('Added to the email under your name — "Looking forward to the October welding session", that sort of thing.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send invite'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $result = $this->invites->invite(
      (string) $form_state->getValue('name'),
      (string) $form_state->getValue('mail'),
      $this->currentUser(),
      trim((string) $form_state->getValue('note')),
    );

    $user = $result['user'];
    $name = $user->getDisplayName();
    if (!$result['sent']) {
      $this->messenger()->addError($this->t('The invite for @name could not be emailed. The account exists; try Resend from Prospective Instructors, and check the mail log.', ['@name' => $name]));
    }
    elseif ($result['created']) {
      $this->messenger()->addStatus($this->t('Invite sent to @mail. A new account was created for @name; they will hold the Instructor role as soon as they sign.', [
        '@mail' => $user->getEmail(),
        '@name' => $name,
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Invite sent to @mail (existing account: @name).', [
        '@mail' => $user->getEmail(),
        '@name' => $name,
      ]));
    }

    $form_state->setRedirect('instructor_companion.prospective_instructors');
  }

}
