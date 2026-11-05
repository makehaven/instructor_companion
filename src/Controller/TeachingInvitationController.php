<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\TeachingInvitation;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The two things a member can do with the teaching invitation card.
 */
class TeachingInvitationController extends ControllerBase {

  public function __construct(protected TeachingInvitation $invitation) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('instructor_companion.teaching_invitation'));
  }

  /**
   * Records the click, so the card can be measured, then opens the page.
   */
  public function open(): RedirectResponse {
    $this->invitation->record((int) $this->currentUser()->id(), TeachingInvitation::OPENED);
    return new RedirectResponse(Url::fromRoute('instructor_companion.become_instructor')->toString());
  }

  /**
   * "Not for me": hides the card for good and returns to the hub.
   */
  public function dismiss(): RedirectResponse {
    $this->invitation->record((int) $this->currentUser()->id(), TeachingInvitation::DISMISSED);
    return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => TeachingInvitation::HUB_NID])->toString());
  }

}
