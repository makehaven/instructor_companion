<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for reassigning an individual event to a different course.
 */
class EventReassignController extends ControllerBase {

  /**
   * Reassigns an event.
   *
   * @param int $event_id
   *   CiviCRM Event ID.
   * @param int $target_nid
   *   Target Course NID.
   *
   * @return RedirectResponse
   */
  public function reassign($event_id, $target_nid) {
    $database = \Drupal::database();
    
    // Get the old course ID first for sync.
    $old_nid = $database->select('civicrm_event__field_parent_course', 'f')
      ->fields('f', ['field_parent_course_target_id'])
      ->condition('entity_id', $event_id)
      ->execute()
      ->fetchField();

    // Update the record.
    $database->merge('civicrm_event__field_parent_course')
      ->keys(['entity_id' => (int) $event_id])
      ->fields([
        'bundle' => 'ticketed_workshop', // Fallback for new assignments
        'deleted' => 0,
        'langcode' => 'und',
        'revision_id' => (int) $event_id,
        'delta' => 0,
        'field_parent_course_target_id' => (int) $target_nid,
      ])
      ->updateFields([
        'field_parent_course_target_id' => (int) $target_nid,
      ])
      ->execute();

    // Sync stats for both.
    $sync = \Drupal::service('instructor_companion.stats_sync');
    if ($old_nid) {
      $sync->sync((int) $old_nid);
    }
    $sync->sync((int) $target_nid);

    $this->messenger()->addStatus($this->t('Event #@id has been moved to the new course.', ['@id' => $event_id]));

    return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $target_nid])->toString() . '/instances');
  }

}
