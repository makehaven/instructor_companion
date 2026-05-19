<?php

namespace Drupal\instructor_companion\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Retires Ashley's old post-workshop evaluation form.
 *
 * `instructor_feedback` replaces `post_workshop_instructor_evaluat`. The old
 * form had no event linkage so its submissions can't gate the post-event
 * flow. Anyone (or any bookmark / email link) hitting the old path is sent
 * to the new per-event form, query string preserved.
 */
class PostWorkshopRedirectSubscriber implements EventSubscriberInterface {

  protected const OLD_PATH = '/form/post-workshop-instructor-evaluat';
  protected const NEW_PATH = '/form/instructor-feedback';

  /**
   * Redirects the retired post-workshop form path to the new per-event form.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if (rtrim($request->getPathInfo(), '/') !== self::OLD_PATH) {
      return;
    }
    $qs = $request->getQueryString();
    $event->setResponse(new RedirectResponse(
      self::NEW_PATH . ($qs ? '?' . $qs : ''),
      301
    ));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority above routing (32) so the old path never resolves first.
    return [KernelEvents::REQUEST => [['onRequest', 40]]];
  }

}
