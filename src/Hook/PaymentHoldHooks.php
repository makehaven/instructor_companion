<?php

namespace Drupal\instructor_companion\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\instructor_companion\Service\PostEventStatusService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Holds class hours out of the pay run until the class wrap-up is done.
 *
 * Answers xero_bills_sync's hook, so that module never depends on this one.
 */
class PaymentHoldHooks {

  public function __construct(
    #[Autowire(service: 'instructor_companion.post_event_status')]
    protected PostEventStatusService $postEventStatus,
  ) {}

  /**
   * Implements hook_xero_bills_sync_payment_hold().
   */
  #[Hook('xero_bills_sync_payment_hold')]
  public function paymentHold(EntityInterface $request): ?string {
    if ($request->bundle() !== 'payment' || !$request->hasField('field_event') || $request->get('field_event')->isEmpty()) {
      return NULL;
    }
    $payee = ($request->hasField('field_payee') && !$request->get('field_payee')->isEmpty())
      ? (int) $request->get('field_payee')->target_id
      : (method_exists($request, 'getOwnerId') ? (int) $request->getOwnerId() : 0);
    return $this->postEventStatus->paymentHoldReason((int) $request->get('field_event')->target_id, $payee);
  }

}
