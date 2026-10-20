<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\profile\Entity\ProfileInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills an instructor profile's empty photo and bio from what they sent us.
 *
 * The Workshop Proposal (webform_497) asks for an instructor photo and bio,
 * but only ever read them *from* the profile (prefill); nothing wrote them
 * back. A proposal usually comes before the profile exists (the agreement
 * creates it), and is often sent logged out, so the public instructor page
 * stayed blank while the photo sat in the submission (Kendall Buck,
 * 2026-10: proposal 10-01 with photo and bio, profile 10-16 empty). This
 * copies them in when the profile is created or a proposal arrives, never
 * over something already there. The member (ID card) photo is never used:
 * nobody gave it to us for a public page.
 */
class InstructorProfileFiller {

  public const PROPOSAL_WEBFORM = 'webform_497';
  public const PHOTO_ELEMENT = 'instructor_photo_25';
  public const BIO_ELEMENT = 'bio_25';
  public const EMAIL_ELEMENT = 'e_mail_address_25';
  protected const PHOTO_DIR = 'public://instructors';

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileRepositoryInterface $fileRepository,
    protected FileSystemInterface $fileSystem,
    protected LoggerInterface $logger,
  ) {}

  /**
   * What fill() would set, without writing. Keys 'photo' and 'bio' when found.
   *
   * @return array{photo?: array{fid:int, source:string}, bio?: array{text:string, sid:int}}
   *   Planned values.
   */
  public function plan(ProfileInterface $profile): array {
    $account = $profile->getOwner();
    if (!$account || $account->isAnonymous()) {
      return [];
    }
    $want_photo = $profile->hasField('field_instructor_photo') && $profile->get('field_instructor_photo')->isEmpty();
    $want_bio = $profile->hasField('field_instructor_bio') && trim((string) $profile->get('field_instructor_bio')->value) === '';
    if (!$want_photo && !$want_bio) {
      return [];
    }
    $plan = [];
    foreach ($this->proposals((int) $account->id(), (string) $account->getEmail()) as $sid => $values) {
      if ($want_photo && !isset($plan['photo']) && !empty($values[self::PHOTO_ELEMENT]) && ctype_digit((string) $values[self::PHOTO_ELEMENT])) {
        $plan['photo'] = ['fid' => (int) $values[self::PHOTO_ELEMENT], 'source' => "proposal $sid"];
      }
      if ($want_bio && !isset($plan['bio']) && trim((string) ($values[self::BIO_ELEMENT] ?? '')) !== '') {
        $plan['bio'] = ['text' => trim((string) $values[self::BIO_ELEMENT]), 'sid' => (int) $sid];
      }
    }
    return $plan;
  }

  /**
   * Fills the empty fields and saves. Returns the field names it set.
   */
  public function fill(ProfileInterface $profile): array {
    $plan = $this->plan($profile);
    $set = [];
    if (isset($plan['photo'])) {
      $fid = $this->copyToPublic($plan['photo']['fid']);
      if ($fid) {
        $profile->set('field_instructor_photo', ['target_id' => $fid]);
        $set[] = 'photo (' . $plan['photo']['source'] . ')';
      }
    }
    if (isset($plan['bio'])) {
      $profile->set('field_instructor_bio', ['value' => $plan['bio']['text'], 'format' => 'plain_text']);
      $set[] = 'bio (proposal ' . $plan['bio']['sid'] . ')';
    }
    if ($set) {
      $profile->save();
      $this->logger->notice('Instructor profile @p (@name): filled @what.', [
        '@p' => $profile->id(),
        '@name' => $profile->getOwner()->getDisplayName(),
        '@what' => implode(', ', $set),
      ]);
    }
    return $set;
  }

  /**
   * Fills the instructor profile of a proposal's sender, if they have one.
   */
  public function fillForProposal(int $uid, string $email): array {
    $account = NULL;
    if ($uid > 0) {
      $account = $this->entityTypeManager->getStorage('user')->load($uid);
    }
    elseif ($email !== '') {
      $found = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $email]);
      $account = $found ? reset($found) : NULL;
    }
    if (!$account) {
      return [];
    }
    $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties([
      'uid' => $account->id(),
      'type' => 'instructor',
    ]);
    return $profiles ? $this->fill(reset($profiles)) : [];
  }

  /**
   * Instructor profiles missing a photo or a bio.
   *
   * @return \Drupal\profile\Entity\ProfileInterface[]
   *   Keyed by profile id.
   */
  public function incompleteProfiles(): array {
    $storage = $this->entityTypeManager->getStorage('profile');
    $q = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'instructor');
    $or = $q->orConditionGroup()
      ->notExists('field_instructor_photo')
      ->notExists('field_instructor_bio');
    $ids = $q->condition($or)->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Proposal values by sid, newest first, sent by this user or this address.
   *
   * @return array<int, array<string, string>>
   *   sid => element name => value (photo, bio).
   */
  protected function proposals(int $uid, string $email): array {
    if (!$this->database->schema()->tableExists('webform_submission_data')) {
      return [];
    }
    $q = $this->database->select('webform_submission', 's');
    $q->fields('s', ['sid'])
      ->condition('s.webform_id', self::PROPOSAL_WEBFORM)
      ->orderBy('s.sid', 'DESC');
    $or = $q->orConditionGroup()->condition('s.uid', $uid);
    if ($email !== '') {
      $q->leftJoin('webform_submission_data', 'e', "e.sid = s.sid AND e.name = :email_el", [':email_el' => self::EMAIL_ELEMENT]);
      $or->condition('e.value', $email);
    }
    $sids = $q->condition($or)->distinct()->execute()->fetchCol();
    if (!$sids) {
      return [];
    }
    $out = array_fill_keys(array_map('intval', $sids), []);
    $rows = $this->database->select('webform_submission_data', 'd')
      ->fields('d', ['sid', 'name', 'value'])
      ->condition('d.sid', $sids, 'IN')
      ->condition('d.name', [self::PHOTO_ELEMENT, self::BIO_ELEMENT], 'IN')
      ->execute();
    foreach ($rows as $r) {
      $out[(int) $r->sid][(string) $r->name] = (string) $r->value;
    }
    return $out;
  }

  /**
   * Copies a (private) webform upload into the public instructor folder.
   *
   * The submission's file goes when the submission is deleted, and private
   * files do not show on a public page.
   */
  protected function copyToPublic(int $fid): ?int {
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file || !file_exists($file->getFileUri())) {
      return NULL;
    }
    $dir = self::PHOTO_DIR;
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $copy = $this->fileRepository->copy($file, $dir . '/' . $file->getFilename(), FileExists::Rename);
    $copy->setPermanent();
    $copy->save();
    return (int) $copy->id();
  }

}
