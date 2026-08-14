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
  protected const DASHBOARD = '/instructor/dashboard';
  protected const LOGIN = '/user/login';

  /**
   * Redirects the retired post-workshop form path somewhere usable.
   *
   * The naive redirect (straight to NEW_PATH) dead-ended: the replacement form
   * only renders when it is tied to a class via ?event_id, and it is
   * instructor-only, so an old bookmark produced "access denied" for anonymous
   * visitors and a "start from your dashboard" stub for everyone else. Neither
   * is a landing place.
   *
   * So: keep the direct hand-off only when the caller already carries an
   * event_id (the form can actually render), send signed-in users to the
   * dashboard where they pick the class, and send anonymous visitors to log in
   * with the dashboard as their destination rather than into a 403 wall.
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

    // An event_id means the new form has everything it needs to render.
    if ($request->query->has('event_id')) {
      $event->setResponse(new RedirectResponse(self::NEW_PATH . '?' . $qs, 301));
      return;
    }

    // Otherwise the caller has to choose a class first. 302, not 301: where
    // this lands depends on who is asking, so it must not be cached as
    // permanent by shared caches or the browser.
    if (\Drupal::currentUser()->isAuthenticated()) {
      $event->setResponse(new RedirectResponse(self::DASHBOARD, 302));
      return;
    }
    $event->setResponse(new RedirectResponse(
      self::LOGIN . '?destination=' . ltrim(self::DASHBOARD, '/'),
      302
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
