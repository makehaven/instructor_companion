<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\PreClassReminderService;
use Drupal\Tests\UnitTestCase;

/**
 * Which pre-class reminder is due, what it replaces, and what it says.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\PreClassReminderService
 */
class PreClassReminderTest extends UnitTestCase {

  private int $start;

  protected function setUp(): void {
    parent::setUp();
    $this->start = strtotime('2026-11-07 10:00:00');
  }

  /**
   * @covers ::dueStage
   */
  public function testWeekStageRunsFromSevenDaysUntilTwo(): void {
    $early = $this->start - 30 * 86400;
    $this->assertNull(PreClassReminderService::dueStage($this->start - 8 * 86400, $this->start, $early), 'Eight days out: nothing yet.');
    $this->assertSame('week', PreClassReminderService::dueStage($this->start - 7 * 86400, $this->start, $early));
    $this->assertSame('week', PreClassReminderService::dueStage($this->start - 3 * 86400, $this->start, $early), 'A missed cron still sends it late.');
    $this->assertSame('soon', PreClassReminderService::dueStage($this->start - 2 * 86400, $this->start, $early));
    $this->assertSame('soon', PreClassReminderService::dueStage($this->start - 7 * 3600, $this->start, $early));
    $this->assertNull(PreClassReminderService::dueStage($this->start - 5 * 3600, $this->start, $early), 'Inside six hours: the door pass covers it.');
    $this->assertNull(PreClassReminderService::dueStage($this->start + 3600, $this->start, $early), 'Class started.');
  }

  /**
   * @covers ::dueStage
   */
  public function testLateRegistrantsSkipTheStageTheyMissed(): void {
    $now = $this->start - 4 * 86400;
    $this->assertNull(PreClassReminderService::dueStage($now, $this->start, $this->start - 5 * 86400), 'Registered five days out: the confirmation just went; no week reminder.');
    $now = $this->start - 36 * 3600;
    $this->assertSame('soon', PreClassReminderService::dueStage($now, $this->start, $this->start - 5 * 86400), 'They still get the two-day one.');
    $this->assertNull(PreClassReminderService::dueStage($now, $this->start, $this->start - 40 * 3600), 'Registered inside two days: nothing.');
  }

  /**
   * @covers ::isReplacedReminder
   */
  public function testWhichCivicrmRemindersAreReplaced(): void {
    $this->assertTrue(PreClassReminderService::isReplacedReminder('1 Week Event Reminder', 1, 'week'));
    $this->assertTrue(PreClassReminderService::isReplacedReminder('Event Happening Soon', 1, 'day'));
    $this->assertTrue(PreClassReminderService::isReplacedReminder('Event Tomorrow', 2, 'day'));
    $this->assertFalse(PreClassReminderService::isReplacedReminder('GEMS Week 1 intro', 5, 'day'), 'Program emails stay.');
    $this->assertFalse(PreClassReminderService::isReplacedReminder('Starting soon', 2, 'hour'), 'Same-day ones stay.');
    $this->assertFalse(PreClassReminderService::isReplacedReminder('Two weeks out', 2, 'week'));
  }

  /**
   * @covers ::compose
   */
  public function testComposeFillsTheDetailsAndDropsEmptyLines(): void {
    $r = [
      'stage' => 'soon',
      'first_name' => 'Pat',
      'title' => 'Intro to Welding',
      'start' => '2026-11-07 10:00:00',
      'end' => '2026-11-07 13:00:00',
    ];
    $texts = ['week_subject' => '', 'week_body' => '', 'soon_subject' => '', 'soon_body' => ''];
    $m = PreClassReminderService::compose($r, $texts, '', '', 'https://www.makehaven.org/visit');
    $this->assertSame('In two days: Intro to Welding', $m['subject']);
    $this->assertStringContainsString('Hi Pat,', $m['body']);
    $this->assertStringContainsString('Date: Saturday, November 7, 2026', $m['body']);
    $this->assertStringContainsString('Time: 10:00 AM – 1:00 PM', $m['body']);
    $this->assertStringContainsString('https://www.makehaven.org/visit', $m['body']);
    $this->assertStringNotContainsString('[', $m['body'], 'No token left behind.');
    $this->assertStringNotContainsString("\n\n\n", $m['body'], 'An empty door note or session line leaves no gap.');

    $m = PreClassReminderService::compose(['stage' => 'week'] + $r, $texts, 'This class meets in 3 sessions, Nov 7 – Nov 21, 2026; the date above is the first session.', '', 'x');
    $this->assertStringContainsString('next week', $m['body']);
    $this->assertStringContainsString('3 sessions', $m['body']);
  }

}
