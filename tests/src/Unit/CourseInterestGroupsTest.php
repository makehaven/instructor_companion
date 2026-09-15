<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\CourseInterestGroups;
use Drupal\Tests\UnitTestCase;

/**
 * The CiviCRM group a program's interest list lives in.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\CourseInterestGroups
 */
class CourseInterestGroupsTest extends UnitTestCase {

  /**
   * @covers ::groupName
   * @covers ::groupTitle
   */
  public function testGroupNamingIsStableAndReadable(): void {
    $this->assertSame('program_interest_40290', CourseInterestGroups::groupName(40290));
    $this->assertSame('Program interest: Foundations of Fabrication', CourseInterestGroups::groupTitle('  Foundations of Fabrication '));
  }

}
