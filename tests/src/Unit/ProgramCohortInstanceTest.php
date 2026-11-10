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

  public function testCopyPrefixIsStripped(): void {
    $this->assertSame('GEMS Cohort 30', ScheduleInstanceController::stripCopyPrefix('Copy of GEMS Cohort 30'));
    $this->assertSame('GEMS Cohort 30', ScheduleInstanceController::stripCopyPrefix('Copy of Copy of GEMS Cohort 30'));
    $this->assertSame('Copy Machine Basics', ScheduleInstanceController::stripCopyPrefix('Copy Machine Basics'));
  }

  public function testFollowersAreNotToldAboutAnUnfinishedEvent(): void {
    $now = strtotime('2026-11-04 15:00:00');
    $ready = [\Drupal\instructor_companion\Service\CourseFollowerNotifier::class, 'readyToAnnounce'];
    // Copied twelve minutes ago: Silas is still setting the date.
    $this->assertFalse($ready('Intro to Sewing', '2026-11-04 14:48:00', $now));
    $this->assertTrue($ready('Intro to Sewing', '2026-11-04 13:59:00', $now));
    // Never while it still carries CiviCRM's copy title.
    $this->assertFalse($ready('Copy of GEMS Cohort 30', '2026-11-01 09:00:00', $now));
    // Unknown creation time does not block the notice for ever.
    $this->assertTrue($ready('Intro to Sewing', '', $now));
  }

}
