<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\EventHygieneService;
use Drupal\Tests\UnitTestCase;

/**
 * Which cloned reminder titles are switched off after a copy.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\EventHygieneService
 */
class EventHygieneTest extends UnitTestCase {

  /**
   * @covers ::isClonedReminderTitle
   */
  public function testSupersededTitlesMatchLooselyAndOthersDoNot(): void {
    $this->assertTrue(EventHygieneService::isClonedReminderTitle('3 days Later Reminder'));
    $this->assertTrue(EventHygieneService::isClonedReminderTitle('  thanks for attending '));
    $this->assertTrue(EventHygieneService::isClonedReminderTitle('EVAL'));
    $this->assertFalse(EventHygieneService::isClonedReminderTitle('GEMS Week 2 Digital Fabrication'), 'Programme-week mail is per event on purpose.');
    $this->assertFalse(EventHygieneService::isClonedReminderTitle('Event Happening Soon'), 'Before-class reminders are not touched.');
    $this->assertFalse(EventHygieneService::isClonedReminderTitle('Important Details for Second Sunday: Bottle Cutting'));
  }

  /**
   * @covers ::isClonedReminderTitle
   */
  public function testTheListCanBeReplaced(): void {
    $this->assertTrue(EventHygieneService::isClonedReminderTitle('Custom', ['Custom']));
    $this->assertFalse(EventHygieneService::isClonedReminderTitle('eval', ['Custom']));
  }

  /**
   * @covers ::parseCiviDate
   */
  public function testCiviDateParamsParseInEveryShapeCiviCrmSends(): void {
    $this->assertSame(strtotime('2026-10-07 18:00:00'), EventHygieneService::parseCiviDate('20261007180000'));
    $this->assertSame(strtotime('2026-10-07 18:00:00'), EventHygieneService::parseCiviDate('2026-10-07 18:00:00'));
    $this->assertSame(strtotime('2026-10-07 18:00:00'), EventHygieneService::parseCiviDate('202610071800'));
    $this->assertNull(EventHygieneService::parseCiviDate(''), 'A blank end date is no end date.');
    $this->assertNull(EventHygieneService::parseCiviDate(NULL));
    $this->assertNull(EventHygieneService::parseCiviDate('null'), 'The API sends the string "null" to clear a date.');
  }

}
