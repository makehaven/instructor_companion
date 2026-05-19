<?php

namespace Drupal\Tests\instructor_companion\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\instructor_companion\Service\InstructorDoorAccess;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\unifi_access_sync\Service\UnifiSyncManager;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests the InstructorDoorAccess provisioning + dedup guard.
 *
 * The dedup guard is security-relevant: unifi_access_sync treats any
 * non-active badge_request as "remove from UniFi", so creating a stray
 * pending request for an instructor who already holds an active door badge
 * would revoke their building access. These tests lock that behavior.
 */
#[Group('instructor_companion')]
class InstructorDoorAccessTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'taxonomy',
    'options',
    'unifi_access_sync',
  ];

  /**
   * The service under test.
   */
  protected InstructorDoorAccess $door;

  /**
   * The "Door" taxonomy term id.
   */
  protected int $doorTid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node', 'unifi_access_sync']);

    // unifi_access_sync reacts to badge_request insert/update by calling its
    // sync manager (a network client). Replace it with a mock so saving test
    // badge_request nodes never reaches UniFi.
    $sync_mock = $this->getMockBuilder(UnifiSyncManager::class)
      ->disableOriginalConstructor()
      ->getMock();
    $this->container->set('unifi_access_sync.sync_manager', $sync_mock);

    NodeType::create(['type' => 'badge_request', 'name' => 'Badge Request'])->save();
    Vocabulary::create(['vid' => 'badges', 'name' => 'Badges'])->save();

    $this->createField('node', 'badge_request', 'field_badge_requested', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'badge_request', 'field_badge_status', 'string');
    $this->createField('node', 'badge_request', 'field_member_to_badge', 'entity_reference', ['target_type' => 'user']);
    // unifi_access_sync's save hook reads these off the user.
    $this->createField('user', 'user', 'field_first_name', 'string');
    $this->createField('user', 'user', 'field_last_name', 'string');

    $door_term = Term::create(['vid' => 'badges', 'name' => 'Door']);
    $door_term->save();
    $this->doorTid = (int) $door_term->id();

    // The service reads the term id from unifi_access_sync settings so it
    // never drifts from the term the sync keys on.
    $this->config('unifi_access_sync.settings')->set('door_term_id', $this->doorTid)->save();

    $this->door = new InstructorDoorAccess(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      new NullLogger()
    );
  }

  /**
   * Helper to create a field storage + config.
   */
  protected function createField(string $entity_type, string $bundle, string $field_name, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * Creates a user.
   */
  protected function makeUser(): User {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Creates a door badge_request node in a given status.
   */
  protected function makeDoorRequest(int $uid, string $status): Node {
    $node = Node::create([
      'type' => 'badge_request',
      'title' => 'Door for ' . $uid,
      'field_badge_requested' => ['target_id' => $this->doorTid],
      'field_badge_status' => $status,
      'field_member_to_badge' => ['target_id' => $uid],
    ]);
    $node->save();
    return $node;
  }

  /**
   * A user with no door badge gets one pending request, and no duplicate.
   */
  public function testCreatesPendingWhenNone(): void {
    $uid = (int) $this->makeUser()->id();

    $request = $this->door->ensurePendingRequest($uid);
    $this->assertNotNull($request);
    $this->assertSame('pending', (string) $request->get('field_badge_status')->value);
    $this->assertTrue($this->door->hasPendingDoorBadge($uid));
    $this->assertFalse($this->door->hasActiveDoorBadge($uid));

    // Calling again must not create a duplicate.
    $this->assertNull($this->door->ensurePendingRequest($uid));
    $this->assertCount(1, $this->doorRequestNids($uid));
  }

  /**
   * The member-instructor protection: never disturb an active door badge.
   */
  public function testSkipsWhenActiveExists(): void {
    $uid = (int) $this->makeUser()->id();
    $this->makeDoorRequest($uid, 'active');

    $this->assertNull($this->door->ensurePendingRequest($uid));
    $this->assertTrue($this->door->hasActiveDoorBadge($uid));
    $this->assertFalse($this->door->hasPendingDoorBadge($uid));
    // Still exactly the one (active) request — no spurious pending node that
    // would make unifi_access_sync revoke them on next sync.
    $this->assertCount(1, $this->doorRequestNids($uid));
  }

  /**
   * An existing pending request is not duplicated.
   */
  public function testSkipsWhenPendingExists(): void {
    $uid = (int) $this->makeUser()->id();
    $this->makeDoorRequest($uid, 'pending');

    $this->assertNull($this->door->ensurePendingRequest($uid));
    $this->assertCount(1, $this->doorRequestNids($uid));
  }

  /**
   * A rejected/duplicate request is terminal and does not block re-request.
   */
  public function testTerminalRequestDoesNotBlock(): void {
    $uid = (int) $this->makeUser()->id();
    $this->makeDoorRequest($uid, 'rejected');

    $request = $this->door->ensurePendingRequest($uid);
    $this->assertNotNull($request);
    $this->assertSame('pending', (string) $request->get('field_badge_status')->value);
  }

  /**
   * With no door term configured the service is inert (no nodes created).
   */
  public function testNoDoorTermConfiguredIsInert(): void {
    $this->config('unifi_access_sync.settings')->set('door_term_id', 0)->save();
    $uid = (int) $this->makeUser()->id();

    $this->assertSame(0, $this->door->getDoorTermId());
    $this->assertNull($this->door->ensurePendingRequest($uid));
    $this->assertNull($this->door->loadDoorBadgeRequest($uid));
  }

  /**
   * All door badge_request nids for a user.
   */
  protected function doorRequestNids(int $uid): array {
    return $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_requested.target_id', $this->doorTid)
      ->execute();
  }

}
