<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Connection;
use Drupal\Core\PrivateKey;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Waitlists as the education team experiences them: per course, not per event.
 *
 * CiviCRM keeps a waitlist per event and does three things on its own: it
 * confirms the person on joining, promotes the first in line to "Pending from
 * waitlist" and emails them when a seat frees, and (with an offer window)
 * expires an unpaid offer and moves on. What it cannot know is that the
 * person took the same class last Thursday, or no longer wants it at all.
 *
 *  - onCountedRegistration(): when someone gets a counted seat on any run of
 *    a course, their waitlist entries on the course's other runs are
 *    cancelled (the education manager's ask, 2026-09-22).
 *  - leave(): a signed link in our "another run is open" email lets the
 *    person take themselves off every waitlist for the course.
 *  - cancelParticipants(): staff removal from /admin/education/workshops.
 *  - removeStruckOut(): three "another run is open" emails that the person
 *    did not act on take them off the course's waiting list (JR, 2026-11-10).
 *    A strike is an email we sent, not a run that happened, so a course that
 *    rarely runs keeps its list for years.
 *
 * Nothing is deleted. A removed entry stays in CiviCRM as Cancelled and each
 * removal is written to instructor_companion_waitlist_log with its reason.
 *
 * Cancellation goes through CiviCRM's API so its hooks run, but sends no
 * mail — a "your registration was cancelled" notice for a waitlist entry the
 * person forgot about is worse than silence.
 */
class WaitlistManager {

  public const STATUS_WAITLIST = 7;
  public const STATUS_OFFERED = 9;
  public const STATUS_EXPIRED = 12;
  public const STATUS_CANCELLED = 4;
  public const COUNTED_STATUSES = [1, 2, 5, 14, 15];

  /**
   * "Another run is open" emails a person gets per course before removal.
   */
  public const NOTICE_LIMIT = 3;

  public const LOG_TABLE = 'instructor_companion_waitlist_log';

  public function __construct(
    protected Connection $database,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
    protected PrivateKey $privateKey,
  ) {}

  /**
   * The course an event belongs to, or NULL.
   */
  public function courseOf(int $event_id): ?int {
    if (!$this->database->schema()->tableExists('civicrm_event__field_parent_course')) {
      return NULL;
    }
    $nid = $this->database->select('civicrm_event__field_parent_course', 'pc')
      ->fields('pc', ['field_parent_course_target_id'])
      ->condition('pc.entity_id', $event_id)
      ->condition('pc.deleted', 0)
      ->execute()->fetchField();
    return $nid ? (int) $nid : NULL;
  }

  /**
   * Everyone who has been on this event's waitlist, in join order.
   *
   * @return array<int, array>
   *   participant_id, contact_id, name, email, status_id, status, registered
   *   (timestamp), offered (TRUE when currently holding an unpaid offer).
   */
  public function entriesForEvent(int $event_id): array {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_contact', 'c', 'c.id = p.contact_id');
    $q->innerJoin('civicrm_participant_status_type', 'st', 'st.id = p.status_id');
    $q->leftJoin('civicrm_email', 'em', 'em.contact_id = c.id AND em.is_primary = 1');
    $q->addField('p', 'id', 'participant_id');
    $q->addField('p', 'contact_id', 'contact_id');
    $q->addField('p', 'status_id', 'status_id');
    $q->addField('p', 'register_date', 'registered');
    $q->addField('st', 'label', 'status');
    $q->addField('c', 'display_name', 'name');
    $q->addField('em', 'email', 'email');
    $q->condition('p.event_id', $event_id)
      ->condition('p.is_test', 0)
      ->condition('p.status_id', [
        self::STATUS_WAITLIST,
        self::STATUS_OFFERED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
      ], 'IN')
      ->orderBy('p.register_date');
    $out = [];
    foreach ($q->execute() as $r) {
      $out[] = [
        'participant_id' => (int) $r->participant_id,
        'contact_id' => (int) $r->contact_id,
        'name' => (string) $r->name,
        'email' => (string) ($r->email ?? ''),
        'status_id' => (int) $r->status_id,
        'status' => (string) $r->status,
        'registered' => strtotime((string) $r->registered) ?: 0,
        'offered' => (int) $r->status_id === self::STATUS_OFFERED,
      ];
    }
    return $out;
  }

  /**
   * A contact's live waitlist entries (waiting or offered) across a course.
   *
   * @return int[]
   *   Participant ids.
   */
  public function activeWaitlistIds(int $contact_id, int $course_nid, ?int $except_event = NULL, array $statuses = [self::STATUS_WAITLIST, self::STATUS_OFFERED]): array {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $q->innerJoin('civicrm_event__field_parent_course', 'pc', 'pc.entity_id = e.id AND pc.deleted = 0');
    $q->addField('p', 'id');
    $q->condition('p.contact_id', $contact_id)
      ->condition('p.is_test', 0)
      ->condition('p.status_id', $statuses, 'IN')
      ->condition('pc.field_parent_course_target_id', $course_nid)
      ->condition('e.is_template', 0);
    if ($except_event) {
      $q->condition('p.event_id', $except_event, '<>');
    }
    return array_map('intval', $q->execute()->fetchCol());
  }

  /**
   * Cancels waitlist entries. Returns how many changed.
   *
   * Uses CiviCRM's API so its hooks run; the API does not send mail.
   */
  public function cancelParticipants(array $participant_ids, string $why): int {
    $participant_ids = array_values(array_filter(array_map('intval', $participant_ids)));
    if (!$participant_ids) {
      return 0;
    }
    $n = 0;
    $done = [];
    $api = FALSE;
    try {
      \Drupal::service('civicrm')->initialize();
      $api = function_exists('civicrm_api3');
    }
    catch (\Throwable $e) {
      $api = FALSE;
    }
    foreach ($participant_ids as $pid) {
      try {
        if ($api) {
          civicrm_api3('Participant', 'create', ['id' => $pid, 'status_id' => self::STATUS_CANCELLED]);
        }
        else {
          $this->database->update('civicrm_participant')
            ->fields(['status_id' => self::STATUS_CANCELLED])
            ->condition('id', $pid)
            ->execute();
        }
        $n++;
        $done[] = $pid;
      }
      catch (\Throwable $e) {
        $this->logger->warning('Could not cancel waitlist participant @p: @m', ['@p' => $pid, '@m' => $e->getMessage()]);
      }
    }
    $this->recordRemovals($done, $why);
    if ($n) {
      $this->logger->notice('Waitlist: cancelled @n entr(ies) — @why (participants @ids).', [
        '@n' => $n,
        '@why' => $why,
        '@ids' => implode(', ', $participant_ids),
      ]);
    }
    return $n;
  }

  /**
   * Drops a contact's other waitlists for the course of $event_id.
   *
   * Called when they got a counted seat there. Returns how many were cancelled.
   */
  public function onCountedRegistration(int $contact_id, int $event_id): int {
    $course = $this->courseOf($event_id);
    if (!$course) {
      return 0;
    }
    $ids = $this->activeWaitlistIds($contact_id, $course, $event_id);
    return $ids ? $this->cancelParticipants($ids, "contact $contact_id now holds a seat on event $event_id of course $course") : 0;
  }

  /**
   * The person asked, by signed link, to leave every waitlist for a course.
   */
  public function leave(int $contact_id, int $course_nid): int {
    // Expired offers too: they still put the person in the notice audience.
    $ids = $this->activeWaitlistIds($contact_id, $course_nid, NULL, [self::STATUS_WAITLIST, self::STATUS_OFFERED, self::STATUS_EXPIRED]);
    return $ids ? $this->cancelParticipants($ids, "contact $contact_id left the waitlist for course $course_nid by email link") : 0;
  }

  /**
   * What to do about the next "another run is open" email. Pure; unit tested.
   *
   * @param int $strikes
   *   Emails already sent for this course since the person last joined.
   * @param int $limit
   *   Emails allowed.
   *
   * @return string
   *   'send', 'final' (send, and say it is the last) or 'stop'.
   */
  public static function noticeVerdict(int $strikes, int $limit = self::NOTICE_LIMIT): string {
    if ($strikes >= $limit) {
      return 'stop';
    }
    return $strikes === $limit - 1 ? 'final' : 'send';
  }

  /**
   * Whether the log table is there (it arrives with update 9022).
   */
  protected function hasLog(): bool {
    return $this->database->schema()->tableExists(self::LOG_TABLE);
  }

  /**
   * Notes that a person was emailed about a new run of a course.
   */
  public function recordNotice(int $contact_id, int $course_nid, int $event_id): void {
    if (!$this->hasLog()) {
      return;
    }
    $this->database->insert(self::LOG_TABLE)->fields([
      'kind' => 'notice',
      'contact_id' => $contact_id,
      'course_nid' => $course_nid,
      'event_id' => $event_id,
      'participant_id' => 0,
      'reason' => '',
      'created' => $this->time->getRequestTime(),
    ])->execute();
  }

  /**
   * Emails sent to a person about a course since they last joined its list.
   *
   * Joining a waiting list again is fresh interest, so it starts the count
   * over.
   */
  public function strikes(int $contact_id, int $course_nid): int {
    if (!$this->hasLog()) {
      return 0;
    }
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_event__field_parent_course', 'pc', 'pc.entity_id = p.event_id AND pc.deleted = 0');
    $q->addExpression('MAX(p.register_date)');
    $q->condition('p.contact_id', $contact_id)
      ->condition('p.is_test', 0)
      ->condition('p.status_id', [self::STATUS_WAITLIST, self::STATUS_OFFERED, self::STATUS_EXPIRED], 'IN')
      ->condition('pc.field_parent_course_target_id', $course_nid);
    $joined = strtotime((string) $q->execute()->fetchField()) ?: 0;
    return (int) $this->database->select(self::LOG_TABLE, 'l')
      ->condition('l.kind', 'notice')
      ->condition('l.contact_id', $contact_id)
      ->condition('l.course_nid', $course_nid)
      ->condition('l.created', $joined, '>')
      ->countQuery()->execute()->fetchField();
  }

  /**
   * Takes people off a course's list after their last notice went unused.
   *
   * Waits until every run they were told about has started, so the final
   * email is a real chance. Someone holding a live offer is left alone.
   *
   * @return int
   *   Entries cancelled.
   */
  public function removeStruckOut(): int {
    if (!$this->hasLog() || !$this->database->schema()->tableExists('civicrm_event__field_parent_course')) {
      return 0;
    }
    $pairs = $this->database->query(
      "SELECT contact_id, course_nid FROM {" . self::LOG_TABLE . "} WHERE kind = 'notice'
       GROUP BY contact_id, course_nid HAVING COUNT(*) >= :limit",
      [':limit' => self::NOTICE_LIMIT]
    )->fetchAll();
    $now = date('Y-m-d H:i:s', $this->time->getRequestTime());
    $total = 0;
    foreach ($pairs as $pair) {
      $contact = (int) $pair->contact_id;
      $course = (int) $pair->course_nid;
      $ids = $this->activeWaitlistIds($contact, $course, NULL, [self::STATUS_WAITLIST, self::STATUS_EXPIRED]);
      if (!$ids || $this->strikes($contact, $course) < self::NOTICE_LIMIT) {
        continue;
      }
      if ($this->activeWaitlistIds($contact, $course, NULL, [self::STATUS_OFFERED])) {
        continue;
      }
      $still_open = (int) $this->database->query(
        "SELECT COUNT(*) FROM {" . self::LOG_TABLE . "} l
         INNER JOIN {civicrm_event} e ON e.id = l.event_id
         WHERE l.kind = 'notice' AND l.contact_id = :c AND l.course_nid = :n AND e.is_active = 1 AND e.start_date > :now",
        [':c' => $contact, ':n' => $course, ':now' => $now]
      )->fetchField();
      if ($still_open) {
        continue;
      }
      $total += $this->cancelParticipants($ids, sprintf('told about %d new dates and took none', self::NOTICE_LIMIT));
    }
    return $total;
  }

  /**
   * Old entries the one-time cleanup removes (JR, 2026-11-10).
   *
   * Nobody was told about other runs before 2026-09-30, so past runs are not
   * strikes. The backlog rule is its own: joined over a year ago AND the
   * course has run three or more times since. Everyone else starts at zero.
   *
   * @return array<int, array>
   *   participant_id, contact_id, name, event_id, course_nid, registered,
   *   runs_since.
   */
  public function staleBacklog(): array {
    foreach (['civicrm_participant', 'civicrm_event__field_parent_course'] as $table) {
      if (!$this->database->schema()->tableExists($table)) {
        return [];
      }
    }
    $now = $this->time->getRequestTime();
    $rows = $this->database->query(
      "SELECT p.id participant_id, p.contact_id, c.display_name name, p.event_id, pc.field_parent_course_target_id course_nid,
         p.register_date registered, COUNT(DISTINCT e2.id) runs_since
       FROM {civicrm_participant} p
       INNER JOIN {civicrm_contact} c ON c.id = p.contact_id
       INNER JOIN {civicrm_event__field_parent_course} pc ON pc.entity_id = p.event_id AND pc.deleted = 0
       INNER JOIN {civicrm_event__field_parent_course} pc2 ON pc2.field_parent_course_target_id = pc.field_parent_course_target_id
         AND pc2.deleted = 0 AND pc2.entity_id <> p.event_id
       INNER JOIN {civicrm_event} e2 ON e2.id = pc2.entity_id AND e2.is_active = 1 AND e2.is_template = 0
         AND e2.start_date > p.register_date AND e2.start_date < :now
       WHERE p.is_test = 0 AND p.status_id IN (:statuses[]) AND p.register_date < :cutoff
       GROUP BY p.id, p.contact_id, c.display_name, p.event_id, pc.field_parent_course_target_id, p.register_date
       HAVING COUNT(DISTINCT e2.id) >= :runs
       ORDER BY p.register_date",
      [
        ':now' => date('Y-m-d H:i:s', $now),
        ':cutoff' => date('Y-m-d H:i:s', $now - 365 * 86400),
        ':statuses[]' => [self::STATUS_WAITLIST, self::STATUS_EXPIRED],
        ':runs' => self::NOTICE_LIMIT,
      ]
    )->fetchAll(\PDO::FETCH_ASSOC);
    return array_map(static fn(array $r): array => [
      'participant_id' => (int) $r['participant_id'],
      'contact_id' => (int) $r['contact_id'],
      'name' => (string) $r['name'],
      'event_id' => (int) $r['event_id'],
      'course_nid' => (int) $r['course_nid'],
      'registered' => (string) $r['registered'],
      'runs_since' => (int) $r['runs_since'],
    ], $rows);
  }

  /**
   * Why and when entries were removed, for the staff waitlist page.
   *
   * @return array<int, array{reason:string,created:int}>
   *   Keyed by participant id; the latest removal wins.
   */
  public function removalNotes(array $participant_ids): array {
    $participant_ids = array_values(array_filter(array_map('intval', $participant_ids)));
    if (!$participant_ids || !$this->hasLog()) {
      return [];
    }
    $out = [];
    $rows = $this->database->select(self::LOG_TABLE, 'l')
      ->fields('l', ['participant_id', 'reason', 'created'])
      ->condition('l.kind', 'removed')
      ->condition('l.participant_id', $participant_ids, 'IN')
      ->orderBy('l.id')
      ->execute();
    foreach ($rows as $r) {
      $out[(int) $r->participant_id] = ['reason' => (string) $r->reason, 'created' => (int) $r->created];
    }
    return $out;
  }

  /**
   * Writes one log row per cancelled entry. Never blocks the cancellation.
   */
  protected function recordRemovals(array $participant_ids, string $why): void {
    if (!$participant_ids) {
      return;
    }
    try {
      if (!$this->hasLog()) {
        return;
      }
      $q = $this->database->select('civicrm_participant', 'p');
      $q->leftJoin('civicrm_event__field_parent_course', 'pc', 'pc.entity_id = p.event_id AND pc.deleted = 0');
      $q->fields('p', ['id', 'contact_id', 'event_id']);
      $q->addField('pc', 'field_parent_course_target_id', 'course_nid');
      $q->condition('p.id', $participant_ids, 'IN');
      foreach ($q->execute() as $r) {
        $this->database->insert(self::LOG_TABLE)->fields([
          'kind' => 'removed',
          'contact_id' => (int) $r->contact_id,
          'course_nid' => (int) ($r->course_nid ?? 0),
          'event_id' => (int) $r->event_id,
          'participant_id' => (int) $r->id,
          'reason' => mb_substr($why, 0, 255),
          'created' => $this->time->getRequestTime(),
        ])->execute();
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Waitlist removal not written to the log: @m', ['@m' => $e->getMessage()]);
    }
  }

  /**
   * Signature for the leave link. Pure given its secret; unit tested.
   */
  public static function computeLeaveHash(int $contact_id, int $course_nid, string $secret): string {
    return Crypt::hmacBase64('waitlist-leave:' . $contact_id . ':' . $course_nid, $secret);
  }

  /**
   * The site's secret for leave links.
   */
  protected function secret(): string {
    return Settings::getHashSalt() . $this->privateKey->get();
  }

  /**
   * Signature for one person and course on this site.
   */
  public function leaveHash(int $contact_id, int $course_nid): string {
    return self::computeLeaveHash($contact_id, $course_nid, $this->secret());
  }

  /**
   * Whether a presented signature matches.
   */
  public function leaveHashValid(int $contact_id, int $course_nid, string $hash): bool {
    return hash_equals($this->leaveHash($contact_id, $course_nid), $hash);
  }

  /**
   * Absolute leave URL for one person and course.
   */
  public function leaveUrl(int $contact_id, int $course_nid): string {
    return Url::fromRoute('instructor_companion.waitlist_leave', [
      'contact' => $contact_id,
      'course' => $course_nid,
      'hash' => $this->leaveHash($contact_id, $course_nid),
    ], ['absolute' => TRUE])->toString();
  }

}
