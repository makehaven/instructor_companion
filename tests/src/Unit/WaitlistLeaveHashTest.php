<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\WaitlistManager;
use Drupal\Tests\UnitTestCase;

/**
 * The signed "leave the waiting list" link.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\WaitlistManager
 */
class WaitlistLeaveHashTest extends UnitTestCase {

  /**
   * @covers ::computeLeaveHash
   */
  public function testHashIsStableAndBoundToPersonCourseAndSecret(): void {
    $a = WaitlistManager::computeLeaveHash(15239, 40387, 'secret-1');
    $this->assertSame($a, WaitlistManager::computeLeaveHash(15239, 40387, 'secret-1'));
    $this->assertNotSame($a, WaitlistManager::computeLeaveHash(15240, 40387, 'secret-1'), 'Another person cannot reuse it.');
    $this->assertNotSame($a, WaitlistManager::computeLeaveHash(15239, 40388, 'secret-1'), 'Another course cannot reuse it.');
    $this->assertNotSame($a, WaitlistManager::computeLeaveHash(15239, 40387, 'secret-2'), 'Another site cannot forge it.');
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{20,}$/', $a, 'URL-safe.');
  }

}
