<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\PostEventStatusService;
use Drupal\Tests\UnitTestCase;

/**
 * The status shown on the console's "Classes to close out" rows.
 *
 * closeoutBacklog() is a query wrapper; what decides whether a class appears,
 * and what its row says, is computeStatus() — covered here so the console
 * cannot start showing "done" for a class that is not.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\PostEventStatusService
 */
class CloseoutBacklogRowTest extends UnitTestCase {

  /**
   * A class with everything outstanding lists all four steps.
   *
   * @covers ::computeStatus
   */
  public function testNothingDone(): void {
    $status = PostEventStatusService::computeStatus([
      'attendance_confirmed' => FALSE,
      'badges_applicable' => TRUE,
      'badges_total_pairs' => 6,
      'badges_done_pairs' => 0,
      'feedback_submitted' => FALSE,
      'payment_done' => FALSE,
    ]);

    $this->assertFalse($status['all_complete']);
    $this->assertSame('0/4', $status['progress']);
    $this->assertSame(
      ['attendance', 'badges', 'feedback', 'payment'],
      $status['incomplete'],
    );
  }

  /**
   * A class with no badges is scored out of three, not four.
   *
   * @covers ::computeStatus
   */
  public function testBadgelessClassIsScoredOutOfThree(): void {
    $status = PostEventStatusService::computeStatus([
      'attendance_confirmed' => TRUE,
      'badges_applicable' => FALSE,
      'feedback_submitted' => FALSE,
      'payment_done' => TRUE,
    ]);

    $this->assertSame('2/3', $status['progress']);
    $this->assertSame(['feedback'], $status['incomplete']);
    $badges = $this->step($status, 'badges');
    $this->assertFalse($badges['applicable'], 'Badge step is not applicable.');
  }

  /**
   * Partly-checked badges keep the class on the list, and say how many remain.
   *
   * @covers ::computeStatus
   */
  public function testPartialBadgeCheckoutStaysOutstanding(): void {
    $status = PostEventStatusService::computeStatus([
      'attendance_confirmed' => TRUE,
      'badges_applicable' => TRUE,
      'badges_total_pairs' => 5,
      'badges_done_pairs' => 2,
      'feedback_submitted' => TRUE,
      'payment_done' => TRUE,
    ]);

    $this->assertFalse($status['all_complete']);
    $this->assertSame(['badges'], $status['incomplete']);
    $this->assertSame('3 of 5 still to check off', $this->step($status, 'badges')['detail']);
  }

  /**
   * A fully wrapped-up class drops off the list entirely.
   *
   * @covers ::computeStatus
   */
  public function testCompleteClassIsNotBacklog(): void {
    $status = PostEventStatusService::computeStatus([
      'attendance_confirmed' => TRUE,
      'badges_applicable' => TRUE,
      'badges_total_pairs' => 3,
      'badges_done_pairs' => 3,
      'feedback_submitted' => TRUE,
      'payment_done' => TRUE,
    ]);

    $this->assertTrue($status['all_complete'], 'closeoutBacklog() skips these.');
    $this->assertSame([], $status['incomplete']);
    $this->assertSame('4/4', $status['progress']);
  }

  /**
   * Badges configured but nobody attended is vacuously done, not stuck.
   *
   * @covers ::computeStatus
   */
  public function testBadgesWithNoAttendeesDoNotBlock(): void {
    $status = PostEventStatusService::computeStatus([
      'attendance_confirmed' => TRUE,
      'badges_applicable' => TRUE,
      'badges_total_pairs' => 0,
      'badges_done_pairs' => 0,
      'feedback_submitted' => TRUE,
      'payment_done' => TRUE,
    ]);

    $this->assertTrue($status['all_complete']);
  }

  /**
   * Helper: one step out of a status array.
   */
  protected function step(array $status, string $key): array {
    foreach ($status['steps'] as $step) {
      if ($step['key'] === $key) {
        return $step;
      }
    }
    $this->fail("No $key step in status.");
  }

}
