<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\instructor_companion\Service\InstructorApprovalGate;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

require_once dirname(__DIR__, 3) . '/instructor_companion.module';

/**
 * @covers ::instructor_companion_civicrm_event_create_access
 * @group instructor_companion
 */
class CivicrmEventCreateAccessTest extends UnitTestCase {

  /**
   * Tests that non-instructors remain neutral.
   */
  public function testNeutralForNonInstructor(): void {
    $this->bootContainerWithRequest('/node/add', []);

    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn(['authenticated']);

    $result = instructor_companion_civicrm_event_create_access($account, [], 'civicrm_event');
    $this->assertInstanceOf(AccessResultInterface::class, $result);
    $this->assertTrue($result->isNeutral());
  }

  /**
   * Tests that instructors are denied on propose flow until approved.
   */
  public function testForbiddenForInactiveInstructor(): void {
    $gate = $this->createMock(InstructorApprovalGate::class);
    $gate->method('isApproved')->with(42)->willReturn(FALSE);
    $this->bootContainerWithRequest('/civicrm-event/add', ['propose' => '1'], $gate);

    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn(['instructor']);
    $account->method('id')->willReturn(42);

    $result = instructor_companion_civicrm_event_create_access($account, [], 'civicrm_event');
    $this->assertTrue($result->isForbidden());
  }

  /**
   * Tests that approved instructors can access the proposal flow.
   */
  public function testAllowedForApprovedInstructor(): void {
    $gate = $this->createMock(InstructorApprovalGate::class);
    $gate->method('isApproved')->with(84)->willReturn(TRUE);
    $this->bootContainerWithRequest('/civicrm-event/add', ['propose' => '1'], $gate);

    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn(['instructor']);
    $account->method('id')->willReturn(84);

    $result = instructor_companion_civicrm_event_create_access($account, [], 'civicrm_event');
    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tests that non-proposal creates stay neutral for instructors.
   */
  public function testNeutralForInstructorOutsideProposalFlow(): void {
    $gate = $this->createMock(InstructorApprovalGate::class);
    $this->bootContainerWithRequest('/civicrm-event/add', [], $gate);

    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn(['instructor']);

    $result = instructor_companion_civicrm_event_create_access($account, [], 'civicrm_event');
    $this->assertTrue($result->isNeutral());
  }

  /**
   * Boots a minimal container for procedural hook tests.
   */
  protected function bootContainerWithRequest(string $path, array $query, ?InstructorApprovalGate $gate = NULL): void {
    $container = new ContainerBuilder();
    $request_stack = new RequestStack();
    $request_stack->push(Request::create($path, 'GET', $query));
    $container->set('request_stack', $request_stack);
    $container->set('instructor_companion.approval_gate', $gate ?? $this->createMock(InstructorApprovalGate::class));
    $container->set('cache_contexts_manager', new class() {
      public function assertValidTokens(array $contexts): bool {
        return TRUE;
      }
      public function convertTokensToKeys(array $contexts): array {
        return [];
      }
    });
    \Drupal::setContainer($container);
  }

}
