<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\SessionEvaluationService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests when unmarked attendees fall back to the Drupal evaluation.
 *
 * @coversDefaultClass \Drupal\instructor_companion\Service\SessionEvaluationService
 * @group instructor_companion
 */
class EvaluationFallbackTest extends UnitTestCase {

  /**
   * Only an Attended-only (no Registered) reminder leaves a gap to fill.
   *
   * @covers ::needsFallback
   */
  public function testNeedsFallback(): void {
    $this->assertTrue(SessionEvaluationService::needsFallback(['statuses' => [2]]));
    // Registered + Attended: CiviCRM already mails the unmarked.
    $this->assertFalse(SessionEvaluationService::needsFallback(['statuses' => [1, 2]]));
    // No status filter: CiviCRM mails everyone.
    $this->assertFalse(SessionEvaluationService::needsFallback(['statuses' => []]));
  }

  /**
   * The live schedules store statuses \x01-separated.
   *
   * @covers ::splitValues
   */
  public function testSplitStoredStatuses(): void {
    $this->assertSame([1, 2], SessionEvaluationService::splitValues("1\x012"));
    $this->assertSame([2], SessionEvaluationService::splitValues("\x012\x01"));
    $this->assertSame([], SessionEvaluationService::splitValues(''));
  }

  /**
   * The window opens fallback hours after the class and runs four days back.
   *
   * @covers ::fallbackWindow
   */
  public function testFallbackWindow(): void {
    $now = mktime(12, 0, 0, 10, 14, 2026);
    [$lo, $hi] = SessionEvaluationService::fallbackWindow($now, 72);
    $this->assertSame(date('Y-m-d H:i:s', $now - 72 * 3600), $hi);
    // Four days wide, starting at the fallback point.
    $this->assertSame(date('Y-m-d H:i:s', $now - (72 + 96) * 3600), $lo);
  }

}
