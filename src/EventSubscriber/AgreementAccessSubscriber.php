<?php

namespace Drupal\instructor_companion\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gates the Master Instructor Agreement form behind the orientation badge.
 *
 * Today we only enforce the member-fast-lane half of Sub-task 6.4: a logged-in
 * member who hasn't passed the Instructor Orientation Quiz gets bounced to
 * /video-instructor with a flash message. Existing instructors bypass the
 * gate so they can re-sign if needed. The non-member tokenized invite path
 * (Tier 2) plugs in here once Sub-task 6.4 lands.
 */
final class AgreementAccessSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructor.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessengerInterface $messenger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

  /**
   * Redirects ineligible users away from the agreement form.
   *
   * The agreement form is reached two ways: directly via the webform's own
   * route (`entity.webform.canonical` with webform=webform_5220), and via
   * the wrapping node (nid 5220, bundle `webform`, with a `webform`
   * reference field pointing at webform_5220). The path
   * `/makehaven-instructor-agreement` resolves to the node route, so both
   * cases need to be checked.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    if (!$this->isAgreementRoute()) {
      return;
    }

    if ($this->currentUser->isAnonymous()) {
      // Webform's own access (member|instructor roles) blocks anonymous;
      // nothing to do here. Tier 2 will introduce the token bypass.
      return;
    }
    if (in_array('instructor', $this->currentUser->getRoles(), TRUE)) {
      // Existing instructors can re-sign without re-passing the quiz.
      return;
    }

    if ($this->userHasOrientationBadge((int) $this->currentUser->id())) {
      return;
    }

    $this->messenger->addWarning($this->t('Before signing the Instructor Agreement, please watch the orientation video and pass the Instructor Orientation Quiz. Both are quick.'));
    $event->setResponse(new RedirectResponse('/video-instructor'));
  }

  /**
   * Detects whether the current request is for the agreement form.
   */
  private function isAgreementRoute(): bool {
    $route_name = $this->routeMatch->getRouteName();
    if ($route_name === 'entity.webform.canonical') {
      $webform = $this->routeMatch->getParameter('webform');
      return $webform && $webform->id() === 'webform_5220';
    }
    if ($route_name === 'entity.node.canonical') {
      $node = $this->routeMatch->getParameter('node');
      if (!$node || $node->bundle() !== 'webform' || !$node->hasField('webform')) {
        return FALSE;
      }
      $ref = $node->get('webform')->target_id ?? '';
      return $ref === 'webform_5220';
    }
    return FALSE;
  }

  /**
   * Checks whether the user holds an active Instructor Orientation badge.
   */
  private function userHasOrientationBadge(int $uid): bool {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $badges = $term_storage->loadByProperties([
      'vid' => 'badges',
      'field_badge_text_id' => 'instructor_orientation',
    ]);
    if (empty($badges)) {
      // Defensive: badge term missing means the install hook hasn't run yet.
      // Fail open rather than blocking everyone — the webform's role gate
      // is still in front of us.
      return TRUE;
    }
    $badge = reset($badges);

    $node_storage = $this->entityTypeManager->getStorage('node');
    $nids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_requested.target_id', $badge->id())
      ->condition('field_badge_status', 'active')
      ->range(0, 1)
      ->execute();

    return !empty($nids);
  }

}
