<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\PostEventReminderService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the pure due-window calculation for the +48h reminder.
 *
 * @covers \Drupal\instructor_companion\Service\PostEventReminderService::dueWindow
 * @group instructor_companion
 */
class PostEventReminderWindowTest extends UnitTestCase {

  /**
   * The window is [now-72h, now-48h] in CiviCRM's stored datetime format.
   */
  public function testWindowBounds(): void {
    $now = mktime(12, 0, 0, 6, 15, 2026);
    [$lower, $upper] = PostEventReminderService::dueWindow($now);

    $this->assertSame(date('Y-m-d H:i:s', $now - 72 * 3600), $lower);
    $this->assertSame(date('Y-m-d H:i:s', $now - 48 * 3600), $upper);
  }

  /**
   * Lower bound is strictly older than the upper bound (24h-wide window).
   */
  public function testLowerIsBeforeUpper(): void {
    [$lower, $upper] = PostEventReminderService::dueWindow(time());
    $this->assertLessThan(strtotime($upper), strtotime($lower));
    $this->assertSame(24 * 3600, strtotime($upper) - strtotime($lower));
  }

  /**
   * A class that ended exactly 48h ago is eligible.
   *
   * One that ended 24h ago is past the upper bound (too soon, excluded).
   */
  public function testFreshClassIsBelowWindow(): void {
    $now = time();
    [$lower, $upper] = PostEventReminderService::dueWindow($now);
    $ended_24h_ago = date('Y-m-d H:i:s', $now - 24 * 3600);
    $ended_48h_ago = date('Y-m-d H:i:s', $now - 48 * 3600);

    $this->assertGreaterThan(strtotime($upper), strtotime($ended_24h_ago));
    $this->assertLessThanOrEqual(strtotime($upper), strtotime($ended_48h_ago));
    $this->assertGreaterThanOrEqual(strtotime($lower), strtotime($ended_48h_ago));
  }

}
