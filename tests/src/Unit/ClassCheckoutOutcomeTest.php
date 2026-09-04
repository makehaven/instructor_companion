<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Controller\ClassCheckoutController;
use Drupal\Tests\UnitTestCase;

/**
 * The instructor's class checkout is the badge checkout.
 *
 * @group instructor_companion
 */
class ClassCheckoutOutcomeTest extends UnitTestCase {

  /**
   * @dataProvider outcomeProvider
   */
  public function testResolveOutcome(string $status, bool $has_quiz, bool $passed, string $expected): void {
    $this->assertSame($expected, ClassCheckoutController::resolveOutcome($status, $has_quiz, $passed));
  }

  public static function outcomeProvider(): array {
    return [
      'pending + quiz passed → active' => ['pending', TRUE, TRUE, ClassCheckoutController::OUTCOME_ACTIVATE],
      'blank status counts as pending' => ['', TRUE, TRUE, ClassCheckoutController::OUTCOME_ACTIVATE],
      'no quiz on the badge → active' => ['pending', FALSE, FALSE, ClassCheckoutController::OUTCOME_ACTIVATE],
      'pending + quiz not passed → wait' => ['pending', TRUE, FALSE, ClassCheckoutController::OUTCOME_AWAIT_QUIZ],
      'already active is left alone' => ['active', TRUE, TRUE, ClassCheckoutController::OUTCOME_ALREADY_ACTIVE],
      'case-insensitive' => ['Active', TRUE, FALSE, ClassCheckoutController::OUTCOME_ALREADY_ACTIVE],
      'suspended is staff-managed' => ['suspended', TRUE, TRUE, ClassCheckoutController::OUTCOME_LEAVE],
      'expired is staff-managed' => ['expired', FALSE, FALSE, ClassCheckoutController::OUTCOME_LEAVE],
    ];
  }

}
