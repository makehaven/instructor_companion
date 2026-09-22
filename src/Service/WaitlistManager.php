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
  public function activeWaitlistIds(int $contact_id, int $course_nid, ?int $except_event = NULL): array {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $q->innerJoin('civicrm_event__field_parent_course', 'pc', 'pc.entity_id = e.id AND pc.deleted = 0');
    $q->addField('p', 'id');
    $q->condition('p.contact_id', $contact_id)
      ->condition('p.is_test', 0)
      ->condition('p.status_id', [self::STATUS_WAITLIST, self::STATUS_OFFERED], 'IN')
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
      }
      catch (\Throwable $e) {
        $this->logger->warning('Could not cancel waitlist participant @p: @m', ['@p' => $pid, '@m' => $e->getMessage()]);
      }
    }
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
    $ids = $this->activeWaitlistIds($contact_id, $course_nid);
    return $ids ? $this->cancelParticipants($ids, "contact $contact_id left the waitlist for course $course_nid by email link") : 0;
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
