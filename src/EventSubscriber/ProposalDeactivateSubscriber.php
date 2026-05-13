<?php

namespace Drupal\instructor_companion\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flips proposal-flow civicrm_event rows to is_active=0 after the response.
 *
 * The proposal form (?propose=1) needs new events to land inactive so they
 * surface in the staff queue. CiviCRM Entity persists is_active=1 anyway,
 * because the ticketed_workshop bundle's form display omits the field — the
 * form-alter and entity hooks both get overridden by the CiviCRM sync layer.
 * Updating the row from inside the insert transaction deadlocks. So we let
 * the insert finish, queue the entity id in state, and flip the row here at
 * kernel.terminate — well after the entity transaction has committed.
 */
class ProposalDeactivateSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected StateInterface $state,
    protected Connection $database,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::TERMINATE => 'onTerminate',
    ];
  }

  public function onTerminate(TerminateEvent $event): void {
    $queue_key = 'instructor_companion.proposal_deactivate_queue';
    $ids = (array) $this->state->get($queue_key, []);
    if (empty($ids)) {
      return;
    }
    // Clear the queue immediately so a parallel request can't double-process.
    $this->state->delete($queue_key);
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $this->database->update('civicrm_event')
      ->fields(['is_active' => 0])
      ->condition('id', $ids, 'IN')
      ->execute();
    // Invalidate the entity's render/load cache so subsequent reads see 0.
    \Drupal::entityTypeManager()->getStorage('civicrm_event')->resetCache($ids);
  }

}
