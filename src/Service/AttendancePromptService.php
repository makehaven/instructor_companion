<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Asks the instructor to take attendance while the class is in the room.
 *
 * Attendance is not paperwork, it is a door activity: it records who actually
 * turned up (so no-shows can be followed up), and it is the moment someone who
 * never registered gets noticed. Both of those only work if it happens at the
 * start of the session — an instructor asked two days later is guessing.
 *
 * Until 2026-09-09 the only prompt was the post-class reminder, 48-72 hours
 * after the event, which is why attendance had been confirmed on two classes
 * ever while 301 of 383 participants sat at "Registered". This runs on the
 * same cron, shortly after the class begins, and asks for one thing.
 */
class AttendancePromptService {

  /**
   * State key holding the events already prompted.
   */
  protected const SENT_STATE_KEY = 'instructor_companion.attendance_prompt_sent';

  /**
   * Default minutes after the start time before prompting.
   *
   * Not zero: the instructor is greeting people and setting up at the bell.
   * Late arrivals are handled by the form itself, which can be re-saved.
   */
  public const DEFAULT_OFFSET_MINUTES = 15;

  /**
   * How long after the offset a class stays eligible, in hours.
   *
   * Wide enough that an hourly cron cannot skip a class, narrow enough that a
   * prompt never arrives after everyone has gone home.
   */
  protected const WINDOW_HOURS = 4;

  /**
   * How long a sent record is kept, in seconds.
   */
  protected const PRUNE_AFTER = 7 * 86400;

  public function __construct(
    protected Connection $database,
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PostEventStatusService $postEventStatus,
    protected MailManagerInterface $mailManager,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Cron entry point.
   */
  public function run(): void {
    if (!$this->isEnabled()) {
      return;
    }
    $now = $this->time->getRequestTime();
    [$lower, $upper] = $this->dueWindow($now);

    $q = $this->database->select('civicrm_event', 'e');
    $q->innerJoin('civicrm_event__field_civi_event_instructor', 'i', 'e.id = i.entity_id AND i.deleted = 0');
    $q->addField('e', 'id', 'event_id');
    $q->addField('i', 'field_civi_event_instructor_target_id', 'uid');
    $q->where('e.start_date >= :lo AND e.start_date <= :hi', [':lo' => $lower, ':hi' => $upper]);
    $q->condition('e.is_active', 1);
    $q->condition('e.is_template', 0);
    $types = PostEventStatusService::closeoutEventTypes();
    if ($types) {
      $q->condition('e.event_type_id', $types, 'IN');
    }

    $sent = $this->prune((array) $this->state->get(self::SENT_STATE_KEY, []), $now);
    $prompted = 0;

    foreach ($q->execute() as $row) {
      $event_id = (int) $row->event_id;
      $uid = (int) $row->uid;
      if (isset($sent[$event_id]) || !$uid) {
        continue;
      }
      // Mark up-front so a mid-loop failure cannot double-send.
      $sent[$event_id] = ['t' => $now, 'sent' => FALSE];

      // Nothing to take attendance for.
      if (!$this->postEventStatus->countedParticipants($event_id)) {
        continue;
      }
      // Already done — some instructors are ahead of us.
      if ($this->postEventStatus->isAttendanceConfirmed($event_id)) {
        continue;
      }
      if ($this->send($event_id, $uid)) {
        $sent[$event_id]['sent'] = TRUE;
        $prompted++;
      }
    }

    $this->state->set(self::SENT_STATE_KEY, $sent);
    if ($prompted) {
      $this->logger->notice('Attendance prompts sent at class start: @n.', ['@n' => $prompted]);
    }
  }

  /**
   * Whether the prompt is switched on.
   */
  public function isEnabled(): bool {
    return (bool) ($this->configFactory->get('instructor_companion.settings')
      ->get('attendance_prompt_enabled') ?? TRUE);
  }

  /**
   * Minutes after the start time before prompting.
   */
  public function offsetMinutes(): int {
    $configured = (int) ($this->configFactory->get('instructor_companion.settings')
      ->get('attendance_prompt_offset_minutes') ?? 0);
    return $configured > 0 ? $configured : self::DEFAULT_OFFSET_MINUTES;
  }

  /**
   * The start_date range eligible right now.
   *
   * civicrm_event.start_date is stored in the site's local timezone, which is
   * what date() produces here.
   *
   * @return string[]
   *   [lower, upper] as 'Y-m-d H:i:s'.
   */
  public function dueWindow(int $now): array {
    $offset = $this->offsetMinutes() * 60;
    return [
      date('Y-m-d H:i:s', $now - $offset - self::WINDOW_HOURS * 3600),
      date('Y-m-d H:i:s', $now - $offset),
    ];
  }

  /**
   * Drops records older than PRUNE_AFTER.
   */
  protected function prune(array $sent, int $now): array {
    return array_filter($sent, static fn(array $r): bool => ($r['t'] ?? 0) > $now - self::PRUNE_AFTER);
  }

  /**
   * Emails the instructor a direct link to the attendance list.
   */
  protected function send(int $event_id, int $uid): bool {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user || !$user->getEmail()) {
      return FALSE;
    }
    $event = $this->entityTypeManager->getStorage('civicrm_event')->load($event_id);
    if (!$event) {
      return FALSE;
    }

    $result = $this->mailManager->mail(
      'instructor_companion',
      'attendance_prompt',
      $user->getEmail(),
      $user->getPreferredLangcode(),
      [
        'instructor_name' => $user->getDisplayName(),
        'event_label' => $event->label(),
        'attendance_url' => Url::fromRoute('instructor_companion.attendance',
          ['event_id' => $event_id], ['absolute' => TRUE])->toString(),
        'registered' => $this->postEventStatus->countedParticipants($event_id),
      ],
      NULL,
      TRUE,
    );
    return !empty($result['result']);
  }

}
