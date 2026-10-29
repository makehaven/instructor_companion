<?php

namespace Drupal\instructor_companion\Service;

use Civi\Api4\Participant;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes CiviCRM event attendance for the instructor flow.
 *
 * Reads go through direct DB queries (the established pattern in this module);
 * writes go through CiviCRM API4 with checkPermissions disabled, because the
 * instructor saving attendance does not hold CiviCRM "edit participants"
 * permission but is the authorised teacher of the class (route access is
 * already gated by ClassCheckoutController::access).
 *
 * Sessions: marks are stored per session in `instructor_companion_attendance`
 * and the CiviCRM participant status is DERIVED from them — present at any
 * session means Attended, never present means No-show. Saving session 2 can
 * therefore never erase session 1, and the first date someone turned up is
 * kept. A one-session class is just the degenerate case (one session).
 */
class AttendanceManager {

  /**
   * CiviCRM participant_status_type names this flow toggles between.
   */
  protected const STATUS_ATTENDED = 'Attended';
  protected const STATUS_NO_SHOW = 'No-show';

  /**
   * Marks table.
   */
  public const TABLE = 'instructor_companion_attendance';

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns the roster for an event.
   *
   * @return array[]
   *   Each row: participant_id, contact_id, name, status_name, is_attended
   *   (bool), uid (int|null Drupal account).
   */
  public function getRoster(int $event_id): array {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_contact', 'c', 'c.id = p.contact_id');
    $q->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $q->leftJoin('civicrm_uf_match', 'ufm', 'ufm.contact_id = p.contact_id');
    $q->fields('p', ['id', 'contact_id']);
    $q->addField('c', 'display_name', 'name');
    $q->addField('pst', 'name', 'status_name');
    $q->addField('ufm', 'uf_id', 'uid');
    $q->condition('p.event_id', $event_id);
    $q->condition('p.is_test', 0);
    // Drop hard-cancelled / rejected / transferred so the list stays the
    // people the instructor might actually mark present.
    $q->condition('pst.name', ['Cancelled', 'Rejected', 'Transferred', 'Expired'], 'NOT IN');
    $q->orderBy('c.display_name');

    $rows = [];
    foreach ($q->execute() as $r) {
      $rows[] = [
        'participant_id' => (int) $r->id,
        'contact_id' => (int) $r->contact_id,
        'name' => (string) $r->name,
        'status_name' => (string) $r->status_name,
        'is_attended' => $r->status_name === self::STATUS_ATTENDED,
        'uid' => $r->uid !== NULL ? (int) $r->uid : NULL,
      ];
    }
    return $rows;
  }

  /**
   * Resolves participant_status_type IDs by name (never hardcode the ints).
   *
   * @return array
   *   ['attended' => int, 'no_show' => int].
   */
  public function statusIds(): array {
    $map = $this->database->select('civicrm_participant_status_type', 's')
      ->fields('s', ['name', 'id'])
      ->condition('s.name', [self::STATUS_ATTENDED, self::STATUS_NO_SHOW], 'IN')
      ->execute()
      ->fetchAllKeyed();
    return [
      'attended' => (int) ($map[self::STATUS_ATTENDED] ?? 0),
      'no_show' => (int) ($map[self::STATUS_NO_SHOW] ?? 0),
    ];
  }

  /**
   * Per-session marks for an event.
   *
   * @return array<string, array<int, bool>>
   *   session_start => [participant_id => present], sessions in order.
   */
  public function getMarks(int $event_id): array {
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return [];
    }
    $q = $this->database->select(self::TABLE, 'a')
      ->fields('a', ['session_start', 'participant_id', 'present'])
      ->condition('a.event_id', $event_id)
      ->orderBy('a.session_start');
    $out = [];
    foreach ($q->execute() as $r) {
      $out[(string) $r->session_start][(int) $r->participant_id] = (bool) $r->present;
    }
    return $out;
  }

  /**
   * Sessions (local starts) that have been saved at least once for an event.
   *
   * @return string[]
   *   Local 'Y-m-d H:i:s' session starts, in order.
   */
  public function sessionsTaken(int $event_id): array {
    return array_keys($this->getMarks($event_id));
  }

  /**
   * Pure: derives each participant's summary from the per-session marks.
   *
   * @param array<string, array<int, bool>> $marks
   *   As returned by getMarks().
   *
   * @return array<int, array>
   *   participant_id => ['present' => int sessions present, 'marked' => int
   *   sessions they were on a saved roster for, 'first' => local start of
   *   the first session they attended or NULL, 'attended' => bool].
   */
  public static function summarize(array $marks): array {
    $out = [];
    ksort($marks);
    foreach ($marks as $session => $people) {
      foreach ($people as $pid => $present) {
        $pid = (int) $pid;
        $out[$pid] ??= ['present' => 0, 'marked' => 0, 'first' => NULL, 'attended' => FALSE];
        $out[$pid]['marked']++;
        if ($present) {
          $out[$pid]['present']++;
          $out[$pid]['attended'] = TRUE;
          $out[$pid]['first'] ??= (string) $session;
        }
      }
    }
    return $out;
  }

  /**
   * Saves one session's roster and re-derives the CiviCRM statuses.
   *
   * @param int $event_id
   *   The event.
   * @param string $session_start
   *   Local 'Y-m-d H:i:s' start of the session being recorded.
   * @param int[] $all_participant_ids
   *   Every participant row shown on the form.
   * @param int[] $present_participant_ids
   *   The subset the instructor marked present at THIS session.
   * @param int $recorded_by
   *   Drupal uid saving it.
   *
   * @return int
   *   Count of participant rows whose CiviCRM status changed.
   */
  public function recordSession(int $event_id, string $session_start, array $all_participant_ids, array $present_participant_ids, int $recorded_by = 0): int {
    $present = array_flip(array_map('intval', $present_participant_ids));
    $all = array_values(array_unique(array_map('intval', $all_participant_ids)));
    $contacts = $this->contactIdsFor($all);
    $now = \Drupal::time()->getRequestTime();
    $session_start = substr($session_start, 0, 19);

    if ($this->database->schema()->tableExists(self::TABLE)) {
      foreach ($all as $pid) {
        $this->database->merge(self::TABLE)
          ->keys([
            'event_id' => $event_id,
            'session_start' => $session_start,
            'participant_id' => $pid,
          ])
          ->fields([
            'contact_id' => $contacts[$pid] ?? 0,
            'present' => isset($present[$pid]) ? 1 : 0,
            'recorded_by' => $recorded_by,
            'recorded' => $now,
          ])
          ->execute();
      }
    }
    return $this->applyDerivedStatuses($event_id, $all, $present);
  }

  /**
   * Applies attendance to a roster (legacy single-session entry point).
   *
   * Kept for callers that do not know about sessions: records the marks
   * against the event's first session, then derives.
   *
   * @return int
   *   Count of participant rows whose status was changed.
   */
  public function applyAttendance(array $all_participant_ids, array $present_participant_ids): int {
    $pid = (int) (reset($all_participant_ids) ?: 0);
    $event_id = $pid ? (int) $this->database->select('civicrm_participant', 'p')
      ->fields('p', ['event_id'])
      ->condition('p.id', $pid)
      ->execute()
      ->fetchField() : 0;
    if (!$event_id) {
      return 0;
    }
    $start = (string) $this->database->select('civicrm_event', 'e')
      ->fields('e', ['start_date'])
      ->condition('e.id', $event_id)
      ->execute()
      ->fetchField();
    return $this->recordSession($event_id, $start ?: date('Y-m-d H:i:s'), $all_participant_ids, $present_participant_ids);
  }

  /**
   * Writes Attended / No-show to CiviCRM from the accumulated marks.
   *
   * @param int $event_id
   *   The event.
   * @param int[] $roster
   *   Participant ids on the form just saved (the ones whose status may
   *   need re-deriving).
   * @param array<int, mixed> $present_now
   *   Flipped set of ids present at the session just saved — a fallback for
   *   when the marks table is unavailable.
   */
  protected function applyDerivedStatuses(int $event_id, array $roster, array $present_now): int {
    $ids = $this->statusIds();
    if (!$ids['attended'] || !$ids['no_show']) {
      $this->logger->error('Could not resolve Attended/No-show participant status IDs; attendance not saved.');
      return 0;
    }
    $summary = self::summarize($this->getMarks($event_id));
    $current = $this->currentStatusIds($roster);
    $changed = 0;
    foreach ($roster as $pid) {
      $attended = isset($summary[$pid]) ? $summary[$pid]['attended'] : isset($present_now[$pid]);
      $target = $attended ? $ids['attended'] : $ids['no_show'];
      if (($current[$pid] ?? 0) === $target) {
        continue;
      }
      if ($this->setParticipantStatus($pid, $target)) {
        $changed++;
      }
    }
    return $changed;
  }

  /**
   * Participant_id => status_id for a set of participants.
   */
  protected function currentStatusIds(array $participant_ids): array {
    if (!$participant_ids) {
      return [];
    }
    return array_map('intval', $this->database->select('civicrm_participant', 'p')
      ->fields('p', ['id', 'status_id'])
      ->condition('p.id', $participant_ids, 'IN')
      ->execute()
      ->fetchAllKeyed());
  }

  /**
   * Participant_id => contact_id for a set of participants.
   */
  protected function contactIdsFor(array $participant_ids): array {
    if (!$participant_ids) {
      return [];
    }
    return array_map('intval', $this->database->select('civicrm_participant', 'p')
      ->fields('p', ['id', 'contact_id'])
      ->condition('p.id', $participant_ids, 'IN')
      ->execute()
      ->fetchAllKeyed());
  }

  /**
   * Resolves a CiviCRM contact_id from a MakeHaven account email.
   *
   * Phase 1 walk-in support: the person must already have a MakeHaven account
   * (and therefore a CiviCRM contact via uf_match). Creating brand-new
   * contacts from the attendance screen is intentionally out of scope.
   */
  public function findContactIdByAccountEmail(string $email): ?int {
    $email = trim($email);
    if ($email === '') {
      return NULL;
    }
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['mail' => $email]);
    if (!$users) {
      return NULL;
    }
    $user = reset($users);
    $contact_id = $this->database->select('civicrm_uf_match', 'm')
      ->fields('m', ['contact_id'])
      ->condition('m.uf_id', (int) $user->id())
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $contact_id ? (int) $contact_id : NULL;
  }

  /**
   * The guest waiver webform (signed on a phone at the door).
   */
  public const GUEST_WAIVER_WEBFORM = 'guest_waiver';

  /**
   * Whether this person has signed a MakeHaven waiver we can find.
   *
   * JR 2026-10-28: walk-ins should have a digital waiver on file. The waiver
   * lives in three places, any of which counts:
   * - the class registration form's "Waiver Agreement" (participant field);
   * - the site terms every account accepts (legal module; the terms text is
   *   the "Guest Waiver & Release of Liability"), or the account's
   *   field_waiver_signed_at set by the guest waiver;
   * - a guest waiver submission linked to the contact.
   */
  public function waiverOnFile(int $contact_id, ?int $uid = NULL): bool {
    if ($contact_id <= 0) {
      return FALSE;
    }
    $schema = $this->database->schema();

    $col = $this->registrationWaiverColumn();
    if ($col) {
      $q = $this->database->select('civicrm_participant', 'p');
      $q->innerJoin($col['table'], 'w', 'w.entity_id = p.id');
      $q->condition('p.contact_id', $contact_id);
      $q->condition('w.' . $col['column'], '1');
      if ($q->countQuery()->execute()->fetchField()) {
        return TRUE;
      }
    }

    if ($uid === NULL) {
      $found = $this->database->select('civicrm_uf_match', 'm')
        ->fields('m', ['uf_id'])
        ->condition('m.contact_id', $contact_id)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      $uid = $found ? (int) $found : NULL;
    }
    if ($uid) {
      if ($schema->tableExists('legal_accepted')
        && $this->database->select('legal_accepted', 'l')->condition('l.uid', $uid)->countQuery()->execute()->fetchField()) {
        return TRUE;
      }
      if ($schema->tableExists('user__field_waiver_signed_at')
        && $this->database->select('user__field_waiver_signed_at', 'f')->condition('f.entity_id', $uid)->countQuery()->execute()->fetchField()) {
        return TRUE;
      }
    }

    return $this->guestWaiverContactQuery()
      ->condition('wcs.contact_id', '-' . $contact_id . '-%', 'LIKE')
      ->countQuery()->execute()->fetchField() > 0;
  }

  /**
   * The contact of the latest guest waiver signed with this email, or NULL.
   *
   * Lets an instructor add a walk-in who has no account: they sign the
   * guest waiver on their phone, which makes the CiviCRM contact.
   */
  public function findContactIdByGuestWaiverEmail(string $email): ?int {
    $email = trim($email);
    if ($email === '') {
      return NULL;
    }
    $q = $this->guestWaiverContactQuery();
    $q->innerJoin('webform_submission_data', 'd', "d.sid = ws.sid AND d.name = 'civicrm_1_contact_1_email_email'");
    $q->condition('d.value', $email);
    $q->fields('wcs', ['contact_id']);
    $q->orderBy('ws.created', 'DESC');
    $q->range(0, 1);
    $raw = (string) $q->execute()->fetchField();
    return preg_match('/^-(\d+)-/', $raw, $m) && (int) $m[1] > 0 ? (int) $m[1] : NULL;
  }

  /**
   * Guest waiver submissions joined to the contacts webform_civicrm made.
   */
  protected function guestWaiverContactQuery() {
    $q = $this->database->select('webform_submission', 'ws');
    $q->innerJoin('webform_civicrm_submissions', 'wcs', 'wcs.sid = ws.sid');
    $q->condition('ws.webform_id', self::GUEST_WAIVER_WEBFORM);
    return $q;
  }

  /**
   * Table and column of the registration form's "Waiver Agreement" field.
   *
   * Looked up by name, not hardcoded (custom_63 on live today).
   *
   * @return array{table: string, column: string}|null
   *   NULL when the field does not exist.
   */
  protected function registrationWaiverColumn(): ?array {
    static $col = FALSE;
    if ($col === FALSE) {
      $q = $this->database->select('civicrm_custom_field', 'f');
      $q->innerJoin('civicrm_custom_group', 'g', 'g.id = f.custom_group_id');
      $q->addField('g', 'table_name', 'table');
      $q->addField('f', 'column_name', 'column');
      $q->condition('f.name', 'Waiver_Agreement');
      $q->condition('g.extends', 'Participant');
      $q->range(0, 1);
      $row = $q->execute()->fetchAssoc();
      $col = $row ?: NULL;
    }
    return $col;
  }

  /**
   * Adds a walk-in: ensures the contact is a participant marked Attended.
   *
   * Idempotent — if the contact already has a participant row for the event
   * it just flips that row to Attended rather than creating a duplicate.
   * When a session is given, the walk-in is also recorded present at it.
   *
   * @return bool
   *   TRUE on success.
   */
  public function addWalkIn(int $event_id, int $contact_id, ?string $session_start = NULL, int $recorded_by = 0): bool {
    $existing = (int) $this->database->select('civicrm_participant', 'p')
      ->fields('p', ['id'])
      ->condition('p.event_id', $event_id)
      ->condition('p.contact_id', $contact_id)
      ->condition('p.is_test', 0)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    $ids = $this->statusIds();
    if (!$ids['attended']) {
      return FALSE;
    }
    $pid = $existing;
    if (!$existing) {
      try {
        \Drupal::service('civicrm')->initialize();
        $created = Participant::create(FALSE)
          ->addValue('event_id', $event_id)
          ->addValue('contact_id', $contact_id)
          ->addValue('status_id', $ids['attended'])
          ->execute()
          ->first();
        $pid = (int) ($created['id'] ?? 0);
      }
      catch (\Throwable $e) {
        $this->logger->error('Walk-in add failed for contact @c on event @e: @m', [
          '@c' => $contact_id,
          '@e' => $event_id,
          '@m' => $e->getMessage(),
        ]);
        return FALSE;
      }
    }
    if ($pid && $session_start !== NULL && $this->database->schema()->tableExists(self::TABLE)) {
      $this->database->merge(self::TABLE)
        ->keys([
          'event_id' => $event_id,
          'session_start' => substr($session_start, 0, 19),
          'participant_id' => $pid,
        ])
        ->fields([
          'contact_id' => $contact_id,
          'present' => 1,
          'recorded_by' => $recorded_by,
          'recorded' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();
    }
    return $existing ? $this->setParticipantStatus($existing, $ids['attended']) : (bool) $pid;
  }

  /**
   * Sets one participant's status via API4 (permission checks disabled).
   */
  protected function setParticipantStatus(int $participant_id, int $status_id): bool {
    try {
      \Drupal::service('civicrm')->initialize();
      Participant::update(FALSE)
        ->addValue('status_id', $status_id)
        ->addWhere('id', '=', $participant_id)
        ->execute();
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to set participant @p status @s: @m', [
        '@p' => $participant_id,
        '@s' => $status_id,
        '@m' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
