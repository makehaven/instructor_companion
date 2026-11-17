<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * The last days before a class: open seats, and the waiting list told.
 *
 * CiviCRM offers a freed seat to one waiting person at a time and holds it
 * for the offer window (24 h). Three days out that wastes the seat: one or
 * two unanswered offers and the class has started. JR, 2026-11-17: inside
 * the last three days there is no waiting list. A freed seat is open to
 * whoever registers first, and the waiting list is how we tell people.
 *
 * CiviCRM forces two things while anyone is "On waitlist": the event reads
 * as full to everyone (CRM_Event_BAO_Participant::eventFull) and that person
 * cannot register through the normal form ("already waitlisted"). Its job
 * also promotes them, with a 24 h hold. So inside the window:
 *
 *  - "On waitlist" and "Pending from waitlist" entries move to "Waitlist
 *    seat alerts" (Waiting class, not counted). CiviCRM promotes only "On
 *    waitlist" by name and blocks only on it, so seats open and these
 *    people can register like anyone else.
 *  - Each time open seats go up, everyone in that status gets one email.
 *    Openings within COMBINE_MINUTES of the last email wait and go out
 *    together. These emails are not "another run is open" notices and do
 *    not count toward the three-notice limit.
 *  - Someone who takes a seat leaves the list for that date.
 *  - Once the class is over (or moves back out of the window) the entries
 *    return to "On waitlist", so history and reports read as before.
 *
 * Runs from cron and after every participant save (sessions.inc), so a
 * cancellation is announced straight away.
 */
final class WaitlistOpenSeats {

  public const MAIL_KEY = 'waitlist_open_seat';
  public const STATE_KEY = 'instructor_companion.waitlist_open_seats';
  public const COMBINE_MINUTES = 60;
  public const DEFAULT_HOURS = 72;

  /**
   * Events being swept in this request, so our own saves do not recurse.
   *
   * @var array<int, bool>
   */
  private array $sweeping = [];

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly MailManagerInterface $mailManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly WaitlistManager $waitlist,
    private readonly CourseFollowerNotifier $notifier,
  ) {}

  /**
   * Hours before the start when holds stop (0 = off).
   */
  public function hours(): int {
    $v = $this->configFactory->get('instructor_companion.settings')->get('waitlist_open_seat_hours');
    return $v === NULL ? self::DEFAULT_HOURS : max(0, (int) $v);
  }

  /**
   * Whether an event start falls in the last-days window. Pure; unit tested.
   */
  public static function inWindow(int $start, int $now, int $hours): bool {
    return $hours > 0 && $start > $now && $start <= $now + $hours * 3600;
  }

  /**
   * Whether to email now. Pure; unit tested.
   *
   * @param int $open
   *   Open seats now.
   * @param int $last_open
   *   Open seats when the event was last looked at.
   * @param int $last_sent
   *   When the last email went out for this event (0 = never).
   * @param int $now
   *   Current time.
   *
   * @return string
   *   'send', 'wait' (seats went up but an email went out recently; keep
   *   last_open so a later sweep sends) or 'none'.
   */
  public static function verdict(int $open, int $last_open, int $last_sent, int $now): string {
    if ($open <= 0 || $open <= $last_open) {
      return 'none';
    }
    if ($last_sent && ($now - $last_sent) < self::COMBINE_MINUTES * 60) {
      return 'wait';
    }
    return 'send';
  }

  /**
   * Cron: restore past classes, then sweep every class in the window.
   *
   * @return int
   *   Emails sent.
   */
  public function run(): int {
    $alert = $this->waitlist->alertStatusId();
    if (!$alert || !$this->database->schema()->tableExists('civicrm_event')) {
      return 0;
    }
    $this->restore();
    $hours = $this->hours();
    if (!$hours) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $ids = $this->database->query(
      "SELECT DISTINCT e.id FROM {civicrm_event} e
       INNER JOIN {civicrm_participant} p ON p.event_id = e.id AND p.is_test = 0
       WHERE e.is_active = 1 AND e.is_template = 0 AND e.start_date > :now AND e.start_date <= :until
         AND p.status_id IN (:s[])",
      [
        ':now' => date('Y-m-d H:i:s', $now),
        ':until' => date('Y-m-d H:i:s', $now + $hours * 3600),
        ':s[]' => [WaitlistManager::STATUS_WAITLIST, WaitlistManager::STATUS_OFFERED, $alert],
      ]
    )->fetchCol();
    $sent = 0;
    foreach ($ids as $id) {
      $sent += $this->sweepEvent((int) $id);
    }
    $this->prune();
    return $sent;
  }

  /**
   * Applies the last-days rule to one event. Safe to call for any event.
   *
   * @return int
   *   Emails sent.
   */
  public function sweepEvent(int $event_id): int {
    $alert = $this->waitlist->alertStatusId();
    $hours = $this->hours();
    if (!$alert || !$hours || !$event_id || isset($this->sweeping[$event_id])) {
      return 0;
    }
    $event = $this->event($event_id);
    $now = $this->time->getRequestTime();
    if (!$event || !self::inWindow(strtotime((string) $event->start_date) ?: 0, $now, $hours)) {
      return 0;
    }
    if (empty($event->is_online_registration) || (int) $event->max_participants <= 0) {
      return 0;
    }
    $this->sweeping[$event_id] = TRUE;
    try {
      // 1. Nobody waits or holds an offer in the last days.
      $move = $this->participantIds($event_id, [WaitlistManager::STATUS_WAITLIST, WaitlistManager::STATUS_OFFERED]);
      if ($move) {
        $n = $this->setStatus($move, $alert);
        $this->logger->notice('Waitlist, last @h h before event @e: @n entr(ies) moved to seat alerts; their seats are open to anyone.', [
          '@h' => $hours,
          '@e' => $event_id,
          '@n' => $n,
        ]);
      }

      // 2. Anyone who has since taken a seat is off the list for this date.
      $done = $this->database->query(
        "SELECT a.id FROM {civicrm_participant} a
         WHERE a.event_id = :e AND a.is_test = 0 AND a.status_id = :alert
           AND EXISTS (SELECT 1 FROM {civicrm_participant} b WHERE b.event_id = a.event_id AND b.contact_id = a.contact_id
             AND b.is_test = 0 AND b.status_id IN (:counted[]))",
        [':e' => $event_id, ':alert' => $alert, ':counted[]' => WaitlistManager::COUNTED_STATUSES]
      )->fetchCol();
      if ($done) {
        $this->waitlist->cancelParticipants($done, "took a seat on event $event_id");
      }

      // 3. Tell the list when seats go up. No list, nothing to do.
      if (!$this->participantIds($event_id, [$alert])) {
        return 0;
      }
      $open = $this->openSeats($event_id);
      $states = (array) $this->state->get(self::STATE_KEY, []);
      $st = (array) ($states[$event_id] ?? []) + ['open' => 0, 'sent' => 0];
      $verdict = self::verdict($open, (int) $st['open'], (int) $st['sent'], $now);
      if (!empty($event->registration_end_date) && strtotime((string) $event->registration_end_date) < $now) {
        $verdict = 'none';
      }
      $sent = 0;
      if ($verdict === 'send') {
        $sent = $this->tell($event, $open);
        if ($sent) {
          // Only a real email starts the combine gap.
          $st['sent'] = $now;
        }
      }
      if ($verdict !== 'wait') {
        $st['open'] = $open;
      }
      $states[$event_id] = $st;
      $this->state->set(self::STATE_KEY, $states);
      return $sent;
    }
    finally {
      unset($this->sweeping[$event_id]);
    }
  }

  /**
   * Puts seat-alert entries back on the waiting list once their class is
   * over, or when it moved back out of the window.
   *
   * @return int
   *   Entries restored.
   */
  public function restore(): int {
    $alert = $this->waitlist->alertStatusId();
    if (!$alert) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $hours = $this->hours();
    $ids = $this->database->query(
      "SELECT p.id FROM {civicrm_participant} p
       INNER JOIN {civicrm_event} e ON e.id = p.event_id
       WHERE p.status_id = :alert
         AND (COALESCE(e.end_date, e.start_date) < :now OR e.start_date > :until OR e.is_active = 0)",
      [
        ':alert' => $alert,
        ':now' => date('Y-m-d H:i:s', $now),
        // Off (0 h): everything outside a class in progress goes back.
        ':until' => date('Y-m-d H:i:s', $now + $hours * 3600),
      ]
    )->fetchCol();
    if (!$ids) {
      return 0;
    }
    $n = $this->setStatus(array_map('intval', $ids), WaitlistManager::STATUS_WAITLIST);
    $this->logger->notice('Waitlist: @n seat-alert entr(ies) back to On waitlist (class over or no longer in its last days).', ['@n' => $n]);
    return $n;
  }

  /**
   * Who to tell about an event: primary seat-alert entries without a seat.
   *
   * @return array<string, array{contact_id:int,name:string,email:string}>
   *   Keyed by lower-cased email.
   */
  public function recipients(int $event_id): array {
    $alert = $this->waitlist->alertStatusId();
    if (!$alert) {
      return [];
    }
    $rows = $this->database->query(
      "SELECT p.contact_id, c.first_name, c.display_name, em.email
       FROM {civicrm_participant} p
       INNER JOIN {civicrm_contact} c ON c.id = p.contact_id
       INNER JOIN {civicrm_email} em ON em.contact_id = c.id AND em.is_primary = 1
       WHERE p.event_id = :e AND p.is_test = 0 AND p.status_id = :alert AND p.registered_by_id IS NULL
         AND c.is_deleted = 0 AND c.is_deceased = 0 AND c.do_not_email = 0 AND c.is_opt_out = 0
         AND em.on_hold = 0 AND em.email <> ''
         AND NOT EXISTS (SELECT 1 FROM {civicrm_participant} b WHERE b.event_id = p.event_id AND b.contact_id = p.contact_id
           AND b.is_test = 0 AND b.status_id IN (:counted[]))
       ORDER BY p.register_date",
      [':e' => $event_id, ':alert' => $alert, ':counted[]' => WaitlistManager::COUNTED_STATUSES]
    );
    $out = [];
    foreach ($rows as $r) {
      $key = mb_strtolower((string) $r->email);
      if (isset($out[$key])) {
        continue;
      }
      $first = trim((string) $r->first_name);
      $out[$key] = [
        'contact_id' => (int) $r->contact_id,
        'name' => $first !== '' ? $first : (string) $r->display_name,
        'email' => (string) $r->email,
      ];
    }
    return $out;
  }

  /**
   * Builds the email. Pure; unit tested.
   *
   * @return array{subject:string, body:string}
   *   Subject and plain-text body.
   */
  public static function compose(string $name, string $title, string $start, int $seats, string $register_url, string $leave_url = ''): array {
    $ts = strtotime($start) ?: 0;
    $when = $ts ? date('l, F j \a\t g:i A', $ts) : '';
    $seat_words = $seats === 1 ? 'A seat just opened' : sprintf('%d seats just opened', $seats);
    $subject = sprintf('%s: %s', $seat_words, $title);
    $lines = [
      sprintf('Hi %s,', $name),
      '',
      sprintf('%s in %s%s. You are on the waiting list, so you are hearing about it now.', $seat_words, $title, $when !== '' ? ', ' . $when : ''),
      '',
      sprintf('%s to whoever registers first: %s', $seats === 1 ? 'It goes' : 'They go', $register_url),
      '',
      sprintf('If %s by the time you get there, you stay on the waiting list and we will email you if another seat opens before the class.', $seats === 1 ? 'it is gone' : 'they are gone'),
    ];
    if ($leave_url !== '') {
      $lines[] = '';
      $lines[] = sprintf('No longer interested? One click takes you off the waiting list: %s', $leave_url);
    }
    $lines[] = '';
    $lines[] = 'The MakeHaven Team';
    $lines[] = 'www.makehaven.org';
    return ['subject' => $subject, 'body' => implode("\n", $lines)];
  }

  /**
   * Emails everyone on the list. Returns how many were sent.
   */
  private function tell(object $event, int $open): int {
    $event_id = (int) $event->id;
    $course = (int) ($this->waitlist->courseOf($event_id) ?? 0);
    $register_url = $this->notifier->baseUrl() . '/civicrm/event/register?reset=1&id=' . $event_id;
    $sent = 0;
    foreach ($this->recipients($event_id) as $r) {
      $leave = $course ? $this->waitlist->leaveUrl($r['contact_id'], $course) : '';
      $message = self::compose($r['name'], (string) $event->title, (string) $event->start_date, $open, $register_url, $leave);
      $result = $this->mailManager->mail(
        'instructor_companion',
        self::MAIL_KEY,
        $r['email'],
        \Drupal::languageManager()->getDefaultLanguage()->getId(),
        ['subject' => $message['subject'], 'body' => $message['body']],
        NULL,
        TRUE
      );
      if (empty($result['result'])) {
        $this->logger->warning('Open-seat email to @mail about event @e did not send.', ['@mail' => $r['email'], '@e' => $event_id]);
        continue;
      }
      $sent++;
      $this->log($r['contact_id'], $course, $event_id, sprintf('%d open seat(s)', $open));
    }
    $this->logger->notice('Waitlist: @n person(s) told that @s seat(s) opened on event @e "@t".', [
      '@n' => $sent,
      '@s' => $open,
      '@e' => $event_id,
      '@t' => $event->title,
    ]);
    return $sent;
  }

  /**
   * One log row per email (kind seat_alert; never a strike).
   */
  private function log(int $contact_id, int $course_nid, int $event_id, string $reason): void {
    try {
      if (!$this->database->schema()->tableExists(WaitlistManager::LOG_TABLE)) {
        return;
      }
      $this->database->insert(WaitlistManager::LOG_TABLE)->fields([
        'kind' => 'seat_alert',
        'contact_id' => $contact_id,
        'course_nid' => $course_nid,
        'event_id' => $event_id,
        'participant_id' => 0,
        'reason' => $reason,
        'created' => $this->time->getRequestTime(),
      ])->execute();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Open-seat email not written to the log: @m', ['@m' => $e->getMessage()]);
    }
  }

  /**
   * The event row, or NULL.
   */
  private function event(int $event_id): ?object {
    $row = $this->database->select('civicrm_event', 'e')
      ->fields('e', ['id', 'title', 'start_date', 'end_date', 'max_participants', 'is_online_registration', 'registration_end_date', 'is_active', 'is_template'])
      ->condition('e.id', $event_id)
      ->execute()->fetchObject();
    if (!$row || empty($row->is_active) || !empty($row->is_template)) {
      return NULL;
    }
    return $row;
  }

  /**
   * Participant ids on an event in the given statuses.
   *
   * @return int[]
   *   Ids.
   */
  private function participantIds(int $event_id, array $statuses): array {
    return array_map('intval', $this->database->select('civicrm_participant', 'p')
      ->fields('p', ['id'])
      ->condition('p.event_id', $event_id)
      ->condition('p.is_test', 0)
      ->condition('p.status_id', $statuses, 'IN')
      ->execute()->fetchCol());
  }

  /**
   * Open seats, the way CiviCRM's registration form counts them.
   */
  private function openSeats(int $event_id): int {
    try {
      \Drupal::service('civicrm')->initialize();
      // Not counting the waiting list: in the window nobody is On waitlist.
      $v = \CRM_Event_BAO_Participant::eventFull($event_id, TRUE, FALSE);
      return is_numeric($v) ? max(0, (int) $v) : 0;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Open seats for event @e could not be counted: @m', ['@e' => $event_id, '@m' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Moves entries between the two waiting statuses.
   *
   * A plain UPDATE, the way CiviCRM's own waitlist job changes statuses
   * (CRM_Event_BAO_Participant::updateStatus): no mail, no hooks. Hooks
   * would re-enter this sweep and wake integrations (door passes) for a
   * change that gives nobody a seat. register_date is left alone, so the
   * order of the list survives the round trip.
   *
   * @return int
   *   How many changed.
   */
  private function setStatus(array $participant_ids, int $status_id): int {
    $participant_ids = array_values(array_filter(array_map('intval', $participant_ids)));
    if (!$participant_ids) {
      return 0;
    }
    return (int) $this->database->update('civicrm_participant')
      ->fields(['status_id' => $status_id])
      ->condition('id', $participant_ids, 'IN')
      ->execute();
  }

  /**
   * Drops state for events that have started.
   */
  private function prune(): void {
    $states = (array) $this->state->get(self::STATE_KEY, []);
    if (!$states) {
      return;
    }
    $started = $this->database->select('civicrm_event', 'e')
      ->fields('e', ['id'])
      ->condition('e.id', array_keys($states), 'IN')
      ->condition('e.start_date', date('Y-m-d H:i:s', $this->time->getRequestTime()), '<')
      ->execute()->fetchCol();
    $gone = array_diff(array_keys($states), array_map('intval', $this->database->select('civicrm_event', 'e')
      ->fields('e', ['id'])
      ->condition('e.id', array_keys($states), 'IN')
      ->execute()->fetchCol()));
    foreach (array_merge($started, $gone) as $id) {
      unset($states[(int) $id]);
    }
    $this->state->set(self::STATE_KEY, $states);
  }

}
