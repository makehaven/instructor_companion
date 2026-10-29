<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\SetupDeadlineAlert;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\instructor_companion\Service\SetupDeadlineAlert
 * @group instructor_companion
 */
class SetupDeadlineAlertTest extends UnitTestCase {

  /**
   * A week out is the window; past classes and later ones are not.
   */
  public function testIsDueWindow(): void {
    $now = strtotime('2026-10-28 12:00:00');
    $this->assertTrue(SetupDeadlineAlert::isDue('2026-11-04 11:00:00', $now));
    $this->assertTrue(SetupDeadlineAlert::isDue('2026-10-28 14:00:00', $now));
    $this->assertFalse(SetupDeadlineAlert::isDue('2026-11-04 13:00:00', $now));
    $this->assertFalse(SetupDeadlineAlert::isDue('2026-10-28 11:00:00', $now));
    $this->assertFalse(SetupDeadlineAlert::isDue('', $now));
  }

  /**
   * Done steps, including "Guidebook: coming soon", are not listed.
   */
  public function testOpenStepsSkipsDoneAndPending(): void {
    $steps = [
      'profile' => ['title' => 'Add your photo and bio', 'done' => TRUE],
      'guide' => ['title' => 'Instructor Guidebook: coming soon', 'done' => TRUE, 'pending' => TRUE],
      'agreement' => ['title' => 'Sign the instructor agreement', 'done' => FALSE],
      'site_orientation' => ['title' => 'Book your in-person Site Orientation', 'done' => FALSE],
    ];
    $this->assertSame(
      ['Sign the instructor agreement', 'Book your in-person Site Orientation'],
      SetupDeadlineAlert::openSteps($steps)
    );
  }

}
