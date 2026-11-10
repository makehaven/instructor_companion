<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Controller\ScheduleInstanceController;
use PHPUnit\Framework\TestCase;

/**
 * A program's Schedule button makes a cohort, not a copy of the program page.
 *
 * @group instructor_companion
 */
class ProgramCohortInstanceTest extends TestCase {

  public function testCohortNumberFollowsRunsSoFar(): void {
    // Foundations: 2 linked runs + 6 before our records = 8 so far.
    $this->assertSame('Foundations of Fabrication - Cohort 9', ScheduleInstanceController::cohortTitle('Foundations of Fabrication', 8));
    $this->assertSame('New Program - Cohort 1', ScheduleInstanceController::cohortTitle('New Program', 0));
    $this->assertSame('New Program - Cohort 1', ScheduleInstanceController::cohortTitle('New Program', -3));
  }

  public function testDescriptionIsSummaryAndLinkNotThePageBody(): void {
    $html = ScheduleInstanceController::cohortDescription('Foundations of Fabrication', 'A 16-week evening program.', 'https://www.makehaven.org/foundations-fabrication');
    $this->assertStringContainsString('<p>A 16-week evening program.</p>', $html);
    $this->assertStringContainsString('href="https://www.makehaven.org/foundations-fabrication"', $html);
    $this->assertStringNotContainsString('COURSE_UPCOMING', $html);
  }

  public function testDescriptionEscapesAndSurvivesNoSummary(): void {
    $html = ScheduleInstanceController::cohortDescription('Cut & Sew', '', 'https://example.org/a?b=1&c=2');
    $this->assertStringContainsString('Cut &amp; Sew program page', $html);
    $this->assertStringContainsString('b=1&amp;c=2', $html);
    $this->assertStringStartsWith('<p>Curriculum', $html);
  }

}
