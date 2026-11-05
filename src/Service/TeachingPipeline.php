<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Who could teach what, by interest area. Counts only, never a list of names.
 *
 * Interested, willing and has-badge are nested and count active members.
 * Willing and has-badge leave out anyone already teaching, so they show who is
 * still available. Applied, taught, active and waiting are counted separately:
 * nearly half of instructors are not members and many never ticked a box, so a
 * single funnel would show taught exceeding applied.
 */
class TeachingPipeline {

  /**
   * Classes someone teaches: workshops, member-only classes and programs.
   *
   * Meetups are hosted, not taught for pay, and they would otherwise make up a
   * third of the people counted.
   */
  public const TAUGHT_EVENT_TYPES = [6, 7, 16];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * One row per interest area.
   *
   * @return array<int, array<string, mixed>>
   *   See assemble().
   */
  public function rows(): array {
    $terms = $this->database->query("
      SELECT t.tid, t.name FROM {taxonomy_term_field_data} t
      INNER JOIN {taxonomy_term__parent} p ON p.entity_id = t.tid AND p.parent_target_id <> 0
      WHERE t.vid = 'area_of_interest' AND t.status = 1")->fetchAllKeyed();

    $members = $this->database->query("
      SELECT u.uid FROM {users_field_data} u
      INNER JOIN {user__roles} r ON r.entity_id = u.uid AND r.roles_target_id = 'member' AND r.deleted = 0
      WHERE u.status = 1")->fetchCol();

    $teaching = array_merge(
      $this->database->query("SELECT entity_id FROM {user__roles} WHERE roles_target_id = 'instructor' AND deleted = 0")->fetchCol(),
      $this->database->query("SELECT DISTINCT field_civi_event_instructor_target_id FROM {civicrm_event__field_civi_event_instructor} WHERE deleted = 0")->fetchCol(),
    );

    $willing = $this->database->query("
      SELECT DISTINCT p.uid FROM {profile} p
      INNER JOIN {profile__field_member_availability} a ON a.entity_id = p.profile_id AND a.deleted = 0
      WHERE p.type = 'main' AND a.field_member_availability_value IN ('teach_paid', 'teach_volunteer')")->fetchCol();

    $interested = $this->groupByKey("
      SELECT i.field_member_areas_interest_target_id AS k, p.uid AS v FROM {profile} p
      INNER JOIN {profile__field_member_areas_interest} i ON i.entity_id = p.profile_id AND i.deleted = 0
      WHERE p.type = 'main'");

    $topic_badges = $this->groupByKey("
      SELECT DISTINCT a.field_civi_event_area_interest_target_id AS k, b.field_course_badges_target_id AS v
      FROM {civicrm_event__field_parent_course} pc
      INNER JOIN {civicrm_event__field_civi_event_area_interest} a ON a.entity_id = pc.entity_id AND a.deleted = 0
      INNER JOIN {node__field_course_badges} b ON b.entity_id = pc.field_parent_course_target_id AND b.deleted = 0
      WHERE pc.deleted = 0");

    $badge_holders = $this->groupByKey("
      SELECT r.field_badge_requested_target_id AS k, m.field_member_to_badge_target_id AS v
      FROM {node__field_badge_requested} r
      INNER JOIN {node__field_badge_status} s ON s.entity_id = r.entity_id AND s.field_badge_status_value = 'active' AND s.deleted = 0
      INNER JOIN {node__field_member_to_badge} m ON m.entity_id = r.entity_id AND m.deleted = 0
      WHERE r.deleted = 0");

    $applied = $this->groupByKey("
      SELECT DISTINCT value AS k, sid AS v FROM {webform_submission_data}
      WHERE webform_id = 'webform_14366' AND name = 'teaching_topics'");

    $taught = [];
    $active = [];
    $cutoff = $this->time->getRequestTime() - 365 * 86400;
    $rows = $this->database->query("
      SELECT a.field_civi_event_area_interest_target_id AS tid, i.field_civi_event_instructor_target_id AS uid, MAX(e.start_date) AS latest
      FROM {civicrm_event} e
      INNER JOIN {civicrm_event__field_civi_event_instructor} i ON i.entity_id = e.id AND i.deleted = 0
      INNER JOIN {civicrm_event__field_civi_event_area_interest} a ON a.entity_id = e.id AND a.deleted = 0
      WHERE e.is_active = 1 AND e.is_template = 0 AND e.event_type_id IN (:types[]) AND e.start_date < :now
      GROUP BY a.field_civi_event_area_interest_target_id, i.field_civi_event_instructor_target_id", [
        ':types[]' => self::TAUGHT_EVENT_TYPES,
        ':now' => date('Y-m-d H:i:s', $this->time->getRequestTime()),
      ]);
    foreach ($rows as $r) {
      $taught[(int) $r->tid][] = (int) $r->uid;
      if (strtotime((string) $r->latest) >= $cutoff) {
        $active[(int) $r->tid][] = (int) $r->uid;
      }
    }

    // Still waiting: turned away in the last year and not seated on a later
    // run of the same course, as in WorkshopInsightsService::teachingDemand().
    $waiting = $this->database->query("
      SELECT a.field_civi_event_area_interest_target_id AS tid, COUNT(DISTINCT p.contact_id) AS n
      FROM {civicrm_participant} p
      INNER JOIN {civicrm_event} e ON e.id = p.event_id AND e.is_template = 0 AND e.is_active = 1
      INNER JOIN {civicrm_event__field_civi_event_area_interest} a ON a.entity_id = e.id AND a.deleted = 0
      LEFT JOIN {civicrm_event__field_parent_course} pc ON pc.entity_id = e.id AND pc.deleted = 0
      WHERE p.is_test = 0 AND p.status_id IN (7, 9, 12) AND e.start_date >= :year AND e.event_type_id IN (:types[])
        AND NOT EXISTS (
          SELECT 1 FROM {civicrm_participant} p2
          INNER JOIN {civicrm_event} e2 ON e2.id = p2.event_id
          INNER JOIN {civicrm_event__field_parent_course} pc2 ON pc2.entity_id = e2.id AND pc2.deleted = 0
          WHERE p2.contact_id = p.contact_id AND p2.is_test = 0 AND p2.status_id IN (1, 2, 5, 14, 15)
            AND pc2.field_parent_course_target_id = pc.field_parent_course_target_id
            AND e2.start_date > e.start_date)
      GROUP BY a.field_civi_event_area_interest_target_id", [
        ':year' => date('Y-m-d H:i:s', $cutoff),
        ':types[]' => self::TAUGHT_EVENT_TYPES,
      ])->fetchAllKeyed();

    return self::assemble($terms, $members, $teaching, $willing, $interested, $topic_badges, $badge_holders, $applied, $taught, $active, $waiting);
  }

  /**
   * Builds the rows from plain lists, so the counting can be tested.
   *
   * @return array<int, array{tid:int, area:string, interested:int, willing:int, badge:int|null, applied:int, taught:int, active:int, waiting:int}>
   *   The badge count is NULL when no badge is linked to the area yet.
   */
  public static function assemble(array $terms, array $members, array $teaching, array $willing, array $interested, array $topic_badges, array $badge_holders, array $applied, array $taught, array $active, array $waiting): array {
    $members = array_flip(array_map('intval', $members));
    $teaching = array_flip(array_map('intval', $teaching));
    $willing = array_flip(array_map('intval', $willing));
    $out = [];
    foreach ($terms as $tid => $name) {
      $tid = (int) $tid;
      $here = array_intersect_key(array_flip(array_map('intval', $interested[$tid] ?? [])), $members);
      $available = array_diff_key(array_intersect_key($here, $willing), $teaching);
      $badge = NULL;
      if (!empty($topic_badges[$tid])) {
        $holders = [];
        foreach ($topic_badges[$tid] as $badge_tid) {
          $holders += array_flip(array_map('intval', $badge_holders[$badge_tid] ?? []));
        }
        $badge = count(array_intersect_key($available, $holders));
      }
      $out[] = [
        'tid' => $tid,
        'area' => (string) $name,
        'interested' => count($here),
        'willing' => count($available),
        'badge' => $badge,
        'applied' => count(array_unique($applied[$tid] ?? [])),
        'taught' => count(array_unique($taught[$tid] ?? [])),
        'active' => count(array_unique($active[$tid] ?? [])),
        'waiting' => (int) ($waiting[$tid] ?? 0),
      ];
    }
    return $out;
  }

  /**
   * Narrows the rows: people waiting, or few active instructors.
   */
  public static function filter(array $rows, string $view): array {
    return array_values(array_filter($rows, static fn(array $r): bool => match ($view) {
      'waiting' => $r['waiting'] > 0,
      'few' => $r['active'] <= 1,
      default => TRUE,
    }));
  }

  /**
   * Sorts highest first, then by name. A missing badge link sorts last.
   */
  public static function sort(array $rows, string $key): array {
    usort($rows, static function (array $a, array $b) use ($key): int {
      if ($key === 'area') {
        return strcasecmp($a['area'], $b['area']);
      }
      return (($b[$key] ?? -1) <=> ($a[$key] ?? -1)) ?: strcasecmp($a['area'], $b['area']);
    });
    return $rows;
  }

  /**
   * Runs a two-column query into lists of v keyed by k.
   */
  private function groupByKey(string $sql): array {
    $out = [];
    foreach ($this->database->query($sql) as $r) {
      $out[(int) $r->k][] = (int) $r->v;
    }
    return $out;
  }

}
