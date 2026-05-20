<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Resolves whether an instructor is approved to propose or teach classes.
 *
 * The `instructor` role unlocks dashboard/profile tooling, but staff approval
 * still controls whether someone may actually propose or teach. Approval lives
 * on the instructor profile's field_instructor_status.
 */
class InstructorApprovalGate {

  public const STATUS_ACTIVE = 'active';
  public const STATUS_INACTIVE = 'inactive';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads the user's instructor profile, if any.
   */
  public function loadInstructorProfile(int $uid): ?ProfileInterface {
    if ($uid <= 0) {
      return NULL;
    }
    $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties([
      'uid' => $uid,
      'type' => 'instructor',
    ]);
    $profile = $profiles ? reset($profiles) : NULL;
    return $profile instanceof ProfileInterface ? $profile : NULL;
  }

  /**
   * Returns whether the given user is staff-approved to teach.
   */
  public function isApproved(int $uid): bool {
    $profile = $this->loadInstructorProfile($uid);
    if (!$profile) {
      return FALSE;
    }
    return $this->status($profile) === self::STATUS_ACTIVE;
  }

  /**
   * Returns a normalized instructor approval status.
   */
  public function status(ProfileInterface $profile): string {
    if (!$profile->hasField('field_instructor_status') || $profile->get('field_instructor_status')->isEmpty()) {
      return self::STATUS_INACTIVE;
    }
    $status = strtolower((string) $profile->get('field_instructor_status')->value);
    return $status === self::STATUS_ACTIVE ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
  }

}
