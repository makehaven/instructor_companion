<?php

namespace Drupal\instructor_companion;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Derive a course's badge list from its child CiviCRM events.
 *
 * Mirrors CourseStatsSync: events under a course feed up to the course node
 * via field_parent_course. Each event's field_civi_event_badges contributes
 * to the course's field_course_badges. The course field is the union across
 * all child events — single source of truth lives on the events.
 */
class CourseBadgeSync {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected Connection $database;
  protected LoggerInterface $logger;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->logger = $logger;
  }

  /**
   * Recompute field_course_badges for one or all courses.
   */
  public function sync(?int $nid = NULL): int {
    $course_storage = $this->entityTypeManager->getStorage('node');
    $query = $course_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course');
    if ($nid) {
      $query->condition('nid', $nid);
    }
    $nids = $query->execute();

    $touched = 0;
    foreach ($nids as $course_nid) {
      if ($this->syncOne((int) $course_nid)) {
        $touched++;
      }
    }
    return $touched;
  }

  /**
   * Sync a single course; returns TRUE if the course's badges changed.
   */
  protected function syncOne(int $nid): bool {
    $course = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$course || !$course->hasField('field_course_badges')) {
      return FALSE;
    }

    // Union of badge tids from this course's events (active events only).
    $tids = $this->database->query(
      "SELECT DISTINCT b.field_civi_event_badges_target_id AS tid
         FROM {civicrm_event__field_parent_course} p
         INNER JOIN {civicrm_event} e ON e.id = p.entity_id
         INNER JOIN {civicrm_event__field_civi_event_badges} b ON b.entity_id = e.id
         WHERE p.field_parent_course_target_id = :nid
           AND e.is_active = 1
         ORDER BY tid",
      [':nid' => $nid]
    )->fetchCol();

    $existing = array_map(
      static fn($item) => (int) $item['target_id'],
      $course->get('field_course_badges')->getValue()
    );
    sort($existing);
    $new = array_map('intval', $tids);
    sort($new);

    if ($existing === $new) {
      return FALSE;
    }

    $course->set('field_course_badges', $new);
    $course->save();
    $this->logger->info('Synced badges for course @nid: @count badge(s).', [
      '@nid' => $nid,
      '@count' => count($new),
    ]);
    return TRUE;
  }

}
