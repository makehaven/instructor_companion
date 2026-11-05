<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\TeachingInvitation;
use Drupal\instructor_companion\Service\WorkshopInsightsService;
use Drupal\Tests\UnitTestCase;

/**
 * Who is offered teaching, what it mentions, and how classes rank.
 *
 * @group instructor_companion
 */
class TeachingInvitationTest extends UnitTestCase {

  /**
   * @dataProvider qualifiesCases
   */
  public function testQualifies(bool $ticked, bool $staffish, int $badges, int $months, bool $expected): void {
    $this->assertSame($expected, TeachingInvitation::qualifies($ticked, $staffish, $badges, 5, $months, 6));
  }

  /**
   * Signal combinations.
   */
  public static function qualifiesCases(): array {
    return [
      'ticked, nothing else' => [TRUE, FALSE, 0, 0, TRUE],
      'facilitator or issuer' => [FALSE, TRUE, 0, 0, TRUE],
      'enough badges and time' => [FALSE, FALSE, 5, 6, TRUE],
      'badges but brand new' => [FALSE, FALSE, 12, 2, FALSE],
      'time but few badges' => [FALSE, FALSE, 4, 60, FALSE],
    ];
  }

  /**
   * The card names the class with most people waiting, among badges held.
   */
  public function testTopicPicksMostWaitingAmongHeld(): void {
    $courses = [
      1 => ['title' => 'Lathe', 'badges' => [10], 'waiting' => 3],
      2 => ['title' => 'Milling', 'badges' => [20], 'waiting' => 5],
      3 => ['title' => 'Induction', 'badges' => [30], 'waiting' => 12],
    ];
    $this->assertSame('Milling', TeachingInvitation::topicFor($courses, [10 => TRUE, 20 => TRUE]));
  }

  /**
   * No waitlist or no badge held leaves the card general.
   */
  public function testTopicNullWithoutDemandOrBadge(): void {
    $courses = [1 => ['title' => 'Lathe', 'badges' => [10], 'waiting' => 0]];
    $this->assertNull(TeachingInvitation::topicFor($courses, [10 => TRUE]));
    $courses[1]['waiting'] = 8;
    $this->assertNull(TeachingInvitation::topicFor($courses, [99 => TRUE]));
  }

  /**
   * A waitlisted class with one instructor no longer ranks as covered.
   */
  public function testTierRanksWaitlistAboveDormantAndCovered(): void {
    $tier = [WorkshopInsightsService::class, 'teachingTier'];
    $this->assertSame(1, $tier(6, 40, 0, 1, FALSE), 'Sewing: scheduled, long waitlist, one instructor.');
    $this->assertSame(0, $tier(0, 3, 0, 1, FALSE), 'Uncovered with people waiting.');
    $this->assertSame(0, $tier(0, 0, 0, 1, TRUE), 'Uncovered and matches the viewer.');
    $this->assertSame(2, $tier(0, 0, 0, 1, FALSE), 'Dormant with no demand.');
    $this->assertSame(3, $tier(6, 40, 0, 2, FALSE), 'Two instructors already.');
    $this->assertSame(3, $tier(6, 4, 0, 1, FALSE), 'Short waitlist.');
  }

}
