<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\PostEventStatusService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the pure post-event completion decision logic.
 *
 * PostEventStatusService::computeStatus() is intentionally Drupal-free so the
 * four-step roll-up (attendance / badges / feedback / payment) can be verified
 * without a database — the same pattern as PaymentStatusSummaryTest.
 *
 * @covers \Drupal\instructor_companion\Service\PostEventStatusService::computeStatus
 * @group instructor_companion
 */
class PostEventStatusTest extends UnitTestCase {

  /**
   * A fully-incomplete class with badges has all four steps outstanding.
   */
  public function testNothingDoneWithBadges(): void {
    $r = PostEventStatusService::computeStatus([
      'attendance_confirmed' => FALSE,
      'badges_applicable' => TRUE,
      'badges_total_pairs' => 6,
      'badges_done_pairs' => 0,
      'feedback_submitted' => FALSE,
      'payment_done' => FALSE,
    ]);

    $this->assertFalse($r['all_complete']);
    $this->assertSame(
      ['attendance', 'badges', 'feedback', 'payment'],
      $r['incomplete']
    );
    $this->assertSame('0/4', $r['progress']);
  }

  /**
   * An event with no badges drops the badge step from the roll-up entirely.
   */
  public function testBadgesNotApplicableAreNotCounted(): void {
    $r = PostEventStatusService::computeStatus([
      'attendance_confirmed' => TRUE,
      'badges_applicable' => FALSE,
      'feedback_submitted' => TRUE,
      'payment_done' => TRUE,
    ]);

    $this->assertTrue($r['all_complete']);
    $this->assertSame('3/3', $r['progress']);
    $this->assertNotContains('badges', $r['incomplete']);

    $badge_step = $this->step($r, 'badges');
    $this->assertFalse($badge_step['applicable']);
    $this->assertSame('No badges for this class', $badge_step['detail']);
  }

  /**
   * Badges configured but nobody attended → vacuously complete.
   */
  public function testBadgesAppleableButNoAttendeesIsComplete(): void {
    $r = PostEventStatusService::computeStatus([
      'attendance_confirmed' => TRUE,
      'badges_applicable' => TRUE,
      'badges_total_pairs' => 0,
      'badges_done_pairs' => 0,
      'feedback_submitted' => TRUE,
      'payment_done' => TRUE,
    ]);

    $this->assertTrue($r['all_complete']);
    $this->assertTrue($this->step($r, 'badges')['complete']);
  }

  /**
   * Partial badge checkout is not complete and reports remaining count.
   */
  public function testPartialBadgeCheckout(): void {
    $r = PostEventStatusService::computeStatus([
      'attendance_confirmed' => TRUE,
      'badges_applicable' => TRUE,
      'badges_total_pairs' => 8,
      'badges_done_pairs' => 3,
      'feedback_submitted' => FALSE,
      'payment_done' => FALSE,
    ]);

    $badge = $this->step($r, 'badges');
    $this->assertFalse($badge['complete']);
    $this->assertSame('5 of 8 still to check off', $badge['detail']);
    $this->assertSame(['badges', 'feedback', 'payment'], $r['incomplete']);
    $this->assertSame('1/4', $r['progress']);
  }

  /**
   * All applicable steps done → all_complete, no outstanding labels.
   */
  public function testEverythingComplete(): void {
    $r = PostEventStatusService::computeStatus([
      'attendance_confirmed' => TRUE,
      'badges_applicable' => TRUE,
      'badges_total_pairs' => 4,
      'badges_done_pairs' => 4,
      'feedback_submitted' => TRUE,
      'payment_done' => TRUE,
      'payment_detail' => 'Paid',
    ]);

    $this->assertTrue($r['all_complete']);
    $this->assertSame([], $r['incomplete']);
    $this->assertSame([], $r['incomplete_labels']);
    $this->assertSame('4/4', $r['progress']);
    $this->assertSame('Paid', $this->step($r, 'payment')['detail']);
  }

  /**
   * Incomplete labels use the human-readable step labels for the email body.
   */
  public function testIncompleteLabels(): void {
    $r = PostEventStatusService::computeStatus([
      'attendance_confirmed' => TRUE,
      'badges_applicable' => FALSE,
      'feedback_submitted' => FALSE,
      'payment_done' => FALSE,
    ]);

    $this->assertSame(
      [
        PostEventStatusService::LABELS['feedback'],
        PostEventStatusService::LABELS['payment'],
      ],
      $r['incomplete_labels']
    );
  }

  /**
   * A draft-only payment request is reported but does not count as done.
   */
  public function testDraftPaymentIsNotComplete(): void {
    $r = PostEventStatusService::computeStatus([
      'attendance_confirmed' => TRUE,
      'badges_applicable' => FALSE,
      'feedback_submitted' => TRUE,
      'payment_done' => FALSE,
      'payment_detail' => 'Draft started',
    ]);

    $payment = $this->step($r, 'payment');
    $this->assertFalse($payment['complete']);
    $this->assertSame('Draft started', $payment['detail']);
    $this->assertFalse($r['all_complete']);
  }

  /**
   * Helper: pull one step out of the computed result by key.
   */
  private function step(array $result, string $key): array {
    foreach ($result['steps'] as $step) {
      if ($step['key'] === $key) {
        return $step;
      }
    }
    $this->fail("Step '$key' not found in result.");
  }

}
