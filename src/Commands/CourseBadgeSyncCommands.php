<?php

namespace Drupal\instructor_companion\Commands;

use Drupal\instructor_companion\CourseBadgeSync;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Instructor Companion course badge sync.
 */
class CourseBadgeSyncCommands extends DrushCommands {

  protected CourseBadgeSync $badgeSync;

  public function __construct(CourseBadgeSync $badge_sync) {
    parent::__construct();
    $this->badgeSync = $badge_sync;
  }

  /**
   * Recompute field_course_badges for one or all courses.
   *
   * @command instructor-companion:sync-course-badges
   * @aliases ic-scb
   * @option nid Sync only the given course node id.
   * @usage instructor-companion:sync-course-badges
   *   Sync every course.
   * @usage instructor-companion:sync-course-badges --nid=41420
   *   Sync only course 41420.
   */
  public function sync(array $options = ['nid' => NULL]) {
    $nid = $options['nid'] !== NULL ? (int) $options['nid'] : NULL;
    $touched = $this->badgeSync->sync($nid);
    $this->logger()->success(dt('Updated badges on @count course(s).', ['@count' => $touched]));
  }

}
