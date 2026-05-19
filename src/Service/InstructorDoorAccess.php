<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Single source of truth for instructor door-badge state and provisioning.
 *
 * The "Door" badge (a badge_request node referencing the configured door
 * taxonomy term) is the credential unifi_access_sync pushes to UniFi Access —
 * an *active* request opens the building, a *pending* one does not. Instructors
 * who self-register are never members, so nothing in the member-onboarding
 * funnel grants them this badge. This service mints a *pending* request on
 * agreement signing and lets staff approve it deliberately.
 *
 * The dedup guard here is security-relevant: unifi_access_sync reacts to
 * badge_request insert/update, treating any non-active status as "remove from
 * UniFi". Creating a stray pending request for an instructor who *already*
 * holds an active door badge (the member-instructors) could revoke their
 * access on the next sync. Every caller must go through ensurePendingRequest()
 * so that guard can never drift.
 */
class InstructorDoorAccess {

  /**
   * Badge-request statuses that mean "this request is dead, ignore it".
   *
   * Mirrors assign_badge_from_quiz's loadExistingBadgeRequest() so the two
   * modules agree on what counts as an existing request.
   */
  protected const TERMINAL_STATUSES = ['duplicate', 'Rejected', 'rejected'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * The taxonomy term id of the "Door" badge.
   *
   * Read from unifi_access_sync.settings so this never drifts from the term
   * the sync actually keys on (currently tid 1519).
   */
  public function getDoorTermId(): int {
    return (int) $this->configFactory->get('unifi_access_sync.settings')->get('door_term_id');
  }

  /**
   * Loads the most recent non-terminal door badge_request for a user.
   *
   * "Non-terminal" = any status except duplicate/rejected, so an active,
   * pending, or expired request all count as "this user already has one".
   */
  public function loadDoorBadgeRequest(int $uid): ?NodeInterface {
    $door_tid = $this->getDoorTermId();
    if (!$door_tid || !$uid) {
      return NULL;
    }

    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_requested.target_id', $door_tid)
      ->condition('field_badge_status.value', self::TERMINAL_STATUSES, 'NOT IN')
      ->sort('changed', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$nids) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load((int) reset($nids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Whether the user holds an active (door-opening) badge.
   */
  public function hasActiveDoorBadge(int $uid): bool {
    $request = $this->loadDoorBadgeRequest($uid);
    return $request
      && strcasecmp((string) $request->get('field_badge_status')->value, 'active') === 0;
  }

  /**
   * Whether the user has a pending door request awaiting staff approval.
   */
  public function hasPendingDoorBadge(int $uid): bool {
    $request = $this->loadDoorBadgeRequest($uid);
    return $request
      && strcasecmp((string) $request->get('field_badge_status')->value, 'pending') === 0;
  }

  /**
   * Creates a pending door badge_request unless one already exists.
   *
   * Returns the newly created request, or NULL when nothing was created
   * (user already has a non-terminal request — including an active one, which
   * is the member-instructor case we must not disturb).
   */
  public function ensurePendingRequest(int $uid): ?NodeInterface {
    $door_tid = $this->getDoorTermId();
    if (!$door_tid) {
      $this->logger->warning('Cannot provision instructor door badge: unifi_access_sync door_term_id is not configured.');
      return NULL;
    }
    if (!$uid) {
      return NULL;
    }

    // If any non-terminal request already exists (active OR pending OR
    // expired), do nothing. This protects member-instructors who already
    // have an active door badge from a spurious pending node that would make
    // unifi_access_sync revoke them.
    if ($this->loadDoorBadgeRequest($uid)) {
      return NULL;
    }

    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'badge_request',
      'title' => 'Instructor door access request for user ' . $uid,
      'field_badge_requested' => ['target_id' => $door_tid],
      'field_badge_status' => 'pending',
      'field_member_to_badge' => ['target_id' => $uid],
    ]);
    $node->save();

    $this->logger->notice('Created pending door badge request @nid for instructor uid @uid (awaiting staff approval).', [
      '@nid' => $node->id(),
      '@uid' => $uid,
    ]);

    return $node;
  }

}
