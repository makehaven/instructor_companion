<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Sends the post-class wrap-up reminder when tasks remain ~48h after class.
 *
 * Only fires when something is still outstanding.
 * Deliberately a custom Drupal cron pass, NOT a CiviCRM scheduled reminder:
 * CiviCRM's action_schedule is exactly the surface with an open duplicate-
 * reminder bug, and an admin-UI reminder can't ask PostEventStatusService
 * whether the instructor already finished. A per-event sent-once guard in
 * state makes this idempotent no matter how often cron runs.
 */
class PostEventReminderService {

  /**
   * State key: map of event_id => ['t' => unix_ts, 'sent' => bool].
   */
  protected const SENT_STATE_KEY = 'instructor_companion.post_event_reminder_sent';

  /**
   * Reminder fires once the class has been over this long.
   */
  protected const MIN_HOURS = 48;

  /**
   * Upper edge of the look-back window.
   *
   * Wider than the cron interval so a skipped cron still catches the event;
   * the sent-once guard prevents dupes.
   */
  protected const MAX_HOURS = 72;

  /**
   * Drop processed-event records older than this so state stays small.
   */
  protected const PRUNE_DAYS = 30;

  public function __construct(
    protected Connection $database,
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PostEventStatusService $postEventStatus,
    protected MailManagerInterface $mailManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected ?SessionSchedule $sessions = NULL,
  ) {}

  /**
   * The session schedule (lazy for older service definitions).
   */
  protected function sessions(): SessionSchedule {
    return $this->sessions ??= \Drupal::service('instructor_companion.sessions');
  }

  /**
   * Cron entry point. Cheap on every run (the window query is tiny).
   */
  public function run(): void {
    $now = \Drupal::time()->getRequestTime();
    [$lower, $upper] = self::dueWindow($now);

    // Only classes that actually owe wrap-up. A meetup host owes no badges or
    // payment and a program's first session ends nothing, so reminding them
    // just teaches everyone that this email is noise.
    $types = PostEventStatusService::closeoutEventTypes();
    // "Over" means the end of the LAST session (SessionSchedule), so a
    // six-week workshop is not asked to wrap up after week one.
    $candidates = $this->sessions()->endedBetween($lower, $upper, function ($q) use ($types) {
      $q->addField('i', 'field_civi_event_instructor_target_id', 'uid');
      $q->innerJoin('civicrm_event__field_civi_event_instructor', 'i', 'e.id = i.entity_id AND i.deleted = 0');
      $q->condition('e.is_active', 1);
      $q->condition('e.is_template', 0);
      if ($types) {
        $q->condition('e.event_type_id', $types, 'IN');
      }
    });

    $sent = (array) $this->state->get(self::SENT_STATE_KEY, []);
    $sent = $this->prune($sent, $now);
    $processed = 0;

    foreach ($candidates as $row) {
      $row = (object) $row;
      $event_id = (int) $row->event_id;
      $uid = (int) $row->uid;
      if (isset($sent[$event_id]) || !$uid) {
        continue;
      }
      // Mark processed up-front so a mid-loop failure can't cause a resend.
      $sent[$event_id] = ['t' => $now, 'sent' => FALSE];

      // Skip dead/empty classes — nagging about a 0-attendee event is noise.
      if (!$this->hasCountedParticipants($event_id)) {
        continue;
      }

      $status = $this->postEventStatus->getStatus($event_id, $uid);
      if ($status['all_complete']) {
        continue;
      }

      if ($this->sendReminder($event_id, $uid, $status)) {
        $sent[$event_id]['sent'] = TRUE;
        $processed++;
      }
    }

    $this->state->set(self::SENT_STATE_KEY, $sent);
    if ($processed) {
      $this->logger->notice('Post-class reminders sent: @n.', ['@n' => $processed]);
    }
  }

  /**
   * Returns [lower, upper] 'Y-m-d H:i:s' bounds for a class's end time.
   *
   * Pure — unit tested. civicrm_event stores local time in this format, and
   * lexicographic comparison on it is chronological.
   */
  public static function dueWindow(int $now): array {
    return [
      date('Y-m-d H:i:s', $now - self::MAX_HOURS * 3600),
      date('Y-m-d H:i:s', $now - self::MIN_HOURS * 3600),
    ];
  }

  /**
   * Drops sent-map records older than PRUNE_DAYS.
   */
  protected function prune(array $sent, int $now): array {
    $cutoff = $now - self::PRUNE_DAYS * 86400;
    return array_filter($sent, static fn(array $r): bool => ($r['t'] ?? 0) >= $cutoff);
  }

  /**
   * Whether an event has at least one counted, non-test participant.
   */
  protected function hasCountedParticipants(int $event_id): bool {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $q->condition('p.event_id', $event_id);
    $q->condition('p.is_test', 0);
    $q->condition('pst.is_counted', 1);
    $q->range(0, 1);
    return (bool) $q->countQuery()->execute()->fetchField();
  }

  /**
   * Sends the post-class reminder now, on a staff member's say-so.
   *
   * Bypasses the cron due-window and the already-sent record deliberately:
   * staff use this when the automatic one did not land or was ignored, and
   * they can see on the console exactly what is still outstanding.
   *
   * @return bool
   *   FALSE when the class has no instructor, the instructor has no email, or
   *   there is nothing left outstanding.
   */
  public function remindNow(int $event_id): bool {
    $uid = (int) $this->database->select('civicrm_event__field_civi_event_instructor', 'i')
      ->fields('i', ['field_civi_event_instructor_target_id'])
      ->condition('i.entity_id', $event_id)
      ->condition('i.deleted', 0)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if (!$uid) {
      return FALSE;
    }
    $status = $this->postEventStatus->getStatus($event_id, $uid);
    if ($status['all_complete']) {
      return FALSE;
    }
    if (!$this->sendReminder($event_id, $uid, $status)) {
      return FALSE;
    }
    $sent = (array) $this->state->get(self::SENT_STATE_KEY, []);
    $sent[$event_id] = ['t' => \Drupal::time()->getRequestTime(), 'sent' => TRUE];
    $this->state->set(self::SENT_STATE_KEY, $sent);
    $this->logger->notice('Post-class reminder for event @id re-sent to uid @uid by staff.', [
      '@id' => $event_id,
      '@uid' => $uid,
    ]);
    return TRUE;
  }

  /**
   * Sends the reminder email to the instructor.
   */
  protected function sendReminder(int $event_id, int $uid, array $status): bool {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user || !$user->getEmail()) {
      return FALSE;
    }
    $event = $this->entityTypeManager->getStorage('civicrm_event')->load($event_id);
    if (!$event) {
      return FALSE;
    }

    $params = [
      'instructor_name' => $user->getDisplayName(),
      'event_label' => $event->label(),
      'outstanding' => $status['incomplete_labels'],
      'hub_url' => Url::fromRoute('instructor_companion.post_event_hub',
        ['event_id' => $event_id], ['absolute' => TRUE])->toString(),
    ];

    $result = $this->mailManager->mail(
      'instructor_companion',
      'post_event_reminder',
      $user->getEmail(),
      $user->getPreferredLangcode(),
      $params,
      NULL,
      TRUE
    );
    return !empty($result['result']);
  }

}
