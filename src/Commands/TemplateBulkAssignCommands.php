<?php

namespace Drupal\instructor_companion\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Bulk-assign field_civicrm_template_id on course nodes.
 *
 * Templates at MakeHaven are organized by category (Workshop, Meetup, Tour,
 * Foundations, GEMS, Pathways) — see docs/ai/EVENT_TEMPLATE_INVENTORY.md.
 * Each course should point at one of those templates so the
 * "Schedule This Course" workflow can clone it (carrying reminders, fees,
 * profiles via CRM_Event_BAO_Event::copy()).
 *
 * Defaults to --dry-run; requires --execute to write.
 */
class TemplateBulkAssignCommands extends DrushCommands {

  /**
   * Title-heuristic rules. First match wins. Default falls through to Workshop.
   *
   * @var array<int, array{pattern: string, template_id: int, template_label: string}>
   */
  protected const RULES = [
    ['pattern' => '/\bGEMS\b/i', 'template_id' => 1, 'template_label' => 'GEMS Course'],
    ['pattern' => '/\b(meetup|gathering|office hours)\b/i', 'template_id' => 72, 'template_label' => 'Meetup'],
    ['pattern' => '/\b(tour|field trip|shop tour)\b/i', 'template_id' => 54, 'template_label' => 'Tour Field Trip'],
    ['pattern' => '/^Foundations of\b/i', 'template_id' => 166, 'template_label' => 'Foundations'],
    ['pattern' => '/\bPathways?\b/i', 'template_id' => 174, 'template_label' => 'Pathways'],
  ];

  protected const DEFAULT_TEMPLATE_ID = 3;
  protected const DEFAULT_TEMPLATE_LABEL = 'Workshop';

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Bulk-assign field_civicrm_template_id on course nodes.
   *
   * @command instructor-companion:bulk-assign-templates
   * @aliases ic-bat
   * @option execute Write changes (default is dry-run, prints proposed mapping only).
   * @option overwrite Replace existing template id values (default skips courses that already have one).
   * @option only Comma-separated nids to limit to a sample.
   * @usage instructor-companion:bulk-assign-templates
   *   Dry-run: print proposed mapping for every course without writing.
   * @usage instructor-companion:bulk-assign-templates --only=40222,40223 --execute
   *   Write template ids on just those two courses.
   * @usage instructor-companion:bulk-assign-templates --execute
   *   Write template ids on all courses missing one.
   */
  public function assign(array $options = ['execute' => FALSE, 'overwrite' => FALSE, 'only' => NULL]) {
    $only = $options['only'] ? array_map('intval', explode(',', $options['only'])) : NULL;

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course');
    if ($only) {
      $query->condition('nid', $only, 'IN');
    }
    $nids = $query->execute();

    $rows = [];
    $stats = ['skip_has_id' => 0, 'assign' => 0, 'overwrite' => 0];
    foreach ($storage->loadMultiple($nids) as $node) {
      $existing = $node->hasField('field_civicrm_template_id') && !$node->get('field_civicrm_template_id')->isEmpty()
        ? (int) $node->get('field_civicrm_template_id')->value
        : NULL;
      [$tpl_id, $tpl_label, $rule] = $this->matchTemplate($node->label());

      $action = 'assign';
      if ($existing !== NULL) {
        if (!$options['overwrite']) {
          $action = 'skip (already set to ' . $existing . ')';
          $stats['skip_has_id']++;
        }
        else {
          $action = 'overwrite (' . $existing . ' → ' . $tpl_id . ')';
          $stats['overwrite']++;
        }
      }
      else {
        $stats['assign']++;
      }

      $rows[] = [
        $node->id(),
        $this->trim($node->label(), 45),
        $tpl_id . ' ' . $tpl_label,
        $rule,
        $action,
      ];

      if ($options['execute'] && ($existing === NULL || $options['overwrite'])) {
        $node->set('field_civicrm_template_id', $tpl_id);
        $node->save();
      }
    }

    $this->io()->table(
      ['NID', 'Course', 'Template', 'Rule', 'Action'],
      $rows
    );

    $this->output()->writeln(sprintf(
      "\n%s: %d would assign, %d would overwrite, %d skipped (already set)",
      $options['execute'] ? '<info>Wrote</info>' : '<comment>Dry-run</comment>',
      $stats['assign'],
      $stats['overwrite'],
      $stats['skip_has_id']
    ));

    if (!$options['execute']) {
      $this->output()->writeln('<comment>Re-run with --execute to write.</comment>');
    }
  }

  /**
   * @return array{0: int, 1: string, 2: string} [template_id, template_label, rule_description]
   */
  protected function matchTemplate(string $title): array {
    foreach (static::RULES as $rule) {
      if (preg_match($rule['pattern'], $title)) {
        return [$rule['template_id'], $rule['template_label'], $rule['pattern']];
      }
    }
    return [static::DEFAULT_TEMPLATE_ID, static::DEFAULT_TEMPLATE_LABEL, '(default)'];
  }

  protected function trim(string $s, int $max): string {
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
  }

}
