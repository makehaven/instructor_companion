<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\AttendanceManager;
use Drupal\Tests\UnitTestCase;

/**
 * Attendance never turns a waitlisted person into a no-show (#47008).
 *
 * @coversDefaultClass \Drupal\instructor_companion\Service\AttendanceManager
 * @group instructor_companion
 */
class AttendanceDerivedStatusTest extends UnitTestCase {

  /**
   * @covers ::derivedStatus
   * @dataProvider cases
   */
  public function testDerivedStatus(bool $attended, string $current, ?string $expected): void {
    $this->assertSame($expected, AttendanceManager::derivedStatus($attended, $current));
  }

  /**
   * Cases.
   */
  public static function cases(): array {
    return [
      'registered and came' => [TRUE, 'Registered', 'Attended'],
      'registered, did not come' => [FALSE, 'Registered', 'No-show'],
      'pay later, did not come' => [FALSE, 'Pending from pay later', 'No-show'],
      'already attended, now unticked' => [FALSE, 'Attended', 'No-show'],
      'waitlisted, did not come' => [FALSE, 'On waitlist', NULL],
      'awaiting approval, did not come' => [FALSE, 'Awaiting approval', NULL],
      'offered seat, did not come' => [FALSE, 'Pending from waitlist', NULL],
      'waitlisted but came anyway' => [TRUE, 'On waitlist', 'Attended'],
    ];
  }

}
