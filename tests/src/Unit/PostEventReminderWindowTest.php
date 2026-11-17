<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\PostEventReminderService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the pure window and stage calculations for the post-class reminders.
 *
 * @covers \Drupal\instructor_companion\Service\PostEventReminderService::dueWindow
 * @covers \Drupal\instructor_companion\Service\PostEventReminderService::dueStage
 * @group instructor_companion
 */
class PostEventReminderWindowTest extends UnitTestCase {

  /**
   * The window is [now-360h, now-48h] in CiviCRM's stored datetime format.
   */
  public function testWindowBounds(): void {
    $now = mktime(12, 0, 0, 6, 15, 2026);
    [$lower, $upper] = PostEventReminderService::dueWindow($now);

    $this->assertSame(date('Y-m-d H:i:s', $now - 360 * 3600), $lower);
    $this->assertSame(date('Y-m-d H:i:s', $now - 48 * 3600), $upper);
  }

  /**
   * The window reaches a day past the last (14-day) stage.
   */
  public function testLowerIsBeforeUpper(): void {
    [$lower, $upper] = PostEventReminderService::dueWindow(time());
    $this->assertLessThan(strtotime($upper), strtotime($lower));
    $this->assertSame(312 * 3600, strtotime($upper) - strtotime($lower));
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

  /**
   * Stages: 0 before 48h, 1 from 2 days, 2 from 7 days, 3 from 14 days.
   */
  public function testDueStage(): void {
    $now = mktime(12, 0, 0, 6, 15, 2026);
    $ago = static fn(int $h): string => date('Y-m-d H:i:s', $now - $h * 3600);
    $this->assertSame(0, PostEventReminderService::dueStage($ago(47), $now));
    $this->assertSame(1, PostEventReminderService::dueStage($ago(48), $now));
    $this->assertSame(1, PostEventReminderService::dueStage($ago(167), $now));
    $this->assertSame(2, PostEventReminderService::dueStage($ago(168), $now));
    $this->assertSame(3, PostEventReminderService::dueStage($ago(336), $now));
    $this->assertSame(0, PostEventReminderService::dueStage('', $now));
  }

}
