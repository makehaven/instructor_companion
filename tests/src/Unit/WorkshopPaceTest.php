<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\WorkshopInsightsService;
use Drupal\Tests\UnitTestCase;

/**
 * The pace flag on /admin/education/workshops.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\WorkshopInsightsService
 */
class WorkshopPaceTest extends UnitTestCase {

  /**
   * @covers ::paceFlag
   */
  public function testFlags(): void {
    $this->assertSame('waitlist', WorkshopInsightsService::paceFlag(3, 9, 9, 2));
    $this->assertSame('full', WorkshopInsightsService::paceFlag(20, 9, 9, 0));
    $this->assertSame('empty', WorkshopInsightsService::paceFlag(10, 0, 9, 0));
    $this->assertSame('behind', WorkshopInsightsService::paceFlag(5, 3, 9, 0), 'Three of nine a week out.');
    $this->assertSame('on-track', WorkshopInsightsService::paceFlag(5, 5, 9, 0), 'Five of nine a week out is fine.');
    $this->assertSame('watch', WorkshopInsightsService::paceFlag(12, 2, 9, 0), 'Two of nine two weeks out.');
    $this->assertSame('on-track', WorkshopInsightsService::paceFlag(12, 4, 9, 0));
    $this->assertSame('on-track', WorkshopInsightsService::paceFlag(40, 0, 9, 0), 'Six weeks out, nothing is behind yet.');
    $this->assertSame('on-track', WorkshopInsightsService::paceFlag(5, 2, 0, 0), 'No declared capacity: nothing to be behind.');
  }

}
