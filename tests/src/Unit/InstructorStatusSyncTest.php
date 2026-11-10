<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\InstructorStatusSync;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\instructor_companion\Service\InstructorStatusSync
 * @group instructor_companion
 */
class InstructorStatusSyncTest extends UnitTestCase {

  private int $now;

  protected function setUp(): void {
    parent::setUp();
    $this->now = strtotime('2026-11-10 12:00:00');
  }

  private function decide(string $current, string $last_class = '', int $upcoming = 0, string $grace = '2020-01-01', string $manual_inactive = ''): string {
    return InstructorStatusSync::decide(
      $current,
      $last_class ? strtotime($last_class) : 0,
      $upcoming,
      strtotime($grace),
      $manual_inactive ? strtotime($manual_inactive) : 0,
      $this->now,
    );
  }

  /**
   * A class inside the window makes and keeps someone active.
   */
  public function testTaughtRecentlyIsActive(): void {
    $this->assertSame('active', $this->decide('active', '2026-06-01'));
    $this->assertSame('active', $this->decide('inactive', '2026-06-01'));
  }

  /**
   * Last class over a year ago, nothing scheduled: inactive.
   */
  public function testLapsed(): void {
    $this->assertSame('inactive', $this->decide('active', '2025-08-09'));
    $this->assertSame('inactive', $this->decide('inactive', '2025-08-09'));
  }

  /**
   * A scheduled class keeps an active instructor active, and activates no one.
   */
  public function testUpcomingClassHoldsButDoesNotActivate(): void {
    $this->assertSame('active', $this->decide('active', '2025-08-09', 1));
    $this->assertSame('inactive', $this->decide('inactive', '', 1));
  }

  /**
   * A newly approved instructor has the window to teach a first class.
   */
  public function testNewInstructorGrace(): void {
    $this->assertSame('active', $this->decide('active', '', 0, '2026-10-16'));
    $this->assertSame('inactive', $this->decide('active', '', 0, '2025-06-27'));
    // Grace never activates: approval is staff's call.
    $this->assertSame('inactive', $this->decide('inactive', '', 0, '2026-10-16'));
  }

  /**
   * Staff setting someone inactive after their last class holds.
   */
  public function testStaffInactiveHoldsUntilTheyTeachAgain(): void {
    $this->assertSame('inactive', $this->decide('inactive', '2026-03-14', 0, '2020-01-01', '2026-05-01'));
    $this->assertSame('active', $this->decide('inactive', '2026-06-27', 0, '2020-01-01', '2026-05-01'));
  }

}
