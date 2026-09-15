<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * One CiviCRM mailing-list group per program: "who wants to hear about this".
 *
 * JR (2026-09-14): a group makes sense — the coordinator should be able to
 * reach out and invite people when a program is going to run. So every
 * program course gets a CiviCRM group named after it, and two things feed it:
 * the "Notify Me" flag on the program page (members) and the no-account
 * interest form (anyone with an email). The coordinator opens the group in
 * CiviCRM and mails it; the follower notice also reads it.
 *
 * Only `program` courses get a group. Workshops keep the flag alone — one
 * group per workshop would be seventy groups nobody asked for.
 */
final class CourseInterestGroups {

  public const NAME_PREFIX = 'program_interest_';
  public const TITLE_PREFIX = 'Program interest: ';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The CiviCRM group name for a course node (unique, stable).
   */
  public static function groupName(int $nid): string {
    return self::NAME_PREFIX . $nid;
  }

  /**
   * The CiviCRM group title for a course.
   */
  public static function groupTitle(string $course_title): string {
    return self::TITLE_PREFIX . trim($course_title);
  }

  /**
   * Whether a course node takes an interest group.
   */
  public function appliesTo(int $nid): bool {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    return $node && $node->bundle() === 'course'
      && $node->hasField('field_course_type')
      && $node->get('field_course_type')->value === 'program';
  }

  /**
   * Finds (or, when $create, makes) the group for a course. Returns its id.
   */
  public function groupFor(int $nid, bool $create = TRUE): ?int {
    $this->civi();
    $name = self::groupName($nid);
    $existing = \civicrm_api4('Group', 'get', [
      'select' => ['id'],
      'where' => [['name', '=', $name]],
      'checkPermissions' => FALSE,
    ])->first();
    if ($existing) {
      return (int) $existing['id'];
    }
    if (!$create) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node) {
      return NULL;
    }
    $created = \civicrm_api4('Group', 'create', [
      'values' => [
        'name' => $name,
        'title' => self::groupTitle($node->label()),
        'description' => 'People who asked to hear when ' . $node->label() . ' next runs. '
        . 'Fed automatically by the website (the Notify Me button and the interest form on the program page); '
        . 'the coordinator mails this group when a cohort is scheduled. '
        . 'Removing someone here does not stop the website re-adding them if they follow again.',
        'group_type:name' => ['Mailing List'],
        'visibility' => 'User and User Admin Only',
        'is_active' => TRUE,
        'source' => 'instructor_companion course ' . $nid,
      ],
      'checkPermissions' => FALSE,
    ])->first();
    $this->logger->notice('Created CiviCRM interest group @g for course @n.', [
      '@g' => $created['id'] ?? '?',
      '@n' => $nid,
    ]);
    return isset($created['id']) ? (int) $created['id'] : NULL;
  }

  /**
   * The CiviCRM contact linked to a Drupal account, if any.
   */
  public function contactForUser(int $uid): ?int {
    if (!$this->database->schema()->tableExists('civicrm_uf_match')) {
      return NULL;
    }
    $cid = $this->database->select('civicrm_uf_match', 'm')
      ->fields('m', ['contact_id'])
      ->condition('m.uf_id', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $cid ? (int) $cid : NULL;
  }

  /**
   * The contact for an email address, created as an Individual if unknown.
   */
  public function contactForEmail(string $email, string $first_name = '', bool $create = TRUE): ?int {
    $this->civi();
    $email = trim(mb_strtolower($email));
    if ($email === '') {
      return NULL;
    }
    $hit = \civicrm_api4('Email', 'get', [
      'select' => ['contact_id'],
      'where' => [['email', '=', $email], ['contact_id.is_deleted', '=', FALSE]],
      'orderBy' => ['is_primary' => 'DESC', 'id' => 'ASC'],
      'limit' => 1,
      'checkPermissions' => FALSE,
    ])->first();
    if ($hit) {
      return (int) $hit['contact_id'];
    }
    if (!$create) {
      return NULL;
    }
    $contact = \civicrm_api4('Contact', 'create', [
      'values' => [
        'contact_type' => 'Individual',
        'first_name' => trim($first_name),
        'source' => 'Website program interest form',
      ],
      'checkPermissions' => FALSE,
    ])->first();
    $cid = (int) $contact['id'];
    \civicrm_api4('Email', 'create', [
      'values' => [
        'contact_id' => $cid,
        'email' => $email,
        'is_primary' => TRUE,
        'location_type_id:name' => 'Home',
      ],
      'checkPermissions' => FALSE,
    ]);
    return $cid;
  }

  /**
   * Adds a contact to the course's group (idempotent). Returns TRUE if added.
   */
  public function add(int $nid, int $contact_id): bool {
    $gid = $this->groupFor($nid, TRUE);
    if (!$gid) {
      return FALSE;
    }
    return $this->setStatus($gid, $contact_id, 'Added');
  }

  /**
   * Marks a contact removed from the course's group, if it exists.
   */
  public function remove(int $nid, int $contact_id): bool {
    $gid = $this->groupFor($nid, FALSE);
    if (!$gid) {
      return FALSE;
    }
    return $this->setStatus($gid, $contact_id, 'Removed');
  }

  /**
   * Members of a course's group with status Added.
   *
   * @return array<int, array{contact_id:int,email:string,name:string}>
   *   Keyed by contact id.
   */
  public function members(int $nid): array {
    $gid = $this->groupFor($nid, FALSE);
    if (!$gid) {
      return [];
    }
    $this->civi();
    $rows = \civicrm_api4('GroupContact', 'get', [
      'select' => [
        'contact_id',
        'contact_id.first_name',
        'contact_id.display_name',
        'contact_id.email_primary.email',
      ],
      'where' => [['group_id', '=', $gid], ['status', '=', 'Added'], ['contact_id.is_deleted', '=', FALSE]],
      'checkPermissions' => FALSE,
    ]);
    $out = [];
    foreach ($rows as $r) {
      $email = (string) ($r['contact_id.email_primary.email'] ?? '');
      if ($email === '') {
        continue;
      }
      $first = trim((string) ($r['contact_id.first_name'] ?? ''));
      $out[(int) $r['contact_id']] = [
        'contact_id' => (int) $r['contact_id'],
        'email' => $email,
        'name' => $first !== '' ? $first : (string) ($r['contact_id.display_name'] ?? ''),
      ];
    }
    return $out;
  }

  /**
   * How many contacts are in the course's group (Added).
   */
  public function count(int $nid): int {
    $gid = $this->groupFor($nid, FALSE);
    if (!$gid) {
      return 0;
    }
    return (int) $this->database->query(
      'SELECT COUNT(*) FROM {civicrm_group_contact} WHERE group_id = :g AND status = :s',
      [':g' => $gid, ':s' => 'Added']
    )->fetchField();
  }

  /**
   * The CiviCRM page listing a group's contacts (what the coordinator opens).
   */
  public function groupUrl(int $nid): ?string {
    $gid = $this->groupFor($nid, FALSE);
    return $gid ? '/civicrm/group/search?reset=1&force=1&context=smog&gid=' . $gid : NULL;
  }

  /**
   * Makes sure every program has a group and every follower is in it.
   *
   * @return array{groups:int, added:int}
   *   Groups in place and followers newly added.
   */
  public function syncAll(): array {
    $stats = ['groups' => 0, 'added' => 0];
    $nids = $this->database->select('node__field_course_type', 't')
      ->fields('t', ['entity_id'])
      ->condition('t.field_course_type_value', 'program')
      ->execute()
      ->fetchCol();
    foreach ($nids as $nid) {
      $nid = (int) $nid;
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      // Unlisted programs (an event template's course, a partner-only run)
      // can still be followed by link, but do not get a group pre-created.
      if (!$node || !$node->isPublished() || (int) ($node->get('field_publicly_listed')->value ?? 0) !== 1) {
        continue;
      }
      if ($this->groupFor($nid, TRUE)) {
        $stats['groups']++;
      }
      $uids = $this->database->select('flagging', 'f')
        ->fields('f', ['uid'])
        ->condition('f.flag_id', CourseFollowerNotifier::FLAG_ID)
        ->condition('f.entity_type', 'node')
        ->condition('f.entity_id', $nid)
        ->execute()
        ->fetchCol();
      foreach ($uids as $uid) {
        $cid = $this->contactForUser((int) $uid);
        if ($cid && $this->add($nid, $cid)) {
          $stats['added']++;
        }
      }
    }
    return $stats;
  }

  /**
   * Sets a contact's status in a group, creating the row if absent.
   */
  private function setStatus(int $gid, int $contact_id, string $status): bool {
    $this->civi();
    $existing = \civicrm_api4('GroupContact', 'get', [
      'select' => ['id', 'status'],
      'where' => [['group_id', '=', $gid], ['contact_id', '=', $contact_id]],
      'checkPermissions' => FALSE,
    ])->first();
    if ($existing) {
      if ($existing['status'] === $status) {
        return FALSE;
      }
      \civicrm_api4('GroupContact', 'update', [
        'values' => ['status' => $status],
        'where' => [['id', '=', $existing['id']]],
        'checkPermissions' => FALSE,
      ]);
      return TRUE;
    }
    if ($status !== 'Added') {
      return FALSE;
    }
    \civicrm_api4('GroupContact', 'create', [
      'values' => ['group_id' => $gid, 'contact_id' => $contact_id, 'status' => 'Added'],
      'checkPermissions' => FALSE,
    ]);
    return TRUE;
  }

  /**
   * Boots CiviCRM for API calls made from Drupal.
   */
  private function civi(): void {
    if (!\Drupal::hasService('civicrm')) {
      throw new \RuntimeException('CiviCRM is not available.');
    }
    \Drupal::service('civicrm')->initialize();
  }

}
