<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\WorkshopInsightsService;
use Drupal\Tests\UnitTestCase;

/**
 * Which events the "no payment record" list treats as charging for a seat.
 *
 * Runs the real SQL fragment against a minimal in-memory schema, so the test
 * fails if the condition stops excluding free events (ledger #45786).
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\WorkshopInsightsService
 */
class ChargeableEventConditionTest extends UnitTestCase {

  /**
   * @covers ::chargeableEventCondition
   */
  public function testFreeEventsAreExcluded(): void {
    if (!extension_loaded('pdo_sqlite')) {
      $this->markTestSkipped('pdo_sqlite is not available.');
    }
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE civicrm_event (id INTEGER PRIMARY KEY, is_monetary INTEGER)');
    $pdo->exec('CREATE TABLE civicrm_price_set_entity (entity_table TEXT, entity_id INTEGER, price_set_id INTEGER)');
    $pdo->exec('CREATE TABLE civicrm_price_field (id INTEGER PRIMARY KEY, price_set_id INTEGER)');
    $pdo->exec('CREATE TABLE civicrm_price_field_value (price_field_id INTEGER, amount REAL, is_active INTEGER)');

    // 1: not monetary (Stop the Bleed). 2: monetary, paid option.
    // 3: monetary, only $0 options. 4: monetary, no price set rows.
    // 5: monetary, $0 active plus a paid option since disabled.
    // 6: not monetary but a stale paid price set still attached.
    $pdo->exec('INSERT INTO civicrm_event VALUES (1, 0), (2, 1), (3, 1), (4, 1), (5, 1), (6, 0)');
    $pdo->exec("INSERT INTO civicrm_price_set_entity VALUES ('civicrm_event', 2, 20), ('civicrm_event', 3, 30), ('civicrm_event', 5, 50), ('civicrm_event', 6, 60), ('civicrm_contribution_page', 3, 20)");
    $pdo->exec('INSERT INTO civicrm_price_field VALUES (200, 20), (300, 30), (500, 50), (600, 60)');
    $pdo->exec('INSERT INTO civicrm_price_field_value VALUES (200, 0, 1), (200, 63, 1), (300, 0, 1), (300, 0, 1), (500, 0, 1), (500, 40, 0), (600, 25, 1)');

    $where = strtr(WorkshopInsightsService::chargeableEventCondition('e'), ['{' => '', '}' => '']);
    $ids = $pdo->query("SELECT e.id FROM civicrm_event e WHERE $where ORDER BY e.id")->fetchAll(\PDO::FETCH_COLUMN);

    $this->assertSame([2, 4, 5], array_map('intval', $ids));
  }

}
