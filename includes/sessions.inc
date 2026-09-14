<?php

/**
 * @file
 * Multi-session workshops: CiviCRM hooks and the edit-form session helper.
 *
 * Loaded by instructor_companion.module. The rule everything here serves is
 * in \Drupal\instructor_companion\Service\SessionSchedule: a class that meets
 * several times is ONE event whose start/end are the first meeting; the other
 * meetings live in field_civi_event_sessions.
 */

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\instructor_companion\Service\SessionSchedule;

/**
 * Field holding the session list.
 */
const INSTRUCTOR_COMPANION_SESSIONS_FIELD = 'field_civi_event_sessions';

/**
 * Implements hook_ENTITY_TYPE_load() for civicrm_event.
 *
 * The civicrm_entity module treats every datetime field on its entities as a
 * CiviCRM local-time value and converts it to UTC on load — right for
 * CiviCRM's own columns, wrong for a Drupal field that already stores UTC.
 * Without this, the Sessions list reads four hours late, the widget shows
 * the wrong time, and every save of the event drifts it by another four
 * hours. Re-read the stored values so the entity carries the truth.
 *
 * @see \Drupal\civicrm_entity\CiviEntityStorage::initFieldValues()
 */
function instructor_companion_civicrm_event_load(array $entities) {
  $field = INSTRUCTOR_COMPANION_SESSIONS_FIELD;
  $ids = [];
  foreach ($entities as $entity) {
    if ($entity->hasField($field)) {
      $ids[] = (int) $entity->id();
    }
  }
  if (!$ids) {
    return;
  }
  $db = \Drupal::database();
  if (!$db->schema()->tableExists('civicrm_event__' . $field)) {
    return;
  }
  $rows = $db->select('civicrm_event__' . $field, 's')
    ->fields('s', ['entity_id', 'delta', $field . '_value'])
    ->condition('s.entity_id', $ids, 'IN')
    ->condition('s.deleted', 0)
    ->orderBy('s.entity_id')
    ->orderBy('s.delta')
    ->execute();
  $values = [];
  foreach ($rows as $r) {
    $values[(int) $r->entity_id][] = ['value' => $r->{$field . '_value'}];
  }
  foreach ($entities as $entity) {
    if (!$entity->hasField($field)) {
      continue;
    }
    $stored = $values[(int) $entity->id()] ?? [];
    if ($stored || !$entity->get($field)->isEmpty()) {
      $entity->get($field)->setValue($stored);
    }
  }
}

/**
 * Implements hook_civicrm_copy().
 *
 * Carries the Drupal-side fields (course, instructor, badges, image, session
 * list…) onto an event copied in CiviCRM. Runs after CiviCRM's commit when a
 * transaction is open, so the new row is visible to Drupal's connection.
 */
function instructor_companion_civicrm_copy($objectName, &$object, $original_id = NULL) {
  if ($objectName !== 'Event' || empty($object->id) || !$original_id) {
    return;
  }
  $new_id = (int) $object->id;
  $source_id = (int) $original_id;
  $runner = static function () use ($source_id, $new_id) {
    try {
      \Drupal::service('instructor_companion.event_copy_sync')->copyDrupalFields($source_id, $new_id);
    }
    catch (\Throwable $e) {
      \Drupal::logger('instructor_companion')->error('Event copy sync @s → @t failed: @m', [
        '@s' => $source_id,
        '@t' => $new_id,
        '@m' => $e->getMessage(),
      ]);
    }
  };
  if (class_exists('CRM_Core_Transaction') && \CRM_Core_Transaction::isActive()) {
    \CRM_Core_Transaction::addCallback(\CRM_Core_Transaction::PHASE_POST_COMMIT, $runner);
  }
  else {
    $runner();
  }
}

/**
 * Implements hook_civicrm_pre().
 *
 * Remembers an event's start before CiviCRM changes it.
 */
function instructor_companion_civicrm_pre($op, $objectName, $id, &$params) {
  if ($objectName !== 'Event' || $op !== 'edit' || !$id || !is_array($params) || empty($params['start_date'])) {
    return;
  }
  $old = \Drupal::database()->select('civicrm_event', 'e')
    ->fields('e', ['start_date'])
    ->condition('e.id', (int) $id)
    ->execute()
    ->fetchField();
  if ($old) {
    _instructor_companion_old_start((int) $id, (string) $old);
  }
}

/**
 * Implements hook_civicrm_post().
 *
 * When the start moved, moves a session list that began on the old start.
 */
function instructor_companion_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($objectName !== 'Event' || $op !== 'edit' || !$objectId) {
    return;
  }
  $old = _instructor_companion_old_start((int) $objectId);
  $new = is_object($objectRef) && !empty($objectRef->start_date) ? (string) $objectRef->start_date : NULL;
  if (!$old || !$new || strtotime($old) === FALSE || strtotime($new) === FALSE) {
    return;
  }
  // The Drupal edit form shifts its own submitted values; doing it here too
  // would move them twice.
  if (_instructor_companion_sessions_form_saving()) {
    return;
  }
  try {
    \Drupal::service('instructor_companion.event_copy_sync')->rescheduleSessions((int) $objectId, $old, $new);
  }
  catch (\Throwable $e) {
    \Drupal::logger('instructor_companion')->error('Session reschedule for event @e failed: @m', [
      '@e' => $objectId,
      '@m' => $e->getMessage(),
    ]);
  }
}

/**
 * Per-request store of pre-edit start dates, keyed by event id.
 */
function _instructor_companion_old_start(int $event_id, ?string $set = NULL): ?string {
  $store = &drupal_static(__FUNCTION__, []);
  if ($set !== NULL) {
    $store[$event_id] = $set;
  }
  return $store[$event_id] ?? NULL;
}

/**
 * Flag: the Drupal event form is mid-save (it handles its own shift).
 */
function _instructor_companion_sessions_form_saving(?bool $set = NULL): bool {
  $flag = &drupal_static(__FUNCTION__, FALSE);
  if ($set !== NULL) {
    $flag = $set;
  }
  return $flag;
}

/**
 * Adds the session helper to the CiviCRM event add/edit forms.
 *
 * Called from the module's form alters. Needs the Sessions widget on the
 * form display (config); does nothing when it is hidden.
 */
function instructor_companion_sessions_form_alter(array &$form, FormStateInterface $form_state): void {
  $field = INSTRUCTOR_COMPANION_SESSIONS_FIELD;
  if (!isset($form[$field])) {
    return;
  }
  $entity = $form_state->getFormObject()->getEntity();
  $form['#ic_original_start'] = $entity->isNew() ? NULL : (string) $entity->get('start_date')->value;

  // Defaults for the generator: the course's usual shape, else what is there.
  $count = 0;
  $interval = 7;
  if ($entity->hasField('field_parent_course') && !$entity->get('field_parent_course')->isEmpty()) {
    $course = $entity->get('field_parent_course')->entity;
    if ($course) {
      if ($course->hasField('field_session_count') && !$course->get('field_session_count')->isEmpty()) {
        $count = (int) $course->get('field_session_count')->value;
      }
      if ($course->hasField('field_session_interval_days') && !$course->get('field_session_interval_days')->isEmpty()) {
        $interval = max(1, (int) $course->get('field_session_interval_days')->value);
      }
    }
  }
  $existing = $entity->hasField($field) ? count($entity->get($field)) : 0;
  if ($existing > 1) {
    $count = $existing;
  }
  if ($count < 2) {
    $count = 2;
  }

  $weight = $form[$field]['#weight'] ?? 0;
  $form[$field]['#weight'] = $weight;
  $form[$field]['widget']['#description'] = t('Only for a class that meets more than once. List every meeting, including the first (which must match the start date above). The calendar keeps showing the first date; attendance, the wrap-up reminder and the attendee evaluation follow the last one.');

  $form['ic_sessions_helper'] = [
    '#type' => 'details',
    '#title' => t('Fill in sessions from a weekly pattern'),
    '#open' => $existing <= 1,
    '#weight' => $weight + 0.01,
    '#tree' => TRUE,
    'count' => [
      '#type' => 'number',
      '#title' => t('Number of sessions'),
      '#min' => 1,
      '#max' => 52,
      '#default_value' => $count,
      '#size' => 4,
    ],
    'interval' => [
      '#type' => 'number',
      '#title' => t('Days between sessions'),
      '#min' => 1,
      '#max' => 60,
      '#default_value' => $interval,
      '#size' => 4,
      '#description' => t('7 = weekly. Uses the start date above as session 1; adjust individual dates afterwards if a week is skipped.'),
    ],
    'fill' => [
      '#type' => 'submit',
      '#value' => t('Fill in sessions'),
      '#submit' => ['instructor_companion_sessions_fill_submit'],
      '#limit_validation_errors' => [['ic_sessions_helper'], ['start_date']],
      '#attributes' => ['class' => ['button--small']],
    ],
  ];

  $form['#validate'][] = 'instructor_companion_sessions_validate';
  foreach (['submit', 'publish'] as $button) {
    if (isset($form['actions'][$button]['#submit'])) {
      array_unshift($form['actions'][$button]['#submit'], 'instructor_companion_sessions_presubmit');
    }
  }
}

/**
 * Fills the widget from the weekly pattern and rebuilds the form.
 */
function instructor_companion_sessions_fill_submit(array &$form, FormStateInterface $form_state): void {
  $field = INSTRUCTOR_COMPANION_SESSIONS_FIELD;
  $input = $form_state->getUserInput();
  $start_raw = $input['start_date'][0]['value'] ?? NULL;
  $date = is_array($start_raw) ? trim((string) ($start_raw['date'] ?? '')) : '';
  $time = is_array($start_raw) ? trim((string) ($start_raw['time'] ?? '')) : '';
  if ($date === '') {
    \Drupal::messenger()->addError(t('Set the start date first; sessions are generated from it.'));
    $form_state->setRebuild();
    return;
  }
  if ($time === '') {
    $time = '00:00:00';
  }
  elseif (strlen($time) === 5) {
    $time .= ':00';
  }
  $first = $date . ' ' . $time;
  $count = max(1, (int) ($form_state->getValue(['ic_sessions_helper', 'count']) ?? 2));
  $interval = max(1, (int) ($form_state->getValue(['ic_sessions_helper', 'interval']) ?? 7));
  $starts = SessionSchedule::generate($first, $count, $interval);

  $values = [];
  foreach ($starts as $delta => $start) {
    $values[$delta] = ['value' => ['date' => substr($start, 0, 10), 'time' => substr($start, 11, 8)]];
  }
  $input[$field] = $values;
  $form_state->setUserInput($input);
  WidgetBase::setWidgetState($form['#parents'] ?? [], $field, $form_state, ['items_count' => count($values)]);
  \Drupal::messenger()->addStatus(t('@n sessions filled in, @first to @last. Adjust any date below, then save.', [
    '@n' => count($starts),
    '@first' => SessionSchedule::label($starts[0], 'D M j'),
    '@last' => SessionSchedule::label(end($starts), 'D M j'),
  ]));
  $form_state->setRebuild();
}

/**
 * Validates the session list and moves it with a rescheduled start.
 */
function instructor_companion_sessions_validate(array &$form, FormStateInterface $form_state): void {
  $field = INSTRUCTOR_COMPANION_SESSIONS_FIELD;
  $trigger = $form_state->getTriggeringElement();
  if (($trigger['#submit'][0] ?? '') === 'instructor_companion_sessions_fill_submit') {
    return;
  }
  $values = (array) $form_state->getValue($field, []);
  $tz = SessionSchedule::timezone();
  $starts = [];
  foreach ($values as $delta => $item) {
    if (!is_int($delta) || empty($item['value'])) {
      continue;
    }
    $v = $item['value'];
    $stored = $v instanceof
    DrupalDateTime ? $v->setTimezone(new \DateTimeZone('UTC'))->format(SessionSchedule::STORAGE_FORMAT)
      : (string) $v;
    $local = SessionSchedule::storageToLocal($stored, $tz);
    if ($local) {
      $starts[$delta] = $local;
    }
  }
  if (!$starts) {
    return;
  }

  $new_start_raw = $form_state->getValue(['start_date', 0, 'value']);
  $new_start = $new_start_raw instanceof
  DrupalDateTime ? $new_start_raw->setTimezone($tz)->format(SessionSchedule::LOCAL_FORMAT)
    : ($new_start_raw ? SessionSchedule::storageToLocal((string) $new_start_raw, $tz) : NULL);
  $old_start = !empty($form['#ic_original_start']) ? SessionSchedule::storageToLocal((string) $form['#ic_original_start'], $tz) : NULL;

  // Rescheduled: a list that began on the old start follows the new one.
  if ($old_start && $new_start && substr($old_start, 0, 16) !== substr($new_start, 0, 16)
    && SessionSchedule::includesStart($starts, $old_start)) {
    $shifted = SessionSchedule::shift(array_values($starts), $old_start, $new_start, $tz);
    $new_values = [];
    foreach ($shifted as $start) {
      $new_values[] = ['value' => SessionSchedule::localToStorage($start, $tz)];
    }
    $form_state->setValue($field, $new_values);
    $starts = $shifted;
    \Drupal::messenger()->addStatus(t('The @c sessions moved with the new start date (now @first – @last).', [
      '@c' => count($shifted),
      '@first' => SessionSchedule::label($shifted[0], 'M j'),
      '@last' => SessionSchedule::label(end($shifted), 'M j'),
    ]));
  }

  if ($new_start && !SessionSchedule::includesStart($starts, $new_start)) {
    $form_state->setErrorByName($field, t('The first session must be the class start (@start). Add it to the list, or use "Fill in sessions".', [
      '@start' => SessionSchedule::label($new_start),
    ]));
  }
}

/**
 * Marks the Drupal form save so the CiviCRM post hook does not shift twice.
 */
function instructor_companion_sessions_presubmit(array &$form, FormStateInterface $form_state): void {
  _instructor_companion_sessions_form_saving(TRUE);
}
