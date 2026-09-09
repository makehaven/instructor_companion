<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\instructor_companion\Controller\ClassCheckoutController;
use Drupal\instructor_companion\Controller\PostEventHubController;
use Drupal\instructor_companion\Controller\ProspectiveInstructorsController;
use Drupal\instructor_companion\Service\InstructorInviteManager;
use Drupal\instructor_companion\Service\PostEventStatusService;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * Exercises staff access, instructor status ownership, and invite delivery.
 *
 * @group instructor_companion
 */
class EducationWorkflowTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
  }

  /**
   * Event managers can follow the console's class task links.
   */
  public function testEventManagerCanOpenClassTasks(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => $permission === 'edit all events');
    $route = $this->createMock(RouteMatchInterface::class);
    $route->method('getParameter')->with('event_id')->willReturn(42);

    $result = (new ClassCheckoutController())->access($route, $account);

    $this->assertTrue($result->isAllowed());
    $this->assertContains('user.permissions', $result->getCacheContexts());
  }

  /**
   * Users without staff permissions need an instructor assignment.
   */
  public function testInstructorAssignmentStillControlsOtherUsers(): void {
    foreach ([TRUE, FALSE] as $assigned) {
      $account = $this->createMock(AccountInterface::class);
      $account->method('hasPermission')->willReturn(FALSE);
      $account->method('id')->willReturn(17);
      $route = $this->createMock(RouteMatchInterface::class);
      $route->method('getParameter')->with('event_id')->willReturn(42);
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchField')->willReturn($assigned ? 42 : FALSE);
      $query = $this->createMock(SelectInterface::class);
      $query->method('fields')->willReturnSelf();
      $query->method('condition')->willReturnSelf();
      $query->method('execute')->willReturn($statement);
      $database = $this->createMock(Connection::class);
      $database->method('select')->willReturn($query);
      \Drupal::getContainer()->set('database', $database);

      $result = (new ClassCheckoutController())->access($route, $account);

      $this->assertSame($assigned, $result->isAllowed());
      $this->assertSame(!$assigned, $result->isForbidden());
      $this->assertContains('user', $result->getCacheContexts());
    }
  }

  /**
   * Opening another instructor's class never checks the viewer's payments.
   */
  public function testHubUsesAssignedInstructorForPaymentStatus(): void {
    $instructor = $this->createMock(FieldItemListInterface::class);
    $instructor->method('__get')->with('target_id')->willReturn(17);
    $date = $this->createMock(FieldItemListInterface::class);
    $date->method('__get')->with('value')->willReturn('');
    $event = $this->createMock(ContentEntityInterface::class);
    $event->method('hasField')->with('field_civi_event_instructor')->willReturn(TRUE);
    $event->method('get')->willReturnMap([
      ['field_civi_event_instructor', $instructor],
      ['start_date', $date],
    ]);
    $event->method('label')->willReturn('Workshop');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(42)->willReturn($event);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('civicrm_event')->willReturn($storage);
    $status = $this->createMock(PostEventStatusService::class);
    $status->expects($this->once())->method('getStatus')->with(42, 17)->willReturn([
      'progress' => '2/3',
      'all_complete' => FALSE,
      'steps' => [],
    ]);
    $controller = $this->getMockBuilder(PostEventHubController::class)
      ->setConstructorArgs([$status])
      ->onlyMethods(['entityTypeManager', 'currentUser'])
      ->getMock();
    $controller->method('entityTypeManager')->willReturn($manager);
    $viewer = $this->createMock(AccountInterface::class);
    $viewer->method('hasPermission')->with('access education console')->willReturn(TRUE);
    $controller->method('currentUser')->willReturn($viewer);
    $controller->setStringTranslation($this->getStringTranslationStub());

    $build = $controller->build(42);

    $this->assertStringContainsString('2/3', (string) $build['header']['#markup']);
    $this->assertSame('instructor_companion.education_console', $build['back']['#url']->getRouteName());
  }

  /**
   * A failed mail handoff is not reported as a successful resend.
   */
  public function testResendReportsActualMailResult(): void {
    foreach ([TRUE, FALSE] as $sent) {
      $store = $this->createMock(KeyValueStoreInterface::class);
      $store->method('get')->with(17)->willReturn(['name' => 'Instructor']);
      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn(17);
      $user->method('getEmail')->willReturn('instructor@example.com');
      $sender = $this->createMock(AccountInterface::class);
      $invites = $this->getMockBuilder(InstructorInviteManager::class)
        ->disableOriginalConstructor()->onlyMethods(['invite'])->getMock();
      $property = new \ReflectionProperty(InstructorInviteManager::class, 'store');
      $property->setValue($invites, $store);
      $invites->expects($this->once())->method('invite')
        ->with('Instructor', 'instructor@example.com', $sender, '')
        ->willReturn(['user' => $user, 'created' => FALSE, 'sent' => $sent]);

      $this->assertSame($sent, $invites->resend($user, $sender));
    }
  }

  /**
   * Resends preserve waiting age while expiry and follow-up use the last send.
   */
  public function testInviteFollowUpAfterResend(): void {
    $now = 1800000000;
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);
    \Drupal::getContainer()->set('datetime.time', $time);
    $user = $this->createMock(UserInterface::class);
    $user->method('getEmail')->willReturn('instructor@example.com');
    $sender = $this->createMock(UserInterface::class);
    $sender->method('getDisplayName')->willReturn('Education coordinator');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(22)->willReturn($sender);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('user')->willReturn($storage);
    foreach ([1, 20] as $days_since_send) {
      $sent = $now - $days_since_send * 86400;
      $formatter = $this->createMock(DateFormatterInterface::class);
      $formatter->expects($this->once())->method('formatInterval')->with(40 * 86400, 1)->willReturn('1 month');
      $formatter->expects($this->once())->method('format')
        ->with($sent + InstructorInviteManager::TTL, 'custom', 'M j, Y')->willReturn('Expiry date');
      \Drupal::getContainer()->set('date.formatter', $formatter);
      $invites = $this->getMockBuilder(InstructorInviteManager::class)
        ->disableOriginalConstructor()->onlyMethods(['pending'])->getMock();
      $invites->method('pending')->willReturn([
        17 => [
          'user' => $user,
          'name' => 'Instructor',
          'last_sent_by' => 22,
          'invited_at' => $now - 40 * 86400,
          'last_sent' => $sent,
          'count' => 2,
        ],
      ]);
      \Drupal::getContainer()->set('instructor_companion.invite', $invites);
      $controller = $this->getMockBuilder(ProspectiveInstructorsController::class)
        ->onlyMethods(['entityTypeManager', 'actionQuery', 'usableLinks'])->getMock();
      $controller->method('entityTypeManager')->willReturn($manager);
      $controller->method('actionQuery')->willReturn([]);
      $controller->method('usableLinks')->willReturn([]);
      $controller->setStringTranslation($this->getStringTranslationStub());
      $method = new \ReflectionMethod(ProspectiveInstructorsController::class, 'buildInvitedSection');
      $build = $method->invoke($controller);
      $row = $build['table']['#rows'][0];

      $this->assertSame('1 month', $row['waiting']);
      $this->assertSame('Expiry date', $row['expires']);
      $this->assertStringContainsString('Education coordinator', (string) $row['follow_up']);
      $this->assertSame($days_since_send > 14, str_contains((string) $row['follow_up'], 'resend the expired link'));
    }
  }

}
