<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for merging two Course nodes.
 */
class CourseMergeController extends ControllerBase {

  /**
   * Merges one course into another.
   *
   * @param string $source_nid
   *   The NID of the course to be removed.
   * @param string $target_nid
   *   The NID of the course to keep.
   *
   * @return RedirectResponse
   *   Redirects back to the target course.
   */
  public function merge($source_nid, $target_nid) {
    $fallback_url = Url::fromRoute('view.course_library.page_workshops')->toString();

    if (!is_numeric($source_nid) || !is_numeric($target_nid)) {
      $this->messenger()->addError($this->t('Invalid course IDs provided. Please ensure you replaced TARGET_NID in the URL.'));
      return new RedirectResponse($fallback_url);
    }

    if ($source_nid == $target_nid) {
      $this->messenger()->addError($this->t('Cannot merge a course into itself.'));
      return new RedirectResponse($fallback_url);
    }

    $source = Node::load($source_nid);
    $target = Node::load($target_nid);

    if (!$source || !$target || $source->getType() !== 'course' || $target->getType() !== 'course') {
      $this->messenger()->addError($this->t('One or more invalid course nodes were provided.'));
      return new RedirectResponse($fallback_url);
    }

    $database = \Drupal::database();
    
    // 1. Reassign all CiviCRM Events.
    $updated_events = $database->update('civicrm_event__field_parent_course')
      ->fields(['field_parent_course_target_id' => $target_nid])
      ->condition('field_parent_course_target_id', $source_nid)
      ->execute();

    // 2. Reassign all Interest Flags (Flag module).
    if ($database->schema()->tableExists('flagging')) {
      $database->update('flagging')
        ->fields(['entity_id' => $target_nid])
        ->condition('entity_id', $source_nid)
        ->condition('entity_type', 'node')
        ->condition('flag_id', 'course_interest')
        ->execute();
      
      // Clear flag counts cache for these nodes.
      if ($database->schema()->tableExists('flag_counts')) {
        $database->delete('flag_counts')->condition('entity_id', [$source_nid, $target_nid])->execute();
      }
    }

    // 3. Trigger Stats Sync for the target.
    \Drupal::service('instructor_companion.stats_sync')->sync((int) $target_nid);

    // 4. Delete the source node.
    $source_title = $source->label();
    $source->delete();

    $this->messenger()->addStatus($this->t('Merged "@source" into "@target". @count events reassigned.', [
      '@source' => $source_title,
      '@target' => $target->label(),
      '@count'  => $updated_events,
    ]));

    return new RedirectResponse($target->toUrl()->toString());
  }

}
