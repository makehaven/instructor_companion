<?php

namespace Drupal\instructor_companion\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Backfill course node body / summary from richest past event.
 *
 * Course nodes hold the rich, course-specific content the new
 * "Schedule New Instance" workflow needs to feed back into cloned events.
 * Today most course bodies are sparse (54 of 207 are empty), but their
 * past child CiviCRM events have full summary + description text. This
 * command pulls the most recent active event's content into the course.
 *
 * Pairs with ScheduleInstanceController, which copies course body into the
 * new event after CRM_Event_BAO_Event::copy() runs.
 *
 * Idempotent: by default skips courses that already have non-trivial body
 * content. --overwrite forces a rewrite.
 */
class TemplateBackfillCommands extends DrushCommands {

  /**
   * Treat bodies shorter than this (after stripping tags) as empty.
   */
  protected const MIN_POPULATED_LENGTH = 50;

  protected EntityTypeManagerInterface $entityTypeManager;
  protected Connection $database;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * Backfill course body / summary from the richest past event.
   *
   * @command instructor-companion:backfill-course-content
   * @aliases ic-bcc
   * @option execute Write changes (default is dry-run, prints proposed mapping).
   * @option overwrite Replace existing course body (default skips populated courses).
   * @option only Comma-separated nids to limit to a sample.
   * @usage instructor-companion:backfill-course-content --only=41420
   *   Dry-run for one course.
   * @usage instructor-companion:backfill-course-content --execute
   *   Backfill every course missing body content from its richest past event.
   */
  public function backfill(array $options = ['execute' => FALSE, 'overwrite' => FALSE, 'only' => NULL]) {
    $only = $options['only'] ? array_map('intval', explode(',', $options['only'])) : NULL;

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course');
    if ($only) {
      $query->condition('nid', $only, 'IN');
    }
    $nids = $query->execute();

    $stats = [
      'skip_populated' => 0,
      'skip_no_source' => 0,
      'would_write' => 0,
      'would_overwrite' => 0,
    ];
    $rows = [];

    foreach ($storage->loadMultiple($nids) as $node) {
      $current_body = $node->hasField('body') && !$node->get('body')->isEmpty()
        ? trim(strip_tags($node->get('body')->value ?? ''))
        : '';
      $is_populated = mb_strlen($current_body) >= static::MIN_POPULATED_LENGTH;

      $source = $this->findRichestEvent((int) $node->id());

      if (!$source) {
        $stats['skip_no_source']++;
        $rows[] = [$node->id(), $this->trim($node->label(), 40), 'no past event', '—', '—'];
        continue;
      }

      if ($is_populated && !$options['overwrite']) {
        $stats['skip_populated']++;
        $rows[] = [
          $node->id(),
          $this->trim($node->label(), 40),
          'event #' . $source['id'],
          mb_strlen($current_body) . ' chars',
          'skip (already populated)',
        ];
        continue;
      }

      $action = $is_populated ? 'overwrite' : 'write';
      if ($is_populated) {
        $stats['would_overwrite']++;
      }
      else {
        $stats['would_write']++;
      }

      $proposed_summary_len = mb_strlen(strip_tags($source['summary']));
      $proposed_body_len = mb_strlen(strip_tags($source['description']));
      $rows[] = [
        $node->id(),
        $this->trim($node->label(), 40),
        'event #' . $source['id'],
        $is_populated ? mb_strlen($current_body) . ' chars' : 'empty',
        $action . ' (' . $proposed_summary_len . '+' . $proposed_body_len . ' chars)',
      ];

      if ($options['execute'] && (!$is_populated || $options['overwrite'])) {
        $node->set('body', [
          'value' => $source['description'],
          'summary' => $source['summary'],
          'format' => 'full_html',
        ]);
        $node->save();
      }
    }

    $this->io()->table(
      ['NID', 'Course', 'Source', 'Current body', 'Action'],
      $rows
    );

    $this->output()->writeln(sprintf(
      "\n%s: %d would write, %d would overwrite, %d skipped (already populated), %d skipped (no past event)",
      $options['execute'] ? '<info>Wrote</info>' : '<comment>Dry-run</comment>',
      $stats['would_write'],
      $stats['would_overwrite'],
      $stats['skip_populated'],
      $stats['skip_no_source']
    ));

    if (!$options['execute']) {
      $this->output()->writeln('<comment>Re-run with --execute to write. Add --overwrite to replace populated bodies.</comment>');
    }
  }

  /**
   * Find the most recent active event under this course with content.
   *
   * Ordered by start_date DESC — the freshest event has the most up-to-date
   * description (instructor changes, scheduling notes, etc.). Falls back to
   * NULL if no active non-template event with description exists.
   *
   * @return array{id:int, summary:string, description:string}|null
   */
  protected function findRichestEvent(int $course_nid): ?array {
    $row = $this->database->query(
      "SELECT e.id, e.summary, e.description, e.start_date
       FROM civicrm_event e
       JOIN civicrm_event__field_parent_course pc ON pc.entity_id = e.id
       WHERE pc.field_parent_course_target_id = :nid
         AND e.is_active = 1
         AND e.is_template = 0
         AND e.description IS NOT NULL
         AND CHAR_LENGTH(e.description) > 0
       ORDER BY e.start_date DESC
       LIMIT 1",
      [':nid' => $course_nid]
    )->fetchAssoc();

    if (!$row) {
      return NULL;
    }

    return [
      'id' => (int) $row['id'],
      'summary' => (string) ($row['summary'] ?? ''),
      'description' => (string) ($row['description'] ?? ''),
    ];
  }

  protected function trim(string $s, int $max): string {
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
  }

}
