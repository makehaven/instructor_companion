<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\AttendancePromptService;
use Drupal\Tests\UnitTestCase;

/**
 * The window that decides when a class is asked for attendance.
 *
 * Getting this wrong is how the prompt either never fires or arrives after
 * everyone has gone home, so the arithmetic is pinned down here rather than
 * discovered on live.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\AttendancePromptService
 */
class AttendancePromptWindowTest extends UnitTestCase {

  /**
   * Builds a service whose configured offset is $minutes.
   */
  protected function service(int $minutes): AttendancePromptService {
    $config = $this->createMock('Drupal\Core\Config\ImmutableConfig');
    $config->method('get')->willReturnMap([
      ['attendance_prompt_enabled', TRUE],
      ['attendance_prompt_offset_minutes', $minutes],
    ]);
    $factory = $this->createMock('Drupal\Core\Config\ConfigFactoryInterface');
    $factory->method('get')->willReturn($config);

    return new AttendancePromptService(
      $this->createMock('Drupal\Core\Database\Connection'),
      $this->createMock('Drupal\Core\State\StateInterface'),
      $this->createMock('Drupal\Core\Entity\EntityTypeManagerInterface'),
      $this->createMock('Drupal\instructor_companion\Service\PostEventStatusService'),
      $this->createMock('Drupal\Core\Mail\MailManagerInterface'),
      $factory,
      $this->createMock('Drupal\Component\Datetime\TimeInterface'),
      $this->createMock('Psr\Log\LoggerInterface'),
    );
  }

  /**
   * A class is eligible from the offset until the window closes, not before.
   *
   * @covers ::dueWindow
   */
  public function testClassBecomesEligibleOnlyAfterTheOffset(): void {
    $now = mktime(12, 0, 0, 6, 1, 2026);
    [$lower, $upper] = $this->service(15)->dueWindow($now);

    // Upper bound is "started at least 15 minutes ago".
    $this->assertSame(date('Y-m-d H:i:s', $now - 15 * 60), $upper);
    // Lower bound is 4 hours before that.
    $this->assertSame(date('Y-m-d H:i:s', $now - 15 * 60 - 4 * 3600), $lower);

    $started_5_min_ago = date('Y-m-d H:i:s', $now - 5 * 60);
    $started_30_min_ago = date('Y-m-d H:i:s', $now - 30 * 60);
    $started_yesterday = date('Y-m-d H:i:s', $now - 26 * 3600);

    $this->assertGreaterThan($upper, $started_5_min_ago, 'Too soon: instructor is still greeting people.');
    $this->assertLessThanOrEqual($upper, $started_30_min_ago, 'In the room now — prompt.');
    $this->assertGreaterThanOrEqual($lower, $started_30_min_ago);
    $this->assertLessThan($lower, $started_yesterday, 'Long over — the post-class reminder owns this.');
  }

  /**
   * An hourly cron cannot step over a class.
   *
   * @covers ::dueWindow
   */
  public function testWindowIsWiderThanTheCronInterval(): void {
    $now = mktime(12, 0, 0, 6, 1, 2026);
    [$lower, $upper] = $this->service(15)->dueWindow($now);
    $span = strtotime($upper) - strtotime($lower);

    $this->assertGreaterThan(3600, $span, 'A class must stay eligible across at least one missed hourly run.');
  }

  /**
   * A zero or missing offset falls back to the default rather than firing at
   * the bell.
   *
   * @covers ::offsetMinutes
   */
  public function testOffsetFallsBackToTheDefault(): void {
    $this->assertSame(
      AttendancePromptService::DEFAULT_OFFSET_MINUTES,
      $this->service(0)->offsetMinutes(),
    );
    $this->assertSame(25, $this->service(25)->offsetMinutes());
  }

}
