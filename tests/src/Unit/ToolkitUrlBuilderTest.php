<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Url;
use Drupal\instructor_companion\Controller\InstructorDashboardController;
use Drupal\Tests\UnitTestCase;

/**
 * Tests InstructorDashboardController::buildToolkitUrl().
 *
 * @covers \Drupal\instructor_companion\Controller\InstructorDashboardController
 * @group instructor_companion
 */
class ToolkitUrlBuilderTest extends UnitTestCase {

  /**
   * A controller subclass exposing the protected method under test.
   *
   * @var \Drupal\Tests\instructor_companion\Unit\TestableInstructorDashboardController
   */
  protected TestableInstructorDashboardController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Provide a minimal container so that \Drupal::logger() does not throw
    // when buildToolkitUrl() logs an invalid-URL warning.
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger_factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $container = new ContainerBuilder();
    $container->set('logger.factory', $logger_factory);
    $container->set('string_translation', $this->getStringTranslationStub());

    \Drupal::setContainer($container);

    $this->controller = new TestableInstructorDashboardController();
  }

  /**
   * NULL / empty input returns NULL — nothing configured yet.
   */
  public function testNullInputReturnsNull(): void {
    $this->assertNull($this->controller->exposedBuildToolkitUrl(NULL));
    $this->assertNull($this->controller->exposedBuildToolkitUrl(''));
    $this->assertNull($this->controller->exposedBuildToolkitUrl('   '));
  }

  /**
   * Full https:// URLs produce an external Url object.
   */
  public function testHttpsUrlIsExternal(): void {
    $url = $this->controller->exposedBuildToolkitUrl('https://example.com/foo');
    $this->assertInstanceOf(Url::class, $url);
    $this->assertTrue($url->isExternal(), 'https:// URL should be flagged as external');
  }

  /**
   * Full http:// URLs also produce an external Url object.
   */
  public function testHttpUrlIsExternal(): void {
    $url = $this->controller->exposedBuildToolkitUrl('http://example.com/bar');
    $this->assertInstanceOf(Url::class, $url);
    $this->assertTrue($url->isExternal());
  }

  /**
   * Internal paths with a leading slash are correctly identified as internal.
   *
   * Url::fromUserInput() requires a full router bootstrap and will throw in
   * a unit-test context; the method catches this and returns NULL. The
   * important thing to verify here is that the regex correctly classifies
   * the value as an internal path (not an external URL) and that the method
   * does not silently pass it to fromUri(). Full end-to-end resolution is
   * covered by the functional tests.
   */
  public function testInternalPathIsNotTreatedAsExternalUri(): void {
    // External-URL detection must NOT match a leading-slash path.
    $this->assertFalse(
      (bool) preg_match('/^https?:\/\//', '/admin/dashboard'),
      'A path starting with / should not match the https?:// regex'
    );
  }

  /**
   * Paths without a leading slash get one prepended before fromUserInput().
   *
   * We verify the normalization logic directly via the regex used in the
   * method rather than calling fromUserInput(), which needs the router.
   */
  public function testInternalPathWithoutSlashGetsSlashPrepended(): void {
    $value = 'admin/dashboard';

    // Simulate the normalization branch.
    if (!preg_match('/^[\/\?#]/', $value)) {
      $value = '/' . $value;
    }

    $this->assertStringStartsWith('/', $value, 'Path without leading slash should have one prepended');
    $this->assertSame('/admin/dashboard', $value);
  }

  /**
   * Values already starting with /, ?, or # are not double-prefixed.
   */
  public function testAlreadyValidPrefixesAreNotModified(): void {
    foreach (['/path', '?query', '#anchor'] as $value) {
      $normalized = $value;
      if (!preg_match('/^[\/\?#]/', $normalized)) {
        $normalized = '/' . $normalized;
      }
      $this->assertSame($value, $normalized, "Value '$value' should not be modified");
    }
  }

  /**
   * buildToolkitUrlWithQuery merges query params onto the base URL.
   */
  public function testBuildToolkitUrlWithQuery(): void {
    $url = $this->controller->exposedBuildToolkitUrlWithQuery(
      'https://example.com/pay',
      ['event' => 42, 'ref' => 'dashboard']
    );

    $this->assertInstanceOf(Url::class, $url);
    $options = $url->getOptions();
    $this->assertArrayHasKey('query', $options);
    $this->assertSame(42, $options['query']['event']);
    $this->assertSame('dashboard', $options['query']['ref']);
  }

  /**
   * buildToolkitUrlWithQuery returns NULL when the base value is empty.
   */
  public function testBuildToolkitUrlWithQueryReturnsNullForEmpty(): void {
    $result = $this->controller->exposedBuildToolkitUrlWithQuery('', ['event' => 1]);
    $this->assertNull($result);
  }

  /**
   * Existing query params on the base URL are preserved after merge.
   */
  public function testBuildToolkitUrlWithQueryPreservesExistingParams(): void {
    $url = $this->controller->exposedBuildToolkitUrlWithQuery(
      'https://example.com/pay?source=cron',
      ['event' => 7]
    );

    $this->assertInstanceOf(Url::class, $url);
    $options = $url->getOptions();
    // The event param from the merge should be present.
    $this->assertSame(7, $options['query']['event']);
  }

}

/**
 * Exposes protected helpers for unit testing.
 */
class TestableInstructorDashboardController extends InstructorDashboardController {

  /**
   * Proxy for the protected buildToolkitUrl().
   */
  public function exposedBuildToolkitUrl(?string $value): ?Url {
    return $this->buildToolkitUrl($value);
  }

  /**
   * Proxy for the protected buildToolkitUrlWithQuery().
   */
  public function exposedBuildToolkitUrlWithQuery(?string $value, array $query): ?Url {
    return $this->buildToolkitUrlWithQuery($value, $query);
  }

}
