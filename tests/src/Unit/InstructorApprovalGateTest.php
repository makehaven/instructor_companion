<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\instructor_companion\Service\InstructorApprovalGate;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\instructor_companion\Service\InstructorApprovalGate
 * @group instructor_companion
 */
class InstructorApprovalGateTest extends UnitTestCase {

  /**
   * @covers ::isApproved
   */
  public function testApprovedInstructor(): void {
    $profile = $this->createConfiguredMock(ProfileInterface::class, [
      'hasField' => TRUE,
    ]);
    $fieldList = new class() {
      public function isEmpty(): bool {
        return FALSE;
      }
      public function __get(string $name): ?string {
        return $name === 'value' ? 'active' : NULL;
      }
    };
    $profile->method('get')->with('field_instructor_status')->willReturn($fieldList);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([$profile]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('profile')->willReturn($storage);

    $gate = new InstructorApprovalGate($entityTypeManager);
    $this->assertTrue($gate->isApproved(7));
  }

  /**
   * @covers ::isApproved
   */
  public function testInactiveInstructorNotApproved(): void {
    $profile = $this->createConfiguredMock(ProfileInterface::class, [
      'hasField' => TRUE,
    ]);
    $fieldList = new class() {
      public function isEmpty(): bool {
        return FALSE;
      }
      public function __get(string $name): ?string {
        return $name === 'value' ? 'inactive' : NULL;
      }
    };
    $profile->method('get')->with('field_instructor_status')->willReturn($fieldList);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([$profile]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('profile')->willReturn($storage);

    $gate = new InstructorApprovalGate($entityTypeManager);
    $this->assertFalse($gate->isApproved(8));
  }

  /**
   * @covers ::isApproved
   */
  public function testMissingProfileNotApproved(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('profile')->willReturn($storage);

    $gate = new InstructorApprovalGate($entityTypeManager);
    $this->assertFalse($gate->isApproved(0));
    $this->assertFalse($gate->isApproved(9));
  }

}
