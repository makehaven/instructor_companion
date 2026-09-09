<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\InstructorInviteManager;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Follows (and resends) instructor agreement invite links.
 */
class InstructorInviteController extends ControllerBase {

  public function __construct(protected InstructorInviteManager $invites) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('instructor_companion.invite'));
  }

  /**
   * The link from the invite email: signs the person in, opens the agreement.
   *
   * Route: /instructor/invite/{user}/{timestamp}/{hash} (anonymous).
   * Mirrors core's one-time login link handling for the logged-in cases.
   */
  public function accept(UserInterface $user, int $timestamp, string $hash): RedirectResponse {
    $current = $this->currentUser();

    if ($current->isAuthenticated() && (int) $current->id() !== (int) $user->id()) {
      $this->messenger()->addError($this->t('You are signed in as %name. Sign out, then open the invite link again.', [
        '%name' => $current->getDisplayName(),
      ]));
      return $this->redirect('<front>');
    }

    $state = $this->invites->validate($user, $timestamp, $hash);

    switch ($state) {
      case 'signed':
        $this->messenger()->addStatus($this->t('Your instructor agreement is already on file — nothing more to sign.'));
        return $current->isAuthenticated()
          ? $this->redirect('instructor_companion.dashboard')
          : $this->redirect('user.login', [], ['query' => ['destination' => Url::fromRoute('instructor_companion.dashboard')->toString()]]);

      case 'expired':
        $this->messenger()->addWarning($this->t('This invite link has expired. Email education@makehaven.org and we will send a fresh one.'));
        return $this->redirect('instructor_companion.become_instructor');

      case 'valid':
        break;

      default:
        $this->messenger()->addError($this->t('This invite link is not valid. Email education@makehaven.org and we will send a fresh one.'));
        return $this->redirect('instructor_companion.become_instructor');
    }

    if (!$current->isAuthenticated()) {
      user_login_finalize($user);
      $this->getLogger('instructor_companion')->notice('Instructor invite accepted: uid @uid signed in via invite link.', ['@uid' => $user->id()]);
    }
    $this->invites->markAccepted($user);

    $this->messenger()->addStatus($this->t('You are signed in. Read the agreement below and sign at the bottom.'));
    return $this->redirect('entity.webform.canonical', ['webform' => 'webform_5220']);
  }

  /**
   * Staff action: resend an invite.
   *
   * Route: /admin/education/invite/{user}/resend (CSRF-protected).
   */
  public function resend(UserInterface $user): RedirectResponse {
    if ($this->invites->resend($user, $this->currentUser())) {
      $this->messenger()->addStatus($this->t('Invite resent to @mail.', ['@mail' => $user->getEmail()]));
    }
    else {
      $this->messenger()->addError($this->t('@name has no invite on record to resend — send a new one.', ['@name' => $user->getDisplayName()]));
    }
    return $this->redirect('instructor_companion.prospective_instructors');
  }

}
