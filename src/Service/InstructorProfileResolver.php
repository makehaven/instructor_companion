<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Resolves an "instructor context" for a user — the set of fields we can
 * prefill onto the Workshop Proposal form from existing profile data.
 *
 * Returns strings (or NULL) so the form alter can decide whether to hide
 * fields or show them pre-populated. Kept small and pure so it's unit-
 * testable without the full Drupal bootstrap.
 */
class InstructorProfileResolver {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns an associative array of prefill values, or an empty array if
   * the user has no usable instructor context.
   *
   * Keys: name, email, phone, bio, compensation, photo_fid, has_profile.
   */
  public function getPrefillForUser(AccountInterface $account): array {
    if ($account->isAnonymous()) {
      return [];
    }

    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$user instanceof UserInterface) {
      return [];
    }

    $out = [
      'has_profile' => FALSE,
      'name' => $this->userDisplayName($user),
      'email' => $user->getEmail() ?: NULL,
      'phone' => $this->firstValue($user, 'field_phone_primary')
        ?? $this->firstValue($user, 'field_phone'),
      'bio' => NULL,
      'compensation' => NULL,
      'photo_fid' => NULL,
    ];

    $profile = $this->loadInstructorProfile((int) $user->id());
    if ($profile) {
      $out['has_profile'] = TRUE;
      $bio = $this->firstValue($profile, 'field_instructor_bio');
      if ($bio !== NULL) {
        $out['bio'] = strip_tags((string) $bio);
      }
      $rate = $this->firstValue($profile, 'field_instructor_rate');
      if ($rate !== NULL && $rate !== '') {
        $out['compensation'] = '$' . $rate . '/hour (from saved profile)';
      }
      $photo_fid = $this->firstValue($profile, 'field_instructor_photo');
      if ($photo_fid !== NULL && $photo_fid !== '') {
        $out['photo_fid'] = (int) $photo_fid;
      }
    }

    return $out;
  }

  /**
   * Loads the first instructor profile for a uid, or NULL.
   */
  protected function loadInstructorProfile(int $uid) {
    if (!$this->entityTypeManager->hasDefinition('profile')) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('profile');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'instructor')
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    return $storage->load(reset($ids));
  }

  /**
   * Returns a user's preferred display name, falling back to account name.
   */
  protected function userDisplayName(UserInterface $user): ?string {
    $first = $this->firstValue($user, 'field_first_name');
    $last = $this->firstValue($user, 'field_last_name');
    if ($first && $last) {
      return trim("$first $last");
    }
    return $user->getDisplayName() ?: NULL;
  }

  /**
   * Returns the first scalar value of a field, or NULL if missing/empty.
   */
  protected function firstValue($entity, string $field_name): ?string {
    if (!method_exists($entity, 'hasField') || !$entity->hasField($field_name)) {
      return NULL;
    }
    $item = $entity->get($field_name);
    if ($item->isEmpty()) {
      return NULL;
    }
    $first = $item->first();
    if (!$first) {
      return NULL;
    }
    $value = $first->value ?? ($first->target_id ?? NULL);
    return $value === NULL ? NULL : (string) $value;
  }

}
