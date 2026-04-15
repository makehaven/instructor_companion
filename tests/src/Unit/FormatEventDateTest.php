<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\instructor_companion\Controller\InstructorDashboardController;
use Drupal\Tests\UnitTestCase;

/**
 * Tests InstructorDashboardController::formatEventDate().
 *
 * Regression coverage for the instructor dashboard displaying event times
 * off by several hours. CiviCRM stores civicrm_event.start_date in the
 * site's local timezone, not UTC, so parsing it as UTC was double-shifting
 * the displayed time.
 *
 * @covers \Drupal\instructor_companion\Controller\InstructorDashboardController
 * @group instructor_companion
 */
class FormatEventDateTest extends UnitTestCase {

  protected TestableFormatEventDateController $controller;

  protected function setUp(): void {
    parent::setUp();

    $immutable = $this->createMock(ImmutableConfig::class);
    $immutable->method('get')->willReturnMap([
      ['timezone.default', 'America/New_York'],
    ]);
    $config_factory = $this->createMock(\Drupal\Core\Config\ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($immutable);

    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger_factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $container = new ContainerBuilder();
    $container->set('config.factory', $config_factory);
    $container->set('logger.factory', $logger_factory);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->controller = new TestableFormatEventDateController();
  }

  /**
   * A stored 8:00 PM event should display as 8:00 PM in the site timezone.
   *
   * Regression: previously parsed as UTC and converted to America/New_York,
   * showing up as 3:00 PM (EST) or 4:00 PM (EDT).
   */
  public function testLocalStartDateDisplaysAsStoredLocalTime(): void {
    $result = $this->controller->exposedFormatEventDate('2026-02-15 20:00:00');
    $this->assertStringContainsString('8:00pm', $result);
    $this->assertStringContainsString('Feb 15, 2026', $result);
  }

  /**
   * ISO 8601 format is also parsed as site-local, not UTC.
   */
  public function testIsoFormatParsedAsSiteLocal(): void {
    $result = $this->controller->exposedFormatEventDate('2026-07-04T18:30:00');
    $this->assertStringContainsString('6:30pm', $result);
    $this->assertStringContainsString('Jul 4, 2026', $result);
  }

  /**
   * Empty string short-circuits and returns empty.
   */
  public function testEmptyInputReturnsEmpty(): void {
    $this->assertSame('', $this->controller->exposedFormatEventDate(''));
  }

}

/**
 * Exposes the protected formatEventDate() for unit testing.
 */
class TestableFormatEventDateController extends InstructorDashboardController {

  public function __construct() {
    // Bypass parent constructor which needs services we don't have here.
  }

  public function exposedFormatEventDate(string $value): string {
    return $this->formatEventDate($value);
  }

}
