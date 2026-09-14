<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\AttendanceManager;
use Drupal\instructor_companion\Service\SessionEvaluationService;
use Drupal\instructor_companion\Service\SessionSchedule;
use Drupal\Tests\UnitTestCase;

/**
 * The pure arithmetic behind multi-session classes.
 *
 * Wrong answers here are how a six-week class gets its wrap-up nag on day 3,
 * or a rescheduled class ends up with session 2 before session 1 — so the
 * rules are pinned down without a database.
 *
 * @group instructor_companion
 */
class SessionScheduleTest extends UnitTestCase {

  /**
   * The site timezone used by every case.
   */
  protected \DateTimeZone $tz;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tz = new \DateTimeZone('America/New_York');
    date_default_timezone_set('America/New_York');
  }

  /**
   * A class with no session list is one session, the event itself.
   */
  public function testSingleSessionIsTheEvent(): void {
    $s = SessionSchedule::buildSchedule('2026-09-12 17:00:00', '2026-09-12 19:00:00', []);
    $this->assertCount(1, $s);
    $this->assertSame('2026-09-12 17:00:00', $s[0]['start']);
    $this->assertSame('2026-09-12 19:00:00', $s[0]['end']);
    $this->assertSame(1, $s[0]['count']);
  }

  /**
   * Sessions sort, dedupe against the start, and inherit the session length.
   */
  public function testMultiSessionScheduleIsOrderedAndUnique(): void {
    $s = SessionSchedule::buildSchedule('2026-09-12 17:00:00', '2026-09-12 19:00:00', [
      '2026-09-26 17:00:00',
      '2026-09-12 17:00:00',
      '2026-09-19 17:00:00',
    ]);
    $this->assertCount(3, $s);
    $this->assertSame(['2026-09-12 17:00:00', '2026-09-19 17:00:00', '2026-09-26 17:00:00'], array_column($s, 'start'));
    $this->assertSame('2026-09-26 19:00:00', $s[2]['end']);
    $this->assertSame([0, 1, 2], array_column($s, 'index'));
  }

  /**
   * The event start is always session 1, even when the editor left it out.
   */
  public function testStartIsAlwaysIncluded(): void {
    $s = SessionSchedule::buildSchedule('2026-09-12 17:00:00', '2026-09-12 19:00:00', ['2026-09-19 17:00:00']);
    $this->assertSame('2026-09-12 17:00:00', $s[0]['start']);
    $this->assertCount(2, $s);
    $this->assertFalse(SessionSchedule::includesStart(['2026-09-19 17:00:00'], '2026-09-12 17:00:00'));
    $this->assertTrue(SessionSchedule::includesStart(['2026-09-12 17:00:30'], '2026-09-12 17:00:00'));
  }

  /**
   * A missing or inverted end_date falls back to the minimum length.
   */
  public function testDurationFallback(): void {
    $this->assertSame(SessionSchedule::MIN_DURATION_SECONDS, SessionSchedule::sessionDuration('2026-09-12 17:00:00', NULL));
    $this->assertSame(SessionSchedule::MIN_DURATION_SECONDS, SessionSchedule::sessionDuration('2026-09-12 17:00:00', '2026-09-12 16:00:00'));
    $this->assertSame(7200, SessionSchedule::sessionDuration('2026-09-12 17:00:00', '2026-09-12 19:00:00'));
  }

  /**
   * Weekly generation keeps the wall-clock time across the DST change.
   */
  public function testGenerateWeeklyAcrossDst(): void {
    $starts = SessionSchedule::generate('2026-10-24 18:00:00', 3, 7, $this->tz);
    $this->assertSame(['2026-10-24 18:00:00', '2026-10-31 18:00:00', '2026-11-07 18:00:00'], $starts);
  }

  /**
   * Rescheduling moves every session by the same days and takes the new time.
   */
  public function testShiftFollowsTheNewStart(): void {
    $six = SessionSchedule::generate('2026-09-12 17:00:00', 6, 7, $this->tz);
    $moved = SessionSchedule::shift($six, '2026-09-12 17:00:00', '2026-09-19 18:30:00', $this->tz);
    $this->assertSame('2026-09-19 18:30:00', $moved[0]);
    $this->assertSame('2026-10-24 18:30:00', $moved[5]);
    $this->assertCount(6, $moved);
    // Moving earlier works too.
    $back = SessionSchedule::shift($six, '2026-09-12 17:00:00', '2026-09-05 17:00:00', $this->tz);
    $this->assertSame('2026-09-05 17:00:00', $back[0]);
    $this->assertSame('2026-10-10 17:00:00', $back[5]);
  }

  /**
   * Storage round-trips: local wall clock in, UTC out, and back.
   */
  public function testStorageConversion(): void {
    $stored = SessionSchedule::localToStorage('2026-09-12 17:00:00', $this->tz);
    $this->assertSame('2026-09-12T21:00:00', $stored);
    $this->assertSame('2026-09-12 17:00:00', SessionSchedule::storageToLocal($stored, $this->tz));
    // Winter offset differs.
    $this->assertSame('2026-12-07T23:00:00', SessionSchedule::localToStorage('2026-12-07 18:00:00', $this->tz));
    $this->assertNull(SessionSchedule::localToStorage('', $this->tz));
  }

  /**
   * The summary line reads the way staff say it.
   */
  public function testSummary(): void {
    $s = SessionSchedule::buildSchedule('2026-09-12 17:00:00', '2026-09-12 19:00:00', SessionSchedule::generate('2026-09-12 17:00:00', 6, 7, $this->tz));
    $this->assertSame('6 sessions, Sep 12 – Oct 17, 2026', SessionSchedule::summary($s));
    $this->assertSame('', SessionSchedule::summary(SessionSchedule::buildSchedule('2026-09-12 17:00:00', NULL, [])));
  }

  /**
   * Attendance accumulates: present anywhere = attended, never = not.
   */
  public function testAttendanceSummaryAccumulates(): void {
    $marks = [
      '2026-09-19 17:00:00' => [10 => FALSE, 11 => TRUE, 12 => FALSE],
      '2026-09-12 17:00:00' => [10 => TRUE, 11 => FALSE, 12 => FALSE],
      '2026-09-26 17:00:00' => [10 => FALSE, 11 => TRUE],
    ];
    $sum = AttendanceManager::summarize($marks);
    $this->assertTrue($sum[10]['attended']);
    $this->assertSame('2026-09-12 17:00:00', $sum[10]['first']);
    $this->assertSame(1, $sum[10]['present']);
    $this->assertSame(3, $sum[10]['marked']);
    $this->assertTrue($sum[11]['attended']);
    $this->assertSame('2026-09-19 17:00:00', $sum[11]['first']);
    $this->assertSame(2, $sum[11]['present']);
    $this->assertFalse($sum[12]['attended']);
    $this->assertNull($sum[12]['first']);
    $this->assertSame(2, $sum[12]['marked']);
  }

  /**
   * The evaluation window trails the last session by the configured delay.
   */
  public function testEvaluationWindow(): void {
    $now = strtotime('2026-10-18 20:00:00');
    [$lo, $hi] = SessionEvaluationService::dueWindow($now, 24);
    $this->assertSame('2026-10-17 20:00:00', $hi);
    $this->assertSame('2026-10-14 20:00:00', $lo);
  }

  /**
   * CiviCRM's multi-value strings split on the control separator or commas.
   */
  public function testSplitValues(): void {
    $this->assertSame([1, 2], SessionEvaluationService::splitValues("1\x012"));
    $this->assertSame([6], SessionEvaluationService::splitValues("\x016\x01"));
    $this->assertSame([9, 10], SessionEvaluationService::splitValues('9,10'));
    $this->assertSame([], SessionEvaluationService::splitValues(''));
  }

}
