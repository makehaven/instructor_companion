<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\InstructorPayRate;
use Drupal\Tests\UnitTestCase;

/**
 * Pay wording on published pages and the intro meeting link.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\InstructorPayRate
 */
class InstructorPayRateTest extends UnitTestCase {

  /**
   * @covers ::apply
   */
  public function testReplacesHourlyPayOnly(): void {
    $this->assertSame('a $26.05/hr baseline pay', InstructorPayRate::apply('a $25/hr baseline pay', 26.05));
    $this->assertSame('rate starts at $26.05/hr', InstructorPayRate::apply('rate starts at $25/hr', 26.05));
    $this->assertSame('a base hourly rate of $26.05.', InstructorPayRate::apply('a base hourly rate of $25.', 26.05));
    $this->assertSame('$25 - General Public, $40 materials', InstructorPayRate::apply('$25 - General Public, $40 materials', 26.05));
  }

  /**
   * @covers ::apply
   */
  public function testRateFormatting(): void {
    $this->assertSame('$27/hr', InstructorPayRate::apply('$25/hr', 27.0));
    $this->assertSame('$25.50/hr', InstructorPayRate::apply('$25/hr', 25.5));
    $this->assertSame('$25/hr', InstructorPayRate::apply('$25/hr', 0.0), 'A missing setting leaves the page alone.');
  }

  /**
   * @covers ::bookingUrl
   */
  public function testBookingUrl(): void {
    $this->assertSame(
      'https://calendly.com/x/30min?name=Sam%20Lee&email=sam%2Bt%40example.org',
      InstructorPayRate::bookingUrl('https://calendly.com/x/30min', 'Sam Lee', 'sam+t@example.org'),
    );
    $this->assertSame('https://x.test/a?b=1&name=S', InstructorPayRate::bookingUrl('https://x.test/a?b=1', 'S', ''));
    $this->assertSame('mailto:education@makehaven.org', InstructorPayRate::bookingUrl('  ', 'Sam', 'sam@example.org'));
  }

}
