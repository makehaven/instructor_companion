<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\AttendeeFollowupService;
use Drupal\Tests\UnitTestCase;

/**
 * Who gets which post-class email, and the words in it.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\AttendeeFollowupService
 */
class AttendeeFollowupDecisionTest extends UnitTestCase {

  /**
   * @covers ::decide
   */
  public function testProspectsGetTheJoinOfferOnce(): void {
    $this->assertSame('join', AttendeeFollowupService::decide(TRUE, FALSE, FALSE));
    $this->assertNull(AttendeeFollowupService::decide(TRUE, FALSE, TRUE), 'A second class within the repeat window sends nothing.');
    $this->assertSame('join', AttendeeFollowupService::decide(TRUE, TRUE, FALSE), 'A prospect never gets the badge nudge, whatever their badge records say.');
  }

  /**
   * @covers ::decide
   */
  public function testMembersOnlyHearAboutAPendingBadge(): void {
    $this->assertSame('badge', AttendeeFollowupService::decide(FALSE, TRUE, FALSE));
    $this->assertNull(AttendeeFollowupService::decide(FALSE, FALSE, FALSE), 'A member with nothing pending gets nothing.');
    $this->assertSame('badge', AttendeeFollowupService::decide(FALSE, TRUE, TRUE), 'The join repeat guard does not apply to members.');
  }

  /**
   * @covers ::dueWindow
   */
  public function testDueWindowTrailsTheDelayByThreeDays(): void {
    $now = strtotime('2026-09-22 12:00:00');
    [$lo, $hi] = AttendeeFollowupService::dueWindow($now, 7);
    $this->assertSame(date('Y-m-d H:i:s', $now - 7 * 86400), $hi);
    $this->assertSame(date('Y-m-d H:i:s', $now - 10 * 86400), $lo);
  }

  /**
   * @covers ::compose
   */
  public function testComposeFillsTheTokensForEachKind(): void {
    $event = ['id' => 932, 'title' => 'Intro to Stained Glass'];
    $texts = ['join_subject' => '', 'join_body' => '', 'badge_subject' => '', 'badge_body' => ''];
    $urls = ['join' => 'https://www.makehaven.org/join-makehaven', 'tour' => 'https://www.makehaven.org/visit'];

    $join = AttendeeFollowupService::compose(['kind' => 'join', 'first_name' => 'Sam'], $event, $texts, $urls);
    $this->assertStringContainsString('Intro to Stained Glass', $join['subject']);
    $this->assertStringContainsString('Hi Sam,', $join['body']);
    $this->assertStringContainsString($urls['join'], $join['body']);
    $this->assertStringContainsString($urls['tour'], $join['body']);
    $this->assertStringNotContainsString('[', $join['body'], 'No token is left unreplaced.');

    $badge = AttendeeFollowupService::compose(
      ['kind' => 'badge', 'first_name' => 'Sam', 'badge' => ['name' => 'Metal Lathe', 'url' => 'https://www.makehaven.org/badges/metal-lathe']],
      $event, $texts, $urls
    );
    $this->assertSame('Your Metal Lathe badge is waiting', $badge['subject']);
    $this->assertStringContainsString('https://www.makehaven.org/badges/metal-lathe', $badge['body']);
    $this->assertStringNotContainsString('[', $badge['body']);
  }

  /**
   * @covers ::compose
   */
  public function testConfiguredTextWins(): void {
    $texts = ['join_subject' => 'Custom [event_title]', 'join_body' => 'Body [first_name] [join_url]', 'badge_subject' => '', 'badge_body' => ''];
    $out = AttendeeFollowupService::compose(['kind' => 'join', 'first_name' => 'Ana'], ['id' => 1, 'title' => 'X'], $texts, ['join' => 'J', 'tour' => 'T']);
    $this->assertSame('Custom X', $out['subject']);
    $this->assertSame('Body Ana J', $out['body']);
  }

}
