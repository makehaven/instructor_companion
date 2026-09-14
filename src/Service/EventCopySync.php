<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the Drupal side of a CiviCRM event in step with CiviCRM's own moves.
 *
 * Two moments where CiviCRM acts and Drupal would otherwise be left behind:
 *
 *  - Copy. Staff make almost every event by copying the previous one in
 *    CiviCRM ("Copy Event"), which duplicates CiviCRM's columns and nothing
 *    else. Every Drupal-side field — course, instructor, badges, image,
 *    area of interest, and the session list — had to be re-entered by hand,
 *    which is why the session list was filled in exactly once in two years.
 *    copyDrupalFields() carries them over (only into empty fields).
 *
 *  - Reschedule. When the start date moves, a session list that began on
 *    the old start moves with it by the same number of days, so a six-week
 *    class pushed back a week does not have session 2 land before session 1.
 *    Handled here for CiviCRM-side edits and in the Drupal edit form's
 *    validation for Drupal-side ones (the form path sets a static flag so
 *    this does not shift twice).
 */
class EventCopySync {

  /**
   * Drupal fields carried from the original event to its copy.
   *
   * Computed fields (registered / remaining / full / marketing status) and
   * the low-capacity notice flag are deliberately left out.
   */
  public const COPIED_FIELDS = [
    'field_parent_course',
    'field_civi_event_instructor',
    'field_civi_event_badges',
    'field_civi_event_media_image',
    'field_civi_event_image',
    'field_civi_event_area_interest',
    'field_civi_event_tags',
    'field_event_skill_level',
    'field_civi_event_age_requirement',
    'field_civi_event_staff_contact',
    'field_civi_event_documents',
    'field_civi_event_sessions',
  ];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SessionSchedule $sessions,
    protected MessengerInterface $messenger,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Copies the Drupal-side fields of one event onto another.
   *
   * @return string[]
   *   Labels of the fields that were copied.
   */
  public function copyDrupalFields(int $source_id, int $target_id): array {
    $storage = $this->entityTypeManager->getStorage('civicrm_event');
    $storage->resetCache([$source_id, $target_id]);
    $source = $storage->load($source_id);
    $target = $storage->load($target_id);
    if (!$source || !$target) {
      $this->logger->warning('Event copy sync: could not load source @s or target @t.', [
        '@s' => $source_id,
        '@t' => $target_id,
      ]);
      return [];
    }
    $copied = [];
    foreach (self::COPIED_FIELDS as $field) {
      if (!$source->hasField($field) || !$target->hasField($field)) {
        continue;
      }
      if ($source->get($field)->isEmpty() || !$target->get($field)->isEmpty()) {
        continue;
      }
      $target->set($field, $source->get($field)->getValue());
      $copied[] = (string) $target->get($field)->getFieldDefinition()->getLabel();
    }
    // CiviCRM copies the description text but the Drupal-side text format
    // defaults to plain text, which shows the HTML as tags on the edit
    // form. Match what ScheduleInstanceController writes.
    foreach (['description', 'summary'] as $text_field) {
      if ($target->hasField($text_field) && !$target->get($text_field)->isEmpty()) {
        $item = $target->get($text_field)->first();
        if ($item && property_exists($item, 'format') && ($item->format ?? '') !== 'full_html' && $item->getFieldDefinition()->getType() === 'text_long') {
          $target->set($text_field, ['value' => $item->value, 'format' => 'full_html']);
        }
      }
    }
    if ($copied) {
      $target->save();
      $this->sessions->resetCache($target_id);
      $this->logger->info('Event @t copied from @s: carried over @f.', [
        '@t' => $target_id,
        '@s' => $source_id,
        '@f' => implode(', ', $copied),
      ]);
      $this->messenger->addStatus(t('Carried over from the original event: @fields. Check the sessions and the date before publishing.', ['@fields' => implode(', ', $copied)]));
    }
    return $copied;
  }

  /**
   * Moves the session list to follow a rescheduled start date.
   *
   * Writes the field table directly: this runs inside CiviCRM's post hook,
   * which for a Drupal-form save is itself inside the entity save, where a
   * nested entity save of the same event is not an option.
   *
   * @return string[]
   *   The new local session starts, or [] when nothing needed moving.
   */
  public function rescheduleSessions(int $event_id, string $old_start, string $new_start): array {
    $old = date(SessionSchedule::LOCAL_FORMAT, strtotime($old_start));
    $new = date(SessionSchedule::LOCAL_FORMAT, strtotime($new_start));
    if (substr($old, 0, 16) === substr($new, 0, 16)) {
      return [];
    }
    $starts = $this->sessions->fieldSessionStarts([$event_id])[$event_id] ?? [];
    if (count($starts) < 2 || !SessionSchedule::includesStart($starts, $old)) {
      return [];
    }
    sort($starts);
    $shifted = SessionSchedule::shift($starts, $old, $new);
    $this->writeSessionRows($event_id, $shifted);
    $this->logger->info('Event @e rescheduled @o → @n; @c sessions moved with it.', [
      '@e' => $event_id,
      '@o' => $old,
      '@n' => $new,
      '@c' => count($shifted),
    ]);
    $this->messenger->addStatus(t('The @c sessions moved with the new start date (now @first – @last).', [
      '@c' => count($shifted),
      '@first' => SessionSchedule::label($shifted[0], 'M j'),
      '@last' => SessionSchedule::label(end($shifted), 'M j'),
    ]));
    return $shifted;
  }

  /**
   * Replaces the field rows for an event with the given local starts.
   */
  public function writeSessionRows(int $event_id, array $local_starts): void {
    $table = 'civicrm_event__field_civi_event_sessions';
    if (!$this->database->schema()->tableExists($table)) {
      return;
    }
    $bundle = (string) $this->database->select($table, 's')
      ->fields('s', ['bundle'])
      ->condition('s.entity_id', $event_id)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($bundle === '') {
      $type_id = (int) $this->database->select('civicrm_event', 'e')->fields('e', ['event_type_id'])->condition('e.id', $event_id)->execute()->fetchField();
      $bundle = $this->bundleForType($type_id);
    }
    $tz = SessionSchedule::timezone();
    $tx = $this->database->startTransaction();
    try {
      $this->database->delete($table)->condition('entity_id', $event_id)->execute();
      $delta = 0;
      foreach ($local_starts as $start) {
        $stored = SessionSchedule::localToStorage($start, $tz);
        if (!$stored) {
          continue;
        }
        $this->database->insert($table)->fields([
          'bundle' => $bundle,
          'deleted' => 0,
          'entity_id' => $event_id,
          'revision_id' => $event_id,
          'langcode' => 'en',
          'delta' => $delta++,
          'field_civi_event_sessions_value' => $stored,
        ])->execute();
      }
    }
    catch (\Throwable $e) {
      $tx->rollBack();
      $this->logger->error('Could not write sessions for event @e: @m', ['@e' => $event_id, '@m' => $e->getMessage()]);
      return;
    }
    unset($tx);
    $this->entityTypeManager->getStorage('civicrm_event')->resetCache([$event_id]);
    Cache::invalidateTags(['civicrm_event:' . $event_id, 'civicrm_event_list']);
    $this->sessions->resetCache($event_id);
  }

  /**
   * The Drupal bundle name for a CiviCRM event type id.
   */
  protected function bundleForType(int $type_id): string {
    try {
      $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('civicrm_event');
      $label = (string) $this->database->query(
        "SELECT ov.label FROM civicrm_option_value ov JOIN civicrm_option_group og ON og.id = ov.option_group_id AND og.name = 'event_type' WHERE ov.value = :v",
        [':v' => $type_id]
      )->fetchField();
      foreach ($bundles as $machine => $info) {
        if ((string) ($info['label'] ?? '') === $label) {
          return $machine;
        }
      }
    }
    catch (\Throwable $e) {
      // Fall through.
    }
    return 'civicrm_event';
  }

}
