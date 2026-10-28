<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\InstructorSetupChecklist;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * While the Guidebook is unpublished for review, its link says "coming soon".
 *
 * Old emails and bookmarks carry /instructor-handbook; without this they
 * answer Access denied. Staff who can see unpublished pages still get the
 * page itself.
 */
class GuidebookComingSoonSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected RouteMatchInterface $routeMatch,
    protected InstructorSetupChecklist $checklist,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Ahead of the default HTML exception page.
    return [KernelEvents::EXCEPTION => ['onException', 100]];
  }

  /**
   * Sends a denied request for the pending Guidebook to the notice.
   */
  public function onException(ExceptionEvent $event): void {
    if (!$event->getThrowable() instanceof AccessDeniedHttpException
      || $this->routeMatch->getRouteName() !== 'entity.node.canonical') {
      return;
    }
    $node = $this->routeMatch->getParameter('node');
    $pending = $this->checklist->pendingGuideId();
    if ($pending === 0 || !$node instanceof NodeInterface || (int) $node->id() !== $pending) {
      return;
    }
    $response = new RedirectResponse(Url::fromRoute('instructor_companion.guidebook_coming_soon')->toString());
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    $event->setResponse($response);
  }

}
