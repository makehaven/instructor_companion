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
 */
class AttendanceManager {

  /**
   * CiviCRM participant_status_type names this flow toggles between.
   */
  protected const STATUS_ATTENDED = 'Attended';
  protected const STATUS_NO_SHOW = 'No-show';

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
   * Applies attendance to a roster.
   *
   * Present participant IDs become Attended; every other supplied roster ID
   * becomes No-show.
   *
   * @param int[] $all_participant_ids
   *   Every participant row shown on the form.
   * @param int[] $present_participant_ids
   *   The subset the instructor marked present.
   *
   * @return int
   *   Count of participant rows whose status was changed.
   */
  public function applyAttendance(array $all_participant_ids, array $present_participant_ids): int {
    $ids = $this->statusIds();
    if (!$ids['attended'] || !$ids['no_show']) {
      $this->logger->error('Could not resolve Attended/No-show participant status IDs; attendance not saved.');
      return 0;
    }
    $present = array_flip(array_map('intval', $present_participant_ids));
    $changed = 0;
    foreach (array_map('intval', $all_participant_ids) as $pid) {
      $target = isset($present[$pid]) ? $ids['attended'] : $ids['no_show'];
      if ($this->setParticipantStatus($pid, $target)) {
        $changed++;
      }
    }
    return $changed;
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
   * Adds a walk-in: ensures the contact is a participant marked Attended.
   *
   * Idempotent — if the contact already has a participant row for the event
   * it just flips that row to Attended rather than creating a duplicate.
   *
   * @return bool
   *   TRUE on success.
   */
  public function addWalkIn(int $event_id, int $contact_id): bool {
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
    if ($existing) {
      return $this->setParticipantStatus($existing, $ids['attended']);
    }
    try {
      \Drupal::service('civicrm')->initialize();
      Participant::create(FALSE)
        ->addValue('event_id', $event_id)
        ->addValue('contact_id', $contact_id)
        ->addValue('status_id', $ids['attended'])
        ->execute();
      return TRUE;
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
