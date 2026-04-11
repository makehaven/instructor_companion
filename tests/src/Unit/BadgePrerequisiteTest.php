<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\instructor_companion\Controller\InstructorDashboardController;
use Drupal\Tests\UnitTestCase;

/**
 * Tests badge prerequisite / badger-status logic.
 *
 * Covers:
 *  - instructor_companion_missing_badges() (module procedural function)
 *  - InstructorDashboardController::getBadgePrerequisiteGaps()
 *
 * "Badger" status means the user appears in field_badge_issuer on the badge
 * taxonomy term — a staff-granted role earned after: earn badge → shadow a
 * badger → supervised session → staff approval. Holding an active badge_request
 * alone is NOT sufficient.
 *
 * @group instructor_companion
 */
class BadgePrerequisiteTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger_factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $container = new ContainerBuilder();
    $container->set('logger.factory', $logger_factory);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    // Load the module file so the procedural functions are available.
    require_once __DIR__ . '/../../../instructor_companion.module';
  }

  // ---------------------------------------------------------------------------
  // instructor_companion_missing_badges() tests
  // ---------------------------------------------------------------------------

  /**
   * Empty badge term list returns no missing badges.
   */
  public function testEmptyBadgeTermsReturnsEmpty(): void {
    $this->assertSame([], instructor_companion_missing_badges([], 42));
  }

  /**
   * UID 0 (not logged in) always returns empty — nothing to check.
   */
  public function testUidZeroReturnsEmpty(): void {
    $badge = $this->mockBadgeTerm('Laser Cutter', []);
    $this->assertSame([], instructor_companion_missing_badges([$badge], 0));
  }

  /**
   * User who IS in field_badge_issuer is not flagged as missing.
   */
  public function testUserWhoIsBadgerNotFlagged(): void {
    $badge = $this->mockBadgeTerm('Laser Cutter', [42]);
    $result = instructor_companion_missing_badges([$badge], 42);
    $this->assertEmpty($result);
  }

  /**
   * User who is NOT in field_badge_issuer is flagged with the badge label.
   */
  public function testUserWhoIsNotBadgerIsFlagged(): void {
    $badge = $this->mockBadgeTerm('Laser Cutter', [99]);
    $result = instructor_companion_missing_badges([$badge], 42);
    $this->assertSame(['Laser Cutter'], $result);
  }

  /**
   * Badge with no issuers at all flags the user.
   */
  public function testBadgeWithNoIssuersAlwaysFlags(): void {
    $badge = $this->mockBadgeTerm('Plasma Cutter', []);
    $result = instructor_companion_missing_badges([$badge], 42);
    $this->assertSame(['Plasma Cutter'], $result);
  }

  /**
   * Multiple badges — only the ones the user isn't a badger for are returned.
   */
  public function testOnlyMissingBadgesReturned(): void {
    $laser   = $this->mockBadgeTerm('Laser Cutter', [42, 55]);  // user is badger
    $plasma  = $this->mockBadgeTerm('Plasma Cutter', [55]);     // user is NOT badger
    $welding = $this->mockBadgeTerm('Welding', [42]);            // user is badger

    $result = instructor_companion_missing_badges([$laser, $plasma, $welding], 42);
    $this->assertSame(['Plasma Cutter'], $result);
  }

  /**
   * User ID is compared as int — string '42' in issuer list still matches uid 42.
   */
  public function testIssuerUidStoredAsStringStillMatches(): void {
    $badge = $this->mockBadgeTerm('Laser Cutter', ['42']);
    $result = instructor_companion_missing_badges([$badge], 42);
    $this->assertEmpty($result, 'String UID in field value should match integer UID via intval cast');
  }

  // ---------------------------------------------------------------------------
  // InstructorDashboardController::getBadgePrerequisiteGaps() tests
  // ---------------------------------------------------------------------------

  /**
   * Event with no field_civi_event_badges produces no gaps.
   */
  public function testEventWithNoBadgeFieldProducesNoGaps(): void {
    $event = $this->mockEvent(hasBadgeField: FALSE);
    $controller = new TestableBadgeController();
    $this->assertSame([], $controller->exposedGetBadgePrerequisiteGaps(42, [$event]));
  }

  /**
   * Event with an empty badge field produces no gaps.
   */
  public function testEventWithEmptyBadgeFieldProducesNoGaps(): void {
    $event = $this->mockEvent(hasBadgeField: TRUE, badgeTerms: [], badgeFieldEmpty: TRUE);
    $controller = new TestableBadgeController();
    $this->assertSame([], $controller->exposedGetBadgePrerequisiteGaps(42, [$event]));
  }

  /**
   * Instructor who is a badger for all event badges produces no gaps.
   */
  public function testInstructorWhoIsBadgerProducesNoGaps(): void {
    $badge = $this->mockBadgeTerm('Laser Cutter', [42]);
    $event = $this->mockEvent(hasBadgeField: TRUE, badgeTerms: [$badge]);
    $controller = new TestableBadgeController();
    $this->assertSame([], $controller->exposedGetBadgePrerequisiteGaps(42, [$event]));
  }

  /**
   * Instructor who is not a badger gets a gap entry with event + badge labels.
   */
  public function testInstructorNotBadgerProducesGap(): void {
    $badge = $this->mockBadgeTerm('Plasma Cutter', [99]);
    $event = $this->mockEvent(hasBadgeField: TRUE, badgeTerms: [$badge], eventLabel: 'Metal Shop 101');
    $controller = new TestableBadgeController();
    $gaps = $controller->exposedGetBadgePrerequisiteGaps(42, [$event]);

    $this->assertCount(1, $gaps);
    $this->assertSame('Metal Shop 101', $gaps[0]['event_label']);
    $this->assertSame('Plasma Cutter', $gaps[0]['badge_label']);
  }

  /**
   * Multiple events — gaps only appear for events where the instructor is not a badger.
   */
  public function testOnlyMissingBadgesAppearInGaps(): void {
    $laser  = $this->mockBadgeTerm('Laser Cutter', [42]);    // user is badger
    $plasma = $this->mockBadgeTerm('Plasma Cutter', [99]);   // user is NOT badger

    $event1 = $this->mockEvent(hasBadgeField: TRUE, badgeTerms: [$laser],  eventLabel: 'Laser 101');
    $event2 = $this->mockEvent(hasBadgeField: TRUE, badgeTerms: [$plasma], eventLabel: 'Metal Shop 101');

    $controller = new TestableBadgeController();
    $gaps = $controller->exposedGetBadgePrerequisiteGaps(42, [$event1, $event2]);

    $this->assertCount(1, $gaps);
    $this->assertSame('Metal Shop 101', $gaps[0]['event_label']);
    $this->assertSame('Plasma Cutter', $gaps[0]['badge_label']);
  }

  /**
   * One event with multiple badges — only the missing ones produce gaps.
   */
  public function testOneEventMultipleBadgesMixedStatus(): void {
    $laser  = $this->mockBadgeTerm('Laser Cutter', [42]);    // user is badger
    $plasma = $this->mockBadgeTerm('Plasma Cutter', [99]);   // user is NOT badger

    $event = $this->mockEvent(hasBadgeField: TRUE, badgeTerms: [$laser, $plasma], eventLabel: 'Advanced Metal');
    $controller = new TestableBadgeController();
    $gaps = $controller->exposedGetBadgePrerequisiteGaps(42, [$event]);

    $this->assertCount(1, $gaps);
    $this->assertSame('Plasma Cutter', $gaps[0]['badge_label']);
  }

  /**
   * Empty event list produces no gaps.
   */
  public function testEmptyEventListProducesNoGaps(): void {
    $controller = new TestableBadgeController();
    $this->assertSame([], $controller->exposedGetBadgePrerequisiteGaps(42, []));
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds a mock badge taxonomy term.
   *
   * @param string $label  Badge label.
   * @param array $issuer_uids  UIDs in field_badge_issuer (may be strings or ints).
   */
  private function mockBadgeTerm(string $label, array $issuer_uids): object {
    $values = array_map(fn($uid) => ['target_id' => (string) $uid], $issuer_uids);

    $field_list = $this->createMock(FieldItemListInterface::class);
    $field_list->method('getValue')->willReturn($values);

    $badge = $this->createMock(\Drupal\taxonomy\TermInterface::class);
    $badge->method('get')->with('field_badge_issuer')->willReturn($field_list);
    $badge->method('label')->willReturn($label);

    return $badge;
  }

  /**
   * Builds a mock CiviCRM event entity.
   *
   * @param bool $hasBadgeField  Whether field_civi_event_badges exists.
   * @param array $badgeTerms    Badge terms returned by referencedEntities().
   * @param bool $badgeFieldEmpty  Whether the badge field reports empty.
   * @param string $eventLabel   Event label/title.
   */
  private function mockEvent(
    bool $hasBadgeField,
    array $badgeTerms = [],
    bool $badgeFieldEmpty = FALSE,
    string $eventLabel = 'Test Class',
  ): object {
    $event = $this->createMock(\Drupal\Core\Entity\FieldableEntityInterface::class);
    $event->method('label')->willReturn($eventLabel);

    if (!$hasBadgeField) {
      $event->method('hasField')->with('field_civi_event_badges')->willReturn(FALSE);
      return $event;
    }

    $event->method('hasField')->with('field_civi_event_badges')->willReturn(TRUE);

    $badge_field = $this->createMock(\Drupal\Core\Field\EntityReferenceFieldItemListInterface::class);
    $badge_field->method('isEmpty')->willReturn($badgeFieldEmpty);
    $badge_field->method('referencedEntities')->willReturn($badgeTerms);

    $start_date_item = new \stdClass();
    $start_date_item->value = '2026-05-01 10:00:00';
    $start_date_field = $this->createMock(FieldItemListInterface::class);
    $start_date_field->value = '2026-05-01 10:00:00';

    $event->method('get')->willReturnMap([
      ['field_civi_event_badges', $badge_field],
      ['start_date', $start_date_field],
    ]);

    return $event;
  }

}

/**
 * Exposes protected methods of InstructorDashboardController for unit testing.
 * Overrides formatEventDate() to avoid config system dependency.
 */
class TestableBadgeController extends InstructorDashboardController {

  /**
   * {@inheritdoc}
   */
  protected function formatEventDate(string $start_date_value): string {
    return 'May 1, 2026';
  }

  /**
   * Proxy for the protected getBadgePrerequisiteGaps().
   */
  public function exposedGetBadgePrerequisiteGaps(int $uid, array $upcoming_events): array {
    return $this->getBadgePrerequisiteGaps($uid, $upcoming_events);
  }

}
