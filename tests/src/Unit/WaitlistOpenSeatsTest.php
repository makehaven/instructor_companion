<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\WaitlistOpenSeats;
use Drupal\Tests\UnitTestCase;

/**
 * The last days before a class: when holds stop, when the list is emailed.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\WaitlistOpenSeats
 */
class WaitlistOpenSeatsTest extends UnitTestCase {

  private const NOW = 1_800_000_000;

  /**
   * @covers ::inWindow
   */
  public function testWindowIsTheLastThreeDaysBeforeTheStart(): void {
    $h = 72;
    $this->assertTrue(WaitlistOpenSeats::inWindow(self::NOW + 3600, self::NOW, $h), 'An hour out is in.');
    $this->assertTrue(WaitlistOpenSeats::inWindow(self::NOW + 72 * 3600, self::NOW, $h), 'Exactly three days out is in.');
    $this->assertFalse(WaitlistOpenSeats::inWindow(self::NOW + 72 * 3600 + 1, self::NOW, $h), 'Just over three days keeps the normal 24 h offer.');
    $this->assertFalse(WaitlistOpenSeats::inWindow(self::NOW, self::NOW, $h), 'A class that has started is out.');
    $this->assertFalse(WaitlistOpenSeats::inWindow(self::NOW - 60, self::NOW, $h));
    $this->assertFalse(WaitlistOpenSeats::inWindow(self::NOW + 3600, self::NOW, 0), '0 hours turns it off.');
  }

  /**
   * @covers ::verdict
   */
  public function testListIsEmailedWhenSeatsGoUp(): void {
    // A seat opens; nobody told yet.
    $this->assertSame('send', WaitlistOpenSeats::verdict(1, 0, 0, self::NOW));
    // Seats were already open when the class entered its last days.
    $this->assertSame('send', WaitlistOpenSeats::verdict(2, 0, 0, self::NOW));
    // Nothing changed since the last look.
    $this->assertSame('none', WaitlistOpenSeats::verdict(1, 1, self::NOW - 7200, self::NOW));
    // A seat was taken: fewer open, nobody emailed.
    $this->assertSame('none', WaitlistOpenSeats::verdict(0, 1, self::NOW - 7200, self::NOW));
    // Taken, then another opened later: 0 -> 1 is news again.
    $this->assertSame('send', WaitlistOpenSeats::verdict(1, 0, self::NOW - 7200, self::NOW));
  }

  /**
   * @covers ::verdict
   */
  public function testOpeningsCloseTogetherAreCombined(): void {
    $recent = self::NOW - (WaitlistOpenSeats::COMBINE_MINUTES * 60 - 1);
    $this->assertSame('wait', WaitlistOpenSeats::verdict(2, 1, $recent, self::NOW), 'A second seat soon after an email waits.');
    $later = self::NOW - WaitlistOpenSeats::COMBINE_MINUTES * 60;
    $this->assertSame('send', WaitlistOpenSeats::verdict(2, 1, $later, self::NOW), 'After the gap it goes out.');
  }

  /**
   * @covers ::compose
   */
  public function testEmailSaysFirstComeAndHowToLeave(): void {
    $m = WaitlistOpenSeats::compose('Ana', 'Intro to Welding', '2026-11-21 10:00:00', 1, 'https://www.makehaven.org/civicrm/event/register?reset=1&id=9', 'https://www.makehaven.org/waitlist/leave/1/2/x');
    $this->assertSame('A seat just opened: Intro to Welding', $m['subject']);
    $this->assertStringContainsString('Hi Ana,', $m['body']);
    $this->assertStringContainsString('Saturday, November 21 at 10:00 AM', $m['body']);
    $this->assertStringContainsString('It goes to whoever registers first: https://www.makehaven.org/civicrm/event/register?reset=1&id=9', $m['body']);
    $this->assertStringContainsString('you stay on the waiting list', $m['body']);
    $this->assertStringContainsString('/waitlist/leave/1/2/x', $m['body']);

    $two = WaitlistOpenSeats::compose('Ana', 'Intro to Welding', '2026-11-21 10:00:00', 2, 'https://x/r', '');
    $this->assertSame('2 seats just opened: Intro to Welding', $two['subject']);
    $this->assertStringContainsString('They go to whoever registers first', $two['body']);
    $this->assertStringNotContainsString('One click takes you off', $two['body'], 'No leave line for an event without a course.');
  }

}
