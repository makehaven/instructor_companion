<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\InstructorInviteManager;
use Drupal\Tests\UnitTestCase;

/**
 * The pure parts of the invite link: hash inputs, expiry, name splitting.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\InstructorInviteManager
 */
class InstructorInviteTokenTest extends UnitTestCase {

  /**
   * @covers ::computeHash
   */
  public function testHashChangesWithEveryInputItMustOutlive(): void {
    $base = InstructorInviteManager::computeHash(42, 1000, 'a@example.com', 'hashA', 'salt');

    $this->assertSame($base, InstructorInviteManager::computeHash(42, 1000, 'a@example.com', 'hashA', 'salt'), 'Deterministic.');
    $this->assertNotSame($base, InstructorInviteManager::computeHash(43, 1000, 'a@example.com', 'hashA', 'salt'), 'Another account.');
    $this->assertNotSame($base, InstructorInviteManager::computeHash(42, 1001, 'a@example.com', 'hashA', 'salt'), 'Another issue time.');
    $this->assertNotSame($base, InstructorInviteManager::computeHash(42, 1000, 'b@example.com', 'hashA', 'salt'), 'Email changed.');
    $this->assertNotSame($base, InstructorInviteManager::computeHash(42, 1000, 'a@example.com', 'hashB', 'salt'), 'Password set after invite invalidates it.');
    $this->assertNotSame($base, InstructorInviteManager::computeHash(42, 1000, 'a@example.com', 'hashA', 'other'), 'Another site.');
  }

  /**
   * @covers ::isWithinTtl
   */
  public function testTtlIsFourteenDays(): void {
    $issued = 1_700_000_000;
    $this->assertTrue(InstructorInviteManager::isWithinTtl($issued, $issued));
    $this->assertTrue(InstructorInviteManager::isWithinTtl($issued, $issued + 13 * 86400));
    $this->assertTrue(InstructorInviteManager::isWithinTtl($issued, $issued + 14 * 86400 - 1));
    $this->assertFalse(InstructorInviteManager::isWithinTtl($issued, $issued + 14 * 86400));
    $this->assertFalse(InstructorInviteManager::isWithinTtl($issued, $issued + 30 * 86400));
  }

  /**
   * @covers ::splitName
   * @dataProvider names
   */
  public function testSplitName(string $input, array $expected): void {
    $this->assertSame($expected, InstructorInviteManager::splitName($input));
  }

  public static function names(): array {
    return [
      'first last' => ['Ada Lovelace', ['Ada', 'Lovelace']],
      'three parts keep the last as surname' => ['Mary Ann Evans', ['Mary Ann', 'Evans']],
      'single word' => ['Cher', ['Cher', '']],
      'messy whitespace' => ["  Ada \t Lovelace  ", ['Ada', 'Lovelace']],
      'empty' => ['', ['', '']],
    ];
  }

}
