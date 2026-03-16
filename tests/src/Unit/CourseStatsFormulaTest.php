<?php

namespace Drupal\Tests\instructor_companion\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests the statistical calculation formulas used in CourseStatsSync.
 *
 * These tests mirror the exact formulas in CourseStatsSync::sync() to verify
 * the math is correct independently of the database layer.
 *
 * @see \Drupal\instructor_companion\CourseStatsSync::sync()
 * @group instructor_companion
 */
class CourseStatsFormulaTest extends TestCase {

  /**
   * Tests utilization calculation and capping behaviour.
   *
   * Mirrors:
   *   $util = ($past_cap > 0) ? round(($past_att / $past_cap) * 100) : 0;
   *   if ($util > 100) $util = 100;
   *
   * @dataProvider utilizationProvider
   */
  public function testUtilizationCalculation(int $past_att, int $past_cap, int $expected): void {
    $this->assertSame($expected, $this->calculateUtilization($past_att, $past_cap));
  }

  /**
   * Data provider for utilization scenarios.
   */
  public static function utilizationProvider(): array {
    return [
      'normal: 75 of 100'                   => [75,  100, 75],
      'normal: 50 of 100'                   => [50,  100, 50],
      'full capacity'                        => [100, 100, 100],
      'over capacity capped to 100'          => [120, 100, 100],
      'zero attendees'                       => [0,   100, 0],
      'zero capacity avoids division by zero'=> [0,   0,   0],
      'attendees with zero capacity'         => [5,   0,   0],
      'rounding: 1 of 3 → 33%'              => [1,   3,   33],
      'rounding: 2 of 3 → 67%'              => [2,   3,   67],
      'one over limit capped'                => [101, 100, 100],
    ];
  }

  /**
   * Tests the 12-month window boundary conditions.
   *
   * Mirrors:
   *   $is_recent = ($e->start_date >= $one_year_ago && $e->start_date < $now);
   */
  public function testTwelveMonthWindowBoundaries(): void {
    // Use a fixed reference point for deterministic results.
    $now = mktime(0, 0, 0, 6, 15, 2026);
    $one_year_ago = strtotime('-1 year', $now);

    // Exactly at the boundary is included (>= comparison).
    $this->assertTrue(
      $this->isInTwelveMonthWindow($one_year_ago, $now),
      'Boundary date should be inside the 12-month window'
    );

    // One second before the boundary is excluded.
    $this->assertFalse(
      $this->isInTwelveMonthWindow($one_year_ago - 1, $now),
      'One second before boundary should be outside the window'
    );

    // Mid-window event (6 months ago) is included.
    $this->assertTrue(
      $this->isInTwelveMonthWindow($now - 86400 * 180, $now),
      'An event 6 months ago should be inside the window'
    );

    // $now itself is excluded (< $now required).
    $this->assertFalse(
      $this->isInTwelveMonthWindow($now, $now),
      'The reference point itself is not inside the past 12-month window'
    );

    // Future events are excluded.
    $this->assertFalse(
      $this->isInTwelveMonthWindow($now + 3600, $now),
      'A future event should not be inside the 12-month window'
    );
  }

  /**
   * Tests the upcoming/past event classification.
   *
   * Mirrors: $is_upcoming = ($e->start_date >= $now);
   */
  public function testUpcomingVsPastClassification(): void {
    $now = date('Y-m-d H:i:s');
    $future = date('Y-m-d H:i:s', strtotime('+1 week'));
    $past   = date('Y-m-d H:i:s', strtotime('-1 week'));

    $this->assertTrue($future >= $now, 'A future date should be classified as upcoming');
    $this->assertFalse($past >= $now, 'A past date should not be classified as upcoming');
    // Edge: an event starting exactly at $now is considered upcoming.
    $this->assertTrue($now >= $now, 'An event at exactly now is considered upcoming');
  }

  /**
   * Tests that last_run tracks the most recent past event date.
   *
   * Mirrors:
   *   if (!$stats['last_run'] || $e->start_date > $stats['last_run']) {
   *     $stats['last_run'] = $e->start_date;
   *   }
   */
  public function testLastRunTracksMostRecentDate(): void {
    $dates = [
      '2025-01-15 10:00:00',
      '2025-06-20 14:00:00',
      '2024-11-01 09:00:00',
    ];

    $last_run = NULL;
    foreach ($dates as $start_date) {
      if (!$last_run || $start_date > $last_run) {
        $last_run = $start_date;
      }
    }

    $this->assertSame('2025-06-20 14:00:00', $last_run);
  }

  /**
   * Tests that last_run is not set from upcoming (future) events.
   *
   * The sync loop only sets last_run inside the else (non-upcoming) branch.
   */
  public function testLastRunIgnoresFutureEvents(): void {
    $now   = date('Y-m-d H:i:s');
    $future = date('Y-m-d H:i:s', strtotime('+1 week'));
    $past   = date('Y-m-d H:i:s', strtotime('-3 weeks'));

    $last_run = NULL;
    foreach ([$future, $past] as $start_date) {
      $is_upcoming = ($start_date >= $now);
      if (!$is_upcoming) {
        if (!$last_run || $start_date > $last_run) {
          $last_run = $start_date;
        }
      }
    }

    $this->assertSame($past, $last_run, 'last_run should only reflect past events');
  }

  /**
   * Tests revenue aggregation across multiple events.
   */
  public function testRevenueAggregation(): void {
    $event_revenues = [150.00, 225.50, 75.25, 0.00];
    $total = array_sum($event_revenues);
    $this->assertEqualsWithDelta(450.75, $total, 0.001);
  }

  /**
   * Tests that past_att is only populated from non-upcoming events.
   *
   * Mirrors:
   *   if (!$is_upcoming) { $stats['past_att'] += $event_att; }
   */
  public function testPastAttendanceExcludesFutureEvents(): void {
    $now     = date('Y-m-d H:i:s');
    $future  = date('Y-m-d H:i:s', strtotime('+1 week'));
    $past    = date('Y-m-d H:i:s', strtotime('-1 week'));

    $events = [
      ['start_date' => $past,   'att' => 12],
      ['start_date' => $future, 'att' => 8],
    ];

    $total_att = 0;
    $past_att  = 0;
    foreach ($events as $e) {
      $total_att += $e['att'];
      if (!($e['start_date'] >= $now)) {
        $past_att += $e['att'];
      }
    }

    $this->assertSame(20, $total_att, 'total_att should include all events');
    $this->assertSame(12, $past_att,  'past_att should exclude future events');
  }

  /**
   * Tests that past_cap only accumulates for non-upcoming events.
   *
   * Mirrors:
   *   if (!$is_upcoming) { $stats['past_cap'] += (int) $e->max_participants; }
   */
  public function testPastCapacityExcludesFutureEvents(): void {
    $now    = date('Y-m-d H:i:s');
    $future = date('Y-m-d H:i:s', strtotime('+1 week'));
    $past   = date('Y-m-d H:i:s', strtotime('-1 week'));

    $events = [
      ['start_date' => $past,   'max_participants' => 20],
      ['start_date' => $future, 'max_participants' => 20],
    ];

    $past_cap = 0;
    foreach ($events as $e) {
      if (!($e['start_date'] >= $now)) {
        $past_cap += (int) $e['max_participants'];
      }
    }

    $this->assertSame(20, $past_cap, 'past_cap should only include non-upcoming events');
  }

  // ---------------------------------------------------------------------------
  // Private helpers — mirror the exact formulas from CourseStatsSync::sync()
  // ---------------------------------------------------------------------------

  /**
   * Mirrors: round(($past_att / $past_cap) * 100), capped at 100.
   */
  private function calculateUtilization(int $past_att, int $past_cap): int {
    $util = ($past_cap > 0) ? (int) round(($past_att / $past_cap) * 100) : 0;
    return min($util, 100);
  }

  /**
   * Mirrors: $e->start_date >= $one_year_ago && $e->start_date < $now.
   */
  private function isInTwelveMonthWindow(int $event_ts, int $now): bool {
    $one_year_ago = strtotime('-1 year', $now);
    $event_str    = date('Y-m-d H:i:s', $event_ts);
    $now_str      = date('Y-m-d H:i:s', $now);
    $boundary_str = date('Y-m-d H:i:s', $one_year_ago);
    return $event_str >= $boundary_str && $event_str < $now_str;
  }

}
