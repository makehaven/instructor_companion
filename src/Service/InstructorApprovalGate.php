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

  /**
   * Returns whether the user has signed the master instructor agreement.
   */
  public function hasSignedAgreement(int $uid): bool {
    $profile = $this->loadInstructorProfile($uid);
    if (!$profile || !$profile->hasField('field_instructor_agreement_date')) {
      return FALSE;
    }
    return !$profile->get('field_instructor_agreement_date')->isEmpty();
  }

  /**
   * Returns the agreement sign date (ISO string) or NULL if unsigned.
   */
  public function agreementDate(int $uid): ?string {
    $profile = $this->loadInstructorProfile($uid);
    if (!$profile || !$profile->hasField('field_instructor_agreement_date') || $profile->get('field_instructor_agreement_date')->isEmpty()) {
      return NULL;
    }
    return (string) $profile->get('field_instructor_agreement_date')->value;
  }

  /**
   * Checks whether the user holds an active Instructor Orientation badge.
   *
   * Fails open when the badge term is missing (install hook not run yet) so
   * a misconfigured environment degrades to the webform's own access checks
   * rather than locking everyone out.
   */
  public function hasOrientationBadge(int $uid): bool {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $badges = $term_storage->loadByProperties([
      'vid' => 'badges',
      'field_badge_text_id' => 'instructor_orientation',
    ]);
    if (empty($badges)) {
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

  /**
   * Returns whether the user completed onboarding (quiz badge + agreement).
   *
   * The agreement form is itself gated behind the orientation badge, so for
   * self-serve users a signed agreement implies the badge; the explicit
   * double-check covers staff-created instructors who bypassed the gate.
   */
  public function isOnboarded(int $uid): bool {
    return $this->hasSignedAgreement($uid) && $this->hasOrientationBadge($uid);
  }

}
