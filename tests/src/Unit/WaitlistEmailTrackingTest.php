<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\WaitlistManager;
use Drupal\Tests\UnitTestCase;

/**
 * Tracked links and results for waitlist emails (JR, 2026-11-24).
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\WaitlistManager
 */
class WaitlistEmailTrackingTest extends UnitTestCase {

  /**
   * @covers ::computeClickHash
   */
  public function testClickHashIsBoundToTheEmailAndTheSite(): void {
    $a = WaitlistManager::computeClickHash(181, 'secret-1');
    $this->assertSame($a, WaitlistManager::computeClickHash(181, 'secret-1'));
    $this->assertNotSame($a, WaitlistManager::computeClickHash(182, 'secret-1'), 'Another email cannot reuse it.');
    $this->assertNotSame($a, WaitlistManager::computeClickHash(181, 'secret-2'), 'Another site cannot forge it.');
    $this->assertNotSame($a, WaitlistManager::computeLeaveHash(181, 0, 'secret-1'), 'A leave signature is not a click signature.');
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{20,}$/', $a, 'URL-safe.');
  }

  /**
   * @covers ::clickDestination
   */
  public function testDestinationDependsOnlyOnKindAndEvent(): void {
    $this->assertSame('/civicrm/event/register?reset=1&id=1006', WaitlistManager::clickDestination('seat_alert', 1006), 'A seat email goes straight to registering.');
    $this->assertSame('/civicrm/event/info?id=1006&reset=1', WaitlistManager::clickDestination('notice', 1006), 'A new-date email goes to the class page.');
  }

  /**
   * @covers ::forceHttps
   */
  public function testLinksAreHttpsEvenFromCron(): void {
    $this->assertSame('https://www.makehaven.org/waitlist/go/1/x', WaitlistManager::forceHttps('http://www.makehaven.org/waitlist/go/1/x'));
    $this->assertSame('https://www.makehaven.org/a', WaitlistManager::forceHttps('https://www.makehaven.org/a'));
  }

  /**
   * @covers ::isPersonClick
   */
  public function testClicksInTheFirstTwoMinutesAreScanners(): void {
    $this->assertFalse(WaitlistManager::isPersonClick(1000, 1000));
    $this->assertFalse(WaitlistManager::isPersonClick(1000, 1119));
    $this->assertTrue(WaitlistManager::isPersonClick(1000, 1120));
    $this->assertTrue(WaitlistManager::isPersonClick(1000, 90000));
  }

  /**
   * @covers ::postmarkHeaders
   */
  public function testPostmarkHeadersTagTheKindAndNameTheEmail(): void {
    $h = WaitlistManager::postmarkHeaders('seat_alert', 1006, 181, 841);
    $this->assertSame('waitlist-seat-alert', $h['X-PM-Tag']);
    $this->assertSame('1006', $h['X-PM-Metadata-event']);
    $this->assertSame('181', $h['X-PM-Metadata-log']);
    $this->assertSame('841', $h['X-PM-Metadata-contact']);
    $this->assertSame('waitlist-new-date', WaitlistManager::postmarkHeaders('notice', 1, 2, 3)['X-PM-Tag']);
  }

  /**
   * @covers ::summarizeSends
   */
  public function testSendsAreGroupedAndResultsCountedPerPerson(): void {
    $since = 5000;
    $rows = [
      // 11-23: one seat email before tracking started.
      ['kind' => 'seat_alert', 'contact_id' => 841, 'created' => 1000, 'first_click' => 0],
      // A new-date email batch: three people within a minute.
      ['kind' => 'notice', 'contact_id' => 10, 'created' => 6000, 'first_click' => 7000],
      ['kind' => 'notice', 'contact_id' => 11, 'created' => 6010, 'first_click' => 0],
      ['kind' => 'notice', 'contact_id' => 12, 'created' => 6020, 'first_click' => 0],
      // A seat email to the same people half an hour later is a new send.
      ['kind' => 'seat_alert', 'contact_id' => 10, 'created' => 7800, 'first_click' => 0],
      ['kind' => 'seat_alert', 'contact_id' => 11, 'created' => 7800, 'first_click' => 8000],
      // Same kind, two hours later: a new send.
      ['kind' => 'seat_alert', 'contact_id' => 12, 'created' => 15000, 'first_click' => 0],
    ];
    $seats = [
      // Registered before any email: not credited to an email.
      841 => 500,
      // Registered after the seat email (and after the notice).
      11 => 8100,
    ];
    $left = [12 => 6500];

    $sends = WaitlistManager::summarizeSends($rows, $seats, $left, $since);
    $this->assertCount(4, $sends);

    // Newest first.
    $this->assertSame(['seat_alert', 15000, 1, TRUE, 0, 0, 0], self::flat($sends[0]), 'Person 12 left before this email, so it is not counted again.');
    $this->assertSame(['seat_alert', 7800, 2, TRUE, 1, 1, 0], self::flat($sends[1]));
    $this->assertSame(['notice', 6000, 3, TRUE, 1, 1, 1], self::flat($sends[2]), 'Took a seat counts registering after the email, link or not.');
    $this->assertSame(['seat_alert', 1000, 1, FALSE, 0, 0, 0], self::flat($sends[3]), 'Sent before tracking: clicks unknown; a seat taken earlier is not credited.');
  }

  /**
   * @covers ::summarizeSends
   */
  public function testNoTrackingStartMeansNothingIsTracked(): void {
    $sends = WaitlistManager::summarizeSends([['kind' => 'notice', 'contact_id' => 1, 'created' => 10, 'first_click' => 0]], [], [], 0);
    $this->assertFalse($sends[0]['tracked']);
  }

  /**
   * Row values in a fixed order.
   */
  private static function flat(array $send): array {
    return [$send['kind'], $send['sent'], $send['emails'], $send['tracked'], $send['clicked'], $send['registered'], $send['left']];
  }

}
