<?php

namespace Drupal\instructor_companion;

use Drupal\node\Entity\Node;

/**
 * Service to synchronize Course performance metrics from CiviCRM.
 */
class CourseStatsSync {

  /**
   * Syncs stats for a specific course or all courses.
   */
  public function sync(int $nid = NULL) {
    $database = \Drupal::database();
    $course_storage = \Drupal::entityTypeManager()->getStorage('node');
    $now = date('Y-m-d H:i:s');
    $one_year_ago = date('Y-m-d H:i:s', strtotime('-1 year'));

    $query = $course_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course');
    
    if ($nid) {
      $query->condition('nid', $nid);
    }
    $nids = $query->execute();

    foreach ($nids as $id) {
      $node = $course_storage->load($id);
      if (!$node) continue;

      $stats = [
        'runs' => 0,
        'upcoming' => 0,
        'last_run' => NULL,
        'total_rev' => 0,
        'total_att' => 0,
        'past_cap' => 0,
        'past_att' => 0,
        'runs_12mo' => 0,
        'rev_12mo' => 0,
        'att_12mo' => 0,
      ];

      $q = $database->select('civicrm_event', 'e');
      $q->join('civicrm_event__field_parent_course', 'f', 'e.id = f.entity_id');
      $q->fields('e', ['id', 'start_date', 'max_participants']);
      $q->condition('f.field_parent_course_target_id', $id);
      $q->condition('e.is_active', 1);
      $events = $q->execute()->fetchAll();

      foreach ($events as $e) {
        $stats['runs']++;
        $is_upcoming = ($e->start_date >= $now);
        $is_recent = ($e->start_date >= $one_year_ago && $e->start_date < $now);

        if ($is_upcoming) {
          $stats['upcoming']++;
        } else {
          if (!$stats['last_run'] || $e->start_date > $stats['last_run']) {
            $stats['last_run'] = $e->start_date;
          }
          $stats['past_cap'] += (int) $e->max_participants;
        }

        if ($is_recent) {
          $stats['runs_12mo']++;
        }

        $pq = $database->select('civicrm_participant', 'p');
        $pq->addExpression('COUNT(p.id)', 'att');
        $pq->addExpression('SUM(p.fee_amount)', 'rev');
        $pq->condition('p.event_id', $e->id);
        $res = $pq->execute()->fetchObject();
        
        $event_att = (int) ($res->att ?? 0);
        $event_rev = (float) ($res->rev ?? 0);

        $stats['total_att'] += $event_att;
        $stats['total_rev'] += $event_rev;

        if (!$is_upcoming) {
          $stats['past_att'] += $event_att;
        }

        if ($is_recent) {
          $stats['att_12mo'] += $event_att;
          $stats['rev_12mo'] += $event_rev;
        }
      }

      $util = ($stats['past_cap'] > 0) ? round(($stats['past_att'] / $stats['past_cap']) * 100) : 0;
      if ($util > 100) $util = 100;

      $node->set('field_stat_runs', $stats['runs']);
      $node->set('field_stat_upcoming', $stats['upcoming']);
      $node->set('field_stat_last_run', $stats['last_run']);
      $node->set('field_stat_total_rev', $stats['total_rev']);
      $node->set('field_stat_total_att', $stats['total_att']);
      $node->set('field_stat_utilization', (int) $util);
      $node->set('field_stat_runs_12mo', $stats['runs_12mo']);
      $node->set('field_stat_rev_12mo', $stats['rev_12mo']);
      $node->set('field_stat_att_12mo', $stats['att_12mo']);
      
      $node->save();
    }
  }
}
