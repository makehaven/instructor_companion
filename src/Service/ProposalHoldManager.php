<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Tracks approved proposals held back until the instructor finishes onboarding.
 *
 * Proposal-first funnel: members may propose sessions before signing the
 * agreement or passing the orientation quiz. Staff approval of such a proposal
 * records a "hold" instead of activating the event. When the instructor
 * completes onboarding (agreement signing or badge grant — whichever lands
 * last), the hold releases: the event goes live and both the instructor and
 * staff are notified. Holds live in state as [event_id => instructor_uid].
 */
class ProposalHoldManager {

  private const STATE_KEY = 'instructor_companion.onboarding_hold_events';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
    protected InstructorApprovalGate $approvalGate,
    protected MailManagerInterface $mailManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Records a hold for an approved-but-not-yet-onboarded proposal.
   */
  public function hold(int $event_id, int $instructor_uid): void {
    $holds = $this->state->get(self::STATE_KEY, []);
    $holds[$event_id] = $instructor_uid;
    $this->state->set(self::STATE_KEY, $holds);
    $this->logger->notice('Proposal @id approved but held pending onboarding for uid @uid.', [
      '@id' => $event_id,
      '@uid' => $instructor_uid,
    ]);
  }

  /**
   * Returns held event ids for the given instructor.
   */
  public function heldEventIds(int $instructor_uid): array {
    $holds = $this->state->get(self::STATE_KEY, []);
    return array_keys($holds, $instructor_uid, TRUE);
  }

  /**
   * Releases the user's held proposals if onboarding is now complete.
   *
   * Safe to call opportunistically (agreement signing, badge grants); no-ops
   * unless the user has holds AND has completed onboarding.
   *
   * @return array
   *   Labels of events that went live.
   */
  public function releaseFor(int $instructor_uid): array {
    $event_ids = $this->heldEventIds($instructor_uid);
    if (!$event_ids || !$this->approvalGate->isOnboarded($instructor_uid)) {
      return [];
    }

    $holds = $this->state->get(self::STATE_KEY, []);
    $released = [];
    $storage = $this->entityTypeManager->getStorage('civicrm_event');
    foreach ($event_ids as $event_id) {
      unset($holds[$event_id]);
      $event = $storage->load($event_id);
      if (!$event) {
        // Event deleted while held; just prune the record.
        continue;
      }
      if (!(int) $event->get('is_active')->value) {
        $event->set('is_active', 1);
        $event->save();
      }
      $released[] = $event->label();
      $this->notifyInstructor($event, $instructor_uid);
    }
    $this->state->set(self::STATE_KEY, $holds);

    if ($released) {
      $this->notifyStaff($instructor_uid, $released);
      $this->logger->notice('Released @n held proposal(s) for uid @uid after onboarding completed.', [
        '@n' => count($released),
        '@uid' => $instructor_uid,
      ]);
    }
    return $released;
  }

  /**
   * Sends the standard approval email now that the session is really live.
   */
  protected function notifyInstructor($event, int $instructor_uid): void {
    $instructor = $this->entityTypeManager->getStorage('user')->load($instructor_uid);
    if (!$instructor) {
      return;
    }
    $params = [
      'user_name' => $instructor->getDisplayName(),
      'event_title' => $event->label(),
      'event_link' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'start_date' => $event->get('start_date')->value
        ? date('D, F j, Y \a\t g:ia', strtotime($event->get('start_date')->value))
        : '',
    ];
    $this->mailManager->mail(
      'instructor_companion',
      'proposal_approved',
      $instructor->getEmail(),
      $instructor->getPreferredLangcode(),
      $params,
      NULL,
      TRUE
    );
  }

  /**
   * Tells staff a held session went live without further action from them.
   */
  protected function notifyStaff(int $instructor_uid, array $released_titles): void {
    $instructor = $this->entityTypeManager->getStorage('user')->load($instructor_uid);
    $config = $this->configFactory->get('instructor_companion.settings');
    $to = $config->get('notification_email') ?: $this->configFactory->get('system.site')->get('mail');
    $this->mailManager->mail(
      'instructor_companion',
      'held_proposal_released',
      $to,
      'en',
      [
        'user_name' => $instructor ? $instructor->getDisplayName() : "uid $instructor_uid",
        'released_titles' => $released_titles,
      ],
      NULL,
      TRUE
    );
  }

}
