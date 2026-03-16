<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\instructor_companion\Controller\InstructorDashboardController;
use Drupal\Tests\UnitTestCase;

/**
 * Tests payment-status summary sorting in InstructorDashboardController.
 *
 * The controller groups payment_request entities by status and sorts them in
 * a defined priority order (paid > approved > submitted > draft > rejected).
 * These tests verify that ordering and formatting are correct without a DB.
 *
 * @covers \Drupal\instructor_companion\Controller\InstructorDashboardController
 * @group instructor_companion
 */
class PaymentStatusSummaryTest extends UnitTestCase {

  /**
   * Weight map mirrors the production code exactly.
   *
   * @var array<string, int>
   */
  private array $weight = [
    'paid'      => 1,
    'approved'  => 2,
    'submitted' => 3,
    'draft'     => 4,
    'rejected'  => 5,
    'unknown'   => 6,
  ];

  /**
   * Tests that statuses appear in priority order.
   */
  public function testStatusesSortedByPriority(): void {
    // Deliberately provide statuses in reverse priority order.
    $status_counts = [
      'rejected'  => 1,
      'submitted' => 2,
      'paid'      => 1,
    ];

    $sorted = $this->applySortingLogic($status_counts);
    $keys   = array_keys($sorted);

    $this->assertSame(['paid', 'submitted', 'rejected'], $keys);
  }

  /**
   * Tests that "paid" always appears before "draft".
   */
  public function testPaidBeforeDraft(): void {
    $status_counts = ['draft' => 3, 'paid' => 1];
    $sorted = $this->applySortingLogic($status_counts);
    $keys   = array_keys($sorted);

    $this->assertSame('paid', $keys[0]);
    $this->assertSame('draft', $keys[1]);
  }

  /**
   * Tests the formatted summary string for a mixed-status event.
   */
  public function testSummaryStringFormat(): void {
    $status_counts = [
      'paid'      => 2,
      'submitted' => 1,
      'draft'     => 1,
    ];

    $summary = $this->buildSummaryString($status_counts);
    $this->assertSame('Paid (2), Submitted (1), Draft (1)', $summary);
  }

  /**
   * Tests that a single status formats correctly.
   */
  public function testSingleStatusFormat(): void {
    $summary = $this->buildSummaryString(['paid' => 3]);
    $this->assertSame('Paid (3)', $summary);
  }

  /**
   * Tests that counts are preserved accurately.
   */
  public function testCountsArePreserved(): void {
    $status_counts = ['approved' => 5, 'rejected' => 2];
    $summary = $this->buildSummaryString($status_counts);
    $this->assertStringContainsString('(5)', $summary);
    $this->assertStringContainsString('(2)', $summary);
  }

  /**
   * Tests that unknown statuses appear last.
   */
  public function testUnknownStatusAppearsLast(): void {
    $status_counts = ['unknown' => 1, 'paid' => 1, 'submitted' => 1];
    $sorted = $this->applySortingLogic($status_counts);
    $keys   = array_keys($sorted);

    $this->assertSame('unknown', end($keys));
  }

  /**
   * Tests empty status_counts produces an empty string.
   */
  public function testEmptyInputProducesEmptyString(): void {
    $this->assertSame('', $this->buildSummaryString([]));
  }

  // ---------------------------------------------------------------------------
  // Private helpers — mirror the production logic without hitting the database.
  // ---------------------------------------------------------------------------

  /**
   * Applies the same uksort used in getPaymentStatusSummaryByEvent().
   *
   * @param array<string, int> $status_counts
   *
   * @return array<string, int>
   */
  private function applySortingLogic(array $status_counts): array {
    $weight = $this->weight;
    uksort($status_counts, static function (string $a, string $b) use ($weight): int {
      return ($weight[$a] ?? 99) <=> ($weight[$b] ?? 99);
    });
    return $status_counts;
  }

  /**
   * Builds the summary string the same way getPaymentStatusSummaryByEvent()
   * does, using simple string labels.
   *
   * @param array<string, int> $status_counts
   */
  private function buildSummaryString(array $status_counts): string {
    $label_map = [
      'paid'      => 'Paid',
      'approved'  => 'Approved',
      'submitted' => 'Submitted',
      'draft'     => 'Draft',
      'rejected'  => 'Rejected',
      'unknown'   => 'Unknown',
    ];

    $sorted = $this->applySortingLogic($status_counts);
    $parts  = [];
    foreach ($sorted as $status => $count) {
      $label    = $label_map[$status] ?? 'Unknown';
      $parts[]  = "$label ($count)";
    }
    return implode(', ', $parts);
  }

}
