<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\AbandonedRegistrationService;
use Drupal\Tests\UnitTestCase;

/**
 * When a stalled registration is worth chasing.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\AbandonedRegistrationService
 */
class AbandonedRegistrationEligibilityTest extends UnitTestCase {

  private int $now;

  protected function setUp(): void {
    parent::setUp();
    $this->now = strtotime('2026-09-22 12:00:00');
  }

  /**
   * @covers ::eligible
   */
  public function testTheHappyPath(): void {
    $registered = $this->now - 30 * 3600;
    $start = $this->now + 5 * 86400;
    $this->assertTrue(AbandonedRegistrationService::eligible($this->now, $registered, $start, TRUE, FALSE));
  }

  /**
   * @covers ::eligible
   */
  public function testTooSoonTooOldTooLateOrNoSeat(): void {
    $start = $this->now + 5 * 86400;
    $this->assertFalse(AbandonedRegistrationService::eligible($this->now, $this->now - 3600, $start, TRUE, FALSE), 'An hour old: give them time to come back on their own.');
    $this->assertFalse(AbandonedRegistrationService::eligible($this->now, $this->now - 20 * 86400, $start, TRUE, FALSE), 'Twenty days old: the moment has passed.');
    $this->assertFalse(AbandonedRegistrationService::eligible($this->now, $this->now - 2 * 86400, $this->now + 3600, TRUE, FALSE), 'Class starts within six hours.');
    $this->assertFalse(AbandonedRegistrationService::eligible($this->now, $this->now - 2 * 86400, $start, FALSE, FALSE), 'No seat left.');
    $this->assertFalse(AbandonedRegistrationService::eligible($this->now, $this->now - 2 * 86400, $start, TRUE, TRUE), 'They registered properly since.');
  }

  /**
   * @covers ::eligible
   */
  public function testTheAfterHoursSettingMoves(): void {
    $registered = $this->now - 3 * 3600;
    $start = $this->now + 5 * 86400;
    $this->assertFalse(AbandonedRegistrationService::eligible($this->now, $registered, $start, TRUE, FALSE, 24));
    $this->assertTrue(AbandonedRegistrationService::eligible($this->now, $registered, $start, TRUE, FALSE, 2));
  }

  /**
   * @covers ::compose
   */
  public function testComposeNamesTheClassTheDateAndTheLink(): void {
    $c = ['first_name' => 'Lee', 'title' => 'Intro to Sewing', 'start' => '2026-10-01 18:00:00'];
    $out = AbandonedRegistrationService::compose($c, 'https://www.makehaven.org/civicrm/event/register?id=978&reset=1');
    $this->assertSame('Your seat in Intro to Sewing is still open', $out['subject']);
    $this->assertStringContainsString('Hi Lee,', $out['body']);
    $this->assertStringContainsString('Thursday, October 1 at 6:00 PM', $out['body']);
    $this->assertStringContainsString('register?id=978', $out['body']);
    $this->assertStringNotContainsString('[', $out['body']);
  }

}
