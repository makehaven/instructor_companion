<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * The numbers behind /admin/education/workshops.
 *
 * Read-only queries over CiviCRM events and participants, scoped to the
 * event types that owe wrap-up (Ticketed Workshop, Ticketed Member Only).
 * Revenue is deliberately absent: that lives on the KPI dashboard and in the
 * finance report, and a scheduling page that also shows money gets read as a
 * league table.
 */
class WorkshopInsightsService {

  public const SOLD_STATUSES = [1, 2, 14, 15];
  protected const ROLE_ATTENDEE = '1';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected StateInterface $state,
  ) {}

  /**
   * Where a class stands against its date. Pure; unit tested.
   *
   * @return string
   *   One of full, waitlist, behind, watch, on-track, empty.
   */
  public static function paceFlag(int $days_out, int $sold, int $cap, int $waiting): string {
    if ($waiting > 0) {
      return 'waitlist';
    }
    if ($cap > 0 && $sold >= $cap) {
      return 'full';
    }
    if ($sold === 0 && $days_out <= 14) {
      return 'empty';
    }
    if ($cap > 0 && $days_out <= 7 && $sold < 0.5 * $cap) {
      return 'behind';
    }
    if ($cap > 0 && $days_out <= 14 && $sold < 0.34 * $cap) {
      return 'watch';
    }
    return 'on-track';
  }

  /**
   * Upcoming classes with everything the page shows about them.
   *
   * @return array<int, array>
   *   Keyed by event id.
   */
  public function upcomingRuns(int $days = 60): array {
    if (!$this->database->schema()->tableExists('civicrm_event')) {
      return [];
    }
    $now = $this->time->getRequestTime();
    $types = PostEventStatusService::closeoutEventTypes();
    $role = self::ROLE_ATTENDEE;
    $role_like = "CONCAT(CHAR(1), p.role_id, CHAR(1)) LIKE CONCAT('%', CHAR(1), '$role', CHAR(1), '%')";
    $sql = "
      SELECT e.id, e.title, e.start_date, e.max_participants, e.has_waitlist, e.expiration_time,
             ins.field_civi_event_instructor_target_id AS instructor_uid,
             pc.field_parent_course_target_id AS course_nid,
             n.title AS course_title,
             (SELECT COUNT(*) FROM {civicrm_event__field_civi_event_sessions} s WHERE s.entity_id = e.id AND s.deleted = 0) AS sessions,
             SUM(CASE WHEN p.status_id IN (1, 2, 14, 15) AND $role_like THEN 1 ELSE 0 END) AS sold,
             SUM(CASE WHEN p.status_id = 5 THEN 1 ELSE 0 END) AS pay_later,
             SUM(CASE WHEN p.status_id = 7 THEN 1 ELSE 0 END) AS waiting,
             SUM(CASE WHEN p.status_id = 9 THEN 1 ELSE 0 END) AS offered,
             SUM(CASE WHEN p.status_id = 6 AND p.register_date >= :recent THEN 1 ELSE 0 END) AS abandoned
      FROM {civicrm_event} e
      LEFT JOIN {civicrm_participant} p ON p.event_id = e.id AND p.is_test = 0
      LEFT JOIN {civicrm_event__field_civi_event_instructor} ins ON ins.entity_id = e.id AND ins.deleted = 0
      LEFT JOIN {civicrm_event__field_parent_course} pc ON pc.entity_id = e.id AND pc.deleted = 0
      LEFT JOIN {node_field_data} n ON n.nid = pc.field_parent_course_target_id
      WHERE e.is_template = 0 AND e.is_active = 1
        AND e.start_date BETWEEN :now AND :until
        " . ($types ? 'AND e.event_type_id IN (:types[])' : '') . "
      GROUP BY e.id, e.title, e.start_date, e.max_participants, e.has_waitlist, e.expiration_time,
               ins.field_civi_event_instructor_target_id, pc.field_parent_course_target_id, n.title
      ORDER BY e.start_date";
    $args = [
      ':now' => date('Y-m-d H:i:s', $now),
      ':until' => date('Y-m-d H:i:s', $now + $days * 86400),
      ':recent' => date('Y-m-d H:i:s', $now - AbandonedRegistrationService::MAX_AGE_DAYS * 86400),
    ];
    if ($types) {
      $args[':types[]'] = $types;
    }
    $rows = $this->database->query($sql, $args)->fetchAll();
    $uids = array_values(array_filter(array_map(fn($r) => (int) $r->instructor_uid, $rows)));
    $names = $this->instructorNames($uids);
    $out = [];
    foreach ($rows as $r) {
      $start = strtotime((string) $r->start_date) ?: $now;
      $cap = (int) $r->max_participants;
      $sold = (int) $r->sold;
      $waiting = (int) $r->waiting;
      $days_out = (int) floor(($start - $now) / 86400);
      $gaps = [];
      if (!$r->course_nid) {
        $gaps[] = 'no course';
      }
      if (!$r->instructor_uid) {
        $gaps[] = 'no instructor';
      }
      if ((int) $r->has_waitlist === 1 && !(int) $r->expiration_time) {
        $gaps[] = 'waitlist offers never expire';
      }
      $out[(int) $r->id] = [
        'id' => (int) $r->id,
        'title' => (string) $r->title,
        'start' => $start,
        'days_out' => $days_out,
        'instructor_uid' => (int) $r->instructor_uid,
        'instructor' => $names[(int) $r->instructor_uid] ?? NULL,
        'course_nid' => (int) $r->course_nid,
        'course' => (string) ($r->course_title ?? ''),
        'sessions' => (int) $r->sessions,
        'cap' => $cap,
        'sold' => $sold,
        'pay_later' => (int) $r->pay_later,
        'waiting' => $waiting,
        'offered' => (int) $r->offered,
        'abandoned' => (int) $r->abandoned,
        'pace' => self::paceFlag($days_out, $sold, $cap, $waiting),
        'gaps' => $gaps,
      ];
    }
    return $out;
  }

  /**
   * Uid => display name for instructors.
   */
  protected function instructorNames(array $uids): array {
    if (!$uids) {
      return [];
    }
    $q = $this->database->select('users_field_data', 'u');
    $q->leftJoin('user__field_first_name', 'fn', 'fn.entity_id = u.uid');
    $q->leftJoin('user__field_last_name', 'ln', 'ln.entity_id = u.uid');
    $q->fields('u', ['uid', 'name']);
    $q->addField('fn', 'field_first_name_value', 'first');
    $q->addField('ln', 'field_last_name_value', 'last');
    $q->condition('u.uid', $uids, 'IN');
    $out = [];
    foreach ($q->execute() as $r) {
      $full = trim(trim((string) $r->first) . ' ' . trim((string) $r->last));
      $out[(int) $r->uid] = $full !== '' ? $full : (string) $r->name;
    }
    return $out;
  }

  /**
   * People turned away per course over the last year.
   *
   * With whether any of them later got a seat on a run of the same course.
   *
   * @return array<int, array>
   *   Rows: course, course_nid, waiting (distinct people), reseated, runs,
   *   latest (timestamp), upcoming (future runs of the course).
   */
  public function demandTurnedAway(int $months = 12): array {
    if (!$this->database->schema()->tableExists('civicrm_participant')) {
      return [];
    }
    $now = $this->time->getRequestTime();
    $types = PostEventStatusService::closeoutEventTypes();
    $since = date('Y-m-d H:i:s', strtotime("-$months months", $now));
    $sql = "
      SELECT n.nid AS course_nid, COALESCE(n.title, e.title) AS course,
             COUNT(DISTINCT p.contact_id) AS waiting,
             COUNT(DISTINCT CASE WHEN EXISTS (
               SELECT 1 FROM {civicrm_participant} p2
               INNER JOIN {civicrm_event} e2 ON e2.id = p2.event_id
               LEFT JOIN {civicrm_event__field_parent_course} pc2 ON pc2.entity_id = e2.id AND pc2.deleted = 0
               WHERE p2.contact_id = p.contact_id AND p2.status_id IN (1, 2, 14, 15) AND e2.id <> e.id
                 AND e2.start_date > e.start_date
                 AND COALESCE(pc2.field_parent_course_target_id, 0) = COALESCE(pc.field_parent_course_target_id, 0)
                 AND COALESCE(pc.field_parent_course_target_id, 0) <> 0
             ) THEN p.contact_id END) AS reseated,
             COUNT(DISTINCT e.id) AS runs,
             MAX(e.start_date) AS latest,
             (SELECT COUNT(*) FROM {civicrm_event} f
              INNER JOIN {civicrm_event__field_parent_course} pcf ON pcf.entity_id = f.id AND pcf.deleted = 0
              WHERE pcf.field_parent_course_target_id = n.nid AND f.is_active = 1 AND f.is_template = 0 AND f.start_date > :now) AS upcoming
      FROM {civicrm_participant} p
      INNER JOIN {civicrm_event} e ON e.id = p.event_id AND e.is_template = 0 AND e.is_active = 1
      LEFT JOIN {civicrm_event__field_parent_course} pc ON pc.entity_id = e.id AND pc.deleted = 0
      LEFT JOIN {node_field_data} n ON n.nid = pc.field_parent_course_target_id
      WHERE p.is_test = 0 AND p.status_id IN (7, 9, 12) AND e.start_date BETWEEN :since AND :now
        " . ($types ? 'AND e.event_type_id IN (:types[])' : '') . "
      GROUP BY n.nid, COALESCE(n.title, e.title)
      ORDER BY waiting DESC
      LIMIT 15";
    $args = [':since' => $since, ':now' => date('Y-m-d H:i:s', $now)];
    if ($types) {
      $args[':types[]'] = $types;
    }
    $out = [];
    foreach ($this->database->query($sql, $args) as $r) {
      $out[] = [
        'course_nid' => (int) $r->course_nid,
        'course' => (string) $r->course,
        'waiting' => (int) $r->waiting,
        'reseated' => (int) $r->reseated,
        'runs' => (int) $r->runs,
        'latest' => strtotime((string) $r->latest) ?: 0,
        'upcoming' => (int) $r->upcoming,
      ];
    }
    return $out;
  }

  /**
   * What the three follow-up crons did in the last N days.
   *
   * @return array{join:int, badge:int, abandoned:int, classes:int, seeded:bool}
   *   Counts of what was sent, and whether the cron has seeded.
   */
  public function followupsSent(int $days = 7): array {
    $now = $this->time->getRequestTime();
    $cutoff = $now - $days * 86400;
    $out = ['join' => 0, 'badge' => 0, 'abandoned' => 0, 'classes' => 0, 'seeded' => FALSE];
    $followup = (array) $this->state->get(AttendeeFollowupService::STATE_KEY, []);
    $out['seeded'] = !empty($followup['_seeded']);
    foreach ($followup as $k => $v) {
      if (!is_array($v) || (int) ($v['t'] ?? 0) < $cutoff || !empty($v['seeded'])) {
        continue;
      }
      if ($k[0] === 'p') {
        $out[($v['kind'] ?? '') === 'badge' ? 'badge' : 'join']++;
      }
      elseif ($k[0] === 'e') {
        $out['classes']++;
      }
    }
    $abandoned = (array) $this->state->get(AbandonedRegistrationService::STATE_KEY, []);
    foreach ($abandoned as $k => $v) {
      if (is_array($v) && $k[0] === 'p' && (int) ($v['t'] ?? 0) >= $cutoff && empty($v['seeded'])) {
        $out['abandoned']++;
      }
    }
    return $out;
  }

  /**
   * SQL condition: the event charges for a seat, so a seat should be paid.
   *
   * A free event has nothing to collect, so its registrations carry no
   * payment row by design and do not belong on the "no payment record" list
   * (ledger #45786: Stop the Bleed and the FCC licensing exam were listed).
   * Free means either the event is not monetary at all, or its price set
   * offers only zero-amount options. Inactive options still count as priced:
   * someone may have bought one before it was switched off. A monetary event
   * with no price set rows is kept (nothing proves it free).
   *
   * Zero-fee participants on a priced event are deliberately not filtered:
   * those are the comps and scholarship seats the list exists to surface.
   *
   * @param string $alias
   *   The alias of civicrm_event in the query being filtered.
   *
   * @return string
   *   A WHERE fragment with Drupal {table} braces; no placeholders.
   */
  public static function chargeableEventCondition(string $alias = 'e'): string {
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
    $options = "FROM {civicrm_price_set_entity} pse
        INNER JOIN {civicrm_price_field} pf ON pf.price_set_id = pse.price_set_id
        INNER JOIN {civicrm_price_field_value} pfv ON pfv.price_field_id = pf.id
        WHERE pse.entity_table = 'civicrm_event' AND pse.entity_id = $alias.id";
    return "$alias.is_monetary = 1 AND (
        NOT EXISTS (SELECT 1 $options)
        OR EXISTS (SELECT 1 $options AND pfv.amount > 0)
      )";
  }

  /**
   * Counted Ticketed Workshop registrations in a window with no payment row.
   *
   * Free events (see chargeableEventCondition()) are left out.
   *
   * @return array<int, array>
   *   Rows: event_id, title, start (timestamp), contact_id, name, status,
   *   registered (timestamp), source.
   */
  public function seatsWithoutPayment(int $start, int $end): array {
    if (!$this->database->schema()->tableExists('civicrm_participant')) {
      return [];
    }
    $type = (int) $this->database->query(
      "SELECT ov.value FROM {civicrm_option_value} ov
       INNER JOIN {civicrm_option_group} og ON og.id = ov.option_group_id AND og.name = 'event_type'
       WHERE ov.label = 'Ticketed Workshop' LIMIT 1"
    )->fetchField();
    if (!$type) {
      return [];
    }
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $q->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $q->innerJoin('civicrm_contact', 'c', 'c.id = p.contact_id');
    $q->addField('e', 'id', 'event_id');
    $q->addField('e', 'title', 'title');
    $q->addField('e', 'start_date', 'start');
    $q->addField('p', 'contact_id', 'contact_id');
    $q->addField('p', 'register_date', 'registered');
    $q->addField('p', 'source', 'source');
    $q->addField('pst', 'label', 'status');
    $q->addField('c', 'display_name', 'name');
    $q->condition('pst.is_counted', 1)
      ->condition('p.is_test', 0)
      ->condition('p.role_id', '1')
      ->condition('e.is_active', 1)
      ->condition('e.is_template', 0)
      ->condition('e.event_type_id', $type)
      ->condition('e.start_date', [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)], 'BETWEEN');
    $q->where(self::chargeableEventCondition('e'));
    $paid = $this->database->select('civicrm_participant_payment', 'pp');
    $paid->addField('pp', 'participant_id');
    $paid->where('pp.participant_id = p.id');
    $q->notExists($paid);
    $q->orderBy('e.start_date', 'DESC');
    $out = [];
    foreach ($q->execute() as $r) {
      $out[] = [
        'event_id' => (int) $r->event_id,
        'title' => (string) $r->title,
        'start' => strtotime((string) $r->start) ?: 0,
        'contact_id' => (int) $r->contact_id,
        'name' => (string) $r->name,
        'status' => (string) $r->status,
        'registered' => strtotime((string) $r->registered) ?: 0,
        'source' => (string) ($r->source ?? ''),
      ];
    }
    return $out;
  }

}
