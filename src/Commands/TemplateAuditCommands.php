<?php

namespace Drupal\instructor_companion\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drush\Commands\DrushCommands;

/**
 * Audit CiviCRM event templates and their linkage to course nodes.
 *
 * Read-only. Snapshots: which CiviCRM events are templates, what reminders /
 * fees / profiles they have attached, which courses link to them via
 * field_civicrm_template_id, and which courses are missing a template id.
 *
 * The output is the input to the staff-driven "Schedule This Course" workflow
 * (see ScheduleInstanceController) — that flow can only clone a template that
 * actually exists and has reminders attached, so we need to know the gaps.
 */
class TemplateAuditCommands extends DrushCommands {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected Connection $database;
  protected FileSystemInterface $fileSystem;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, FileSystemInterface $file_system) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->fileSystem = $file_system;
  }

  /**
   * Audit CiviCRM event templates and course → template linkage.
   *
   * @command instructor-companion:audit-templates
   * @aliases ic-aud-tpl
   * @option output Absolute path for the JSON snapshot. Defaults to scripts/data/civicrm-template-audit-{timestamp}.json under DRUPAL_ROOT/..
   * @usage instructor-companion:audit-templates
   *   Print a table and write a JSON snapshot.
   */
  public function audit(array $options = ['output' => NULL]) {
    \Drupal::service('civicrm')->initialize();

    $templates = $this->collectTemplates();
    $courses = $this->collectCourses();

    $this->renderTemplateTable($templates);
    $this->renderCourseTable($courses, $templates);
    $this->renderSummary($templates, $courses);

    $path = $this->writeSnapshot($templates, $courses, $options['output']);
    $this->logger()->success(dt('Snapshot written to @path', ['@path' => $path]));
  }

  /**
   * @return array<int, array<string, mixed>> Keyed by template event id.
   */
  protected function collectTemplates(): array {
    $result = \civicrm_api3('Event', 'get', [
      'is_template' => 1,
      'options' => ['limit' => 0],
      'return' => [
        'id', 'template_title', 'title', 'event_type_id', 'is_active',
        'is_online_registration', 'is_monetary',
      ],
    ]);

    $rows = [];
    foreach ($result['values'] as $tpl) {
      $id = (int) $tpl['id'];
      $rows[$id] = [
        'id' => $id,
        'template_title' => $tpl['template_title'] ?? $tpl['title'] ?? '(untitled)',
        'event_type_id' => $tpl['event_type_id'] ?? NULL,
        'is_active' => (int) ($tpl['is_active'] ?? 0),
        'is_online_registration' => (int) ($tpl['is_online_registration'] ?? 0),
        'is_monetary' => (int) ($tpl['is_monetary'] ?? 0),
        'reminder_count' => $this->countReminders($id),
        'price_set_id' => $this->lookupPriceSet($id),
        'profile_count' => $this->countProfiles($id),
      ];
    }

    ksort($rows);
    return $rows;
  }

  /**
   * Count civicrm_action_schedule rows targeting this specific event id.
   *
   * Reminders attached to a single event use mapping_id 5 ("Event Name") and
   * store the event id in entity_value. Reminders attached at the event-type
   * or status level use other mappings and aren't event-specific, so we skip
   * them here.
   */
  protected function countReminders(int $event_id): int {
    return (int) $this->database->query(
      "SELECT COUNT(*) FROM civicrm_action_schedule
       WHERE mapping_id = '5' AND entity_value = :id",
      [':id' => (string) $event_id]
    )->fetchField();
  }

  protected function lookupPriceSet(int $event_id): ?int {
    $id = $this->database->query(
      "SELECT price_set_id FROM civicrm_price_set_entity
       WHERE entity_table = 'civicrm_event' AND entity_id = :id",
      [':id' => $event_id]
    )->fetchField();
    return $id !== FALSE ? (int) $id : NULL;
  }

  protected function countProfiles(int $event_id): int {
    return (int) $this->database->query(
      "SELECT COUNT(*) FROM civicrm_uf_join
       WHERE entity_table = 'civicrm_event' AND entity_id = :id",
      [':id' => $event_id]
    )->fetchField();
  }

  /**
   * @return array<int, array<string, mixed>> Keyed by course node id.
   */
  protected function collectCourses(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $tpl_id = $node->hasField('field_civicrm_template_id') && !$node->get('field_civicrm_template_id')->isEmpty()
        ? (int) $node->get('field_civicrm_template_id')->value
        : NULL;

      $upcoming = $this->database->query(
        "SELECT COUNT(*) FROM civicrm_event__field_parent_course pc
         JOIN civicrm_event e ON e.id = pc.entity_id
         WHERE pc.field_parent_course_target_id = :nid
           AND e.is_active = 1
           AND e.is_template = 0
           AND e.start_date >= NOW()",
        [':nid' => $node->id()]
      )->fetchField();

      $past_active = $this->database->query(
        "SELECT e.id FROM civicrm_event__field_parent_course pc
         JOIN civicrm_event e ON e.id = pc.entity_id
         WHERE pc.field_parent_course_target_id = :nid
           AND e.is_template = 0
         ORDER BY e.start_date DESC
         LIMIT 1",
        [':nid' => $node->id()]
      )->fetchField();

      $rows[(int) $node->id()] = [
        'nid' => (int) $node->id(),
        'title' => $node->label(),
        'status' => $node->isPublished() ? 'published' : 'unpublished',
        'template_id' => $tpl_id,
        'upcoming_instances' => (int) $upcoming,
        'most_recent_event_id' => $past_active !== FALSE ? (int) $past_active : NULL,
      ];
    }

    ksort($rows);
    return $rows;
  }

  protected function renderTemplateTable(array $templates): void {
    if (empty($templates)) {
      $this->output()->writeln('<comment>No CiviCRM event templates found (is_template = 1).</comment>');
      return;
    }
    $this->output()->writeln("\n<info>CiviCRM Event Templates (is_template = 1)</info>");
    $rows = [];
    foreach ($templates as $tpl) {
      $rows[] = [
        $tpl['id'],
        $this->trim($tpl['template_title'], 50),
        $tpl['event_type_id'],
        $tpl['is_active'] ? 'yes' : 'no',
        $tpl['is_online_registration'] ? 'yes' : 'no',
        $tpl['reminder_count'],
        $tpl['price_set_id'] ?? '—',
        $tpl['profile_count'],
      ];
    }
    $this->io()->table(
      ['ID', 'Title', 'Type', 'Active', 'Online Reg', 'Reminders', 'Price Set', 'Profiles'],
      $rows
    );
  }

  protected function renderCourseTable(array $courses, array $templates): void {
    $this->output()->writeln("\n<info>Courses and their CiviCRM template linkage</info>");
    $rows = [];
    foreach ($courses as $c) {
      $tpl_state = '—';
      if ($c['template_id'] === NULL) {
        $tpl_state = '<error>missing</error>';
      }
      elseif (!isset($templates[$c['template_id']])) {
        $tpl_state = '<error>broken (id ' . $c['template_id'] . ' not a template)</error>';
      }
      else {
        $r = $templates[$c['template_id']]['reminder_count'];
        $tpl_state = $c['template_id'] . ' (' . $r . ' rem)';
      }
      $rows[] = [
        $c['nid'],
        $this->trim($c['title'], 45),
        $c['status'],
        $tpl_state,
        $c['upcoming_instances'],
        $c['most_recent_event_id'] ?? '—',
      ];
    }
    $this->io()->table(
      ['NID', 'Title', 'Status', 'Template ID', 'Upcoming', 'Last Event'],
      $rows
    );
  }

  protected function renderSummary(array $templates, array $courses): void {
    $with_tpl = 0;
    $missing_tpl = 0;
    $broken_tpl = 0;
    foreach ($courses as $c) {
      if ($c['template_id'] === NULL) {
        $missing_tpl++;
      }
      elseif (!isset($templates[$c['template_id']])) {
        $broken_tpl++;
      }
      else {
        $with_tpl++;
      }
    }

    $tpls_with_reminders = 0;
    foreach ($templates as $tpl) {
      if ($tpl['reminder_count'] > 0) {
        $tpls_with_reminders++;
      }
    }

    $this->output()->writeln("\n<info>Summary</info>");
    $this->output()->writeln(sprintf('  Templates: %d total, %d with reminders', count($templates), $tpls_with_reminders));
    $this->output()->writeln(sprintf('  Courses: %d total — %d linked, %d missing template id, %d broken link',
      count($courses), $with_tpl, $missing_tpl, $broken_tpl));
  }

  protected function writeSnapshot(array $templates, array $courses, ?string $output_path): string {
    if ($output_path === NULL) {
      $project_root = realpath(DRUPAL_ROOT . '/..');
      $dir = $project_root . '/scripts/data';
      $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
      $output_path = $dir . '/civicrm-template-audit-' . date('Ymd-His') . '.json';
    }

    $payload = [
      'generated_at' => gmdate('c'),
      'templates' => array_values($templates),
      'courses' => array_values($courses),
    ];
    file_put_contents($output_path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $output_path;
  }

  protected function trim(string $s, int $max): string {
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
  }

}
