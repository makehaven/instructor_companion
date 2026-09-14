<?php

namespace Drupal\instructor_companion\Commands;

use Drupal\instructor_companion\Service\AttendanceManager;
use Drupal\instructor_companion\Service\SessionEvaluationService;
use Drupal\instructor_companion\Service\SessionSchedule;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for multi-session classes.
 */
class SessionCommands extends DrushCommands {

  public function __construct(
    protected SessionSchedule $sessions,
    protected SessionEvaluationService $evaluation,
    protected AttendanceManager $attendance,
  ) {
    parent::__construct();
  }

  /**
   * Show the session schedule and attendance for an event.
   *
   * @param int $event_id
   *   CiviCRM event id.
   *
   * @command instructor-companion:sessions
   * @aliases ic-sessions
   * @usage instructor-companion:sessions 932
   */
  public function show(int $event_id): void {
    $schedule = $this->sessions->getSchedule($event_id);
    if (!$schedule) {
      $this->logger()->error(dt('Event @id not found.', ['@id' => $event_id]));
      return;
    }
    $this->output()->writeln(dt('Event @id: @n session(s), over at @end', [
      '@id' => $event_id,
      '@n' => count($schedule),
      '@end' => $this->sessions->effectiveEnd($event_id),
    ]));
    $marks = $this->attendance->getMarks($event_id);
    foreach ($schedule as $s) {
      $taken = isset($marks[$s['start']]);
      $present = $taken ? count(array_filter($marks[$s['start']])) : 0;
      $note = $taken ? sprintf('  attendance saved: %d present / %d marked', $present, count($marks[$s['start']])) : '';
      $this->output()->writeln(sprintf('  %d. %s – %s%s', $s['index'] + 1, $s['start'], substr($s['end'], 11, 5), $note));
    }
    $summary = AttendanceManager::summarize($marks);
    if ($summary) {
      $never = count(array_filter($summary, static fn(array $r): bool => !$r['attended']));
      $this->output()->writeln(dt('  @a attended at least once, @n never came.', [
        '@a' => count($summary) - $never,
        '@n' => $never,
      ]));
    }
  }

  /**
   * Set the session list for an event (local wall-clock dates).
   *
   * The event's own start date is always kept as the first session; dates
   * given here are added to it. Pass "generate" with --count/--interval to
   * build a weekly pattern from the start instead.
   *
   * @param int $event_id
   *   CiviCRM event id.
   * @param string $dates
   *   Comma-separated local dates ("2026-09-19 17:00, 2026-09-26 17:00"),
   *   or the word "generate".
   * @param array $options
   *   Drush options.
   *
   * @command instructor-companion:set-sessions
   * @aliases ic-set-sessions
   * @option count Number of sessions when generating.
   * @option interval Days between sessions when generating (default 7).
   * @option clear Remove the session list (single-session again).
   * @usage instructor-companion:set-sessions 932 "2026-09-19 17:00,2026-09-26 17:00,2026-10-03 17:00,2026-10-10 17:00,2026-10-17 17:00"
   * @usage instructor-companion:set-sessions 933 generate --count=6
   * @usage instructor-companion:set-sessions 933 "" --clear
   */
  public function set(
    int $event_id,
    string $dates = '',
    array $options = ['count' => 0, 'interval' => 7, 'clear' => FALSE],
  ): void {
    $schedule = $this->sessions->getSchedule($event_id);
    if (!$schedule) {
      $this->logger()->error(dt('Event @id not found.', ['@id' => $event_id]));
      return;
    }
    if (!empty($options['clear'])) {
      $this->sessions->setSessions($event_id, []);
      $this->logger()->success(dt('Event @id is single-session again.', ['@id' => $event_id]));
      return;
    }
    if (trim($dates) === 'generate') {
      $count = (int) $options['count'];
      if ($count < 2) {
        $this->logger()->error(dt('--count must be at least 2 to generate.'));
        return;
      }
      $starts = SessionSchedule::generate($schedule[0]['start'], $count, max(1, (int) $options['interval']));
    }
    else {
      $starts = array_filter(array_map('trim', explode(',', $dates)));
      foreach ($starts as $s) {
        if (strtotime($s) === FALSE) {
          $this->logger()->error(dt('Cannot read date "@d".', ['@d' => $s]));
          return;
        }
      }
    }
    $stored = $this->sessions->setSessions($event_id, $starts);
    $this->logger()->success(dt('Event @id now has @n sessions: @list', [
      '@id' => $event_id,
      '@n' => count($stored),
      '@list' => implode(', ', $stored),
    ]));
  }

  /**
   * Run the evaluation pass now (hold + send), or send for one event.
   *
   * @param array $options
   *   Drush options.
   *
   * @command instructor-companion:session-evaluation
   * @aliases ic-session-eval
   * @option event Send the evaluation for this event now, ignoring the window.
   * @usage instructor-companion:session-evaluation
   * @usage instructor-companion:session-evaluation --event=932
   */
  public function evaluation(array $options = ['event' => 0]): void {
    $event_id = (int) $options['event'];
    if ($event_id) {
      $n = $this->evaluation->sendNow($event_id);
      $this->logger()->success(dt('Evaluation sent to @n attendee(s) of event @id.', ['@n' => $n, '@id' => $event_id]));
      return;
    }
    $held = $this->evaluation->hold();
    $sent = $this->evaluation->send();
    $this->logger()->success(dt('Held @h CiviCRM reminder row(s); sent @s evaluation(s).', [
      '@h' => $held,
      '@s' => $sent,
    ]));
  }

}
