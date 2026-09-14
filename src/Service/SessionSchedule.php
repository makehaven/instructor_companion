<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The one answer to "when does this class meet, and when is it over?".
 *
 * A multi-session workshop (stained glass over six Saturdays, rug tufting over
 * two) is ONE CiviCRM event whose start/end cover only the first meeting, so
 * the calendar and listings show a single date rather than a five-week smear.
 * The other meetings live in the multi-value Drupal field
 * `field_civi_event_sessions` ("Sessions", all meetings including the first).
 *
 * Every consumer that used to key off `end_date` — the at-start attendance
 * prompt, the post-class wrap-up reminder, the staff close-out backlog, the
 * dashboard's past/upcoming split, the attendee evaluation — asks this service
 * instead. Before it existed, all of them fired after the FIRST session:
 * Ash's six-week stained glass class got "a few wrap-up tasks left" on day 3
 * and its students were asked to rate a class that was one-sixth done.
 *
 * Conventions: civicrm_event.start_date / end_date are local wall-clock
 * strings ('Y-m-d H:i:s'); the Drupal datetime field stores UTC ISO
 * ('Y-m-d\TH:i:s'). Everything this service returns is LOCAL 'Y-m-d H:i:s',
 * which is what the existing cron windows compare against.
 */
class SessionSchedule {

  /**
   * Drupal datetime field storage format (UTC).
   */
  public const STORAGE_FORMAT = 'Y-m-d\TH:i:s';

  /**
   * CiviCRM event date format (site-local).
   */
  public const LOCAL_FORMAT = 'Y-m-d H:i:s';

  /**
   * Floor for a session's length when the event has no usable end_date.
   */
  public const MIN_DURATION_SECONDS = 15 * 60;

  /**
   * Per-request cache of computed schedules, keyed by event id.
   */
  protected array $cache = [];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The site timezone, as CiviCRM and the cron windows understand "local".
   */
  public static function timezone(): \DateTimeZone {
    return new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
  }

  /**
   * Converts a stored (UTC ISO) value to a local 'Y-m-d H:i:s' string.
   */
  public static function storageToLocal(string $stored, ?\DateTimeZone $tz = NULL): ?string {
    $stored = trim($stored);
    if ($stored === '') {
      return NULL;
    }
    $dt = \DateTimeImmutable::createFromFormat(self::STORAGE_FORMAT, $stored, new \DateTimeZone('UTC'))
      ?: \DateTimeImmutable::createFromFormat(self::LOCAL_FORMAT, $stored, new \DateTimeZone('UTC'));
    if (!$dt) {
      return NULL;
    }
    return $dt->setTimezone($tz ?? self::timezone())->format(self::LOCAL_FORMAT);
  }

  /**
   * Converts a local 'Y-m-d H:i:s' string to the field's UTC storage value.
   */
  public static function localToStorage(string $local, ?\DateTimeZone $tz = NULL): ?string {
    $local = trim($local);
    if ($local === '') {
      return NULL;
    }
    $dt = \DateTimeImmutable::createFromFormat(self::LOCAL_FORMAT, $local, $tz ?? self::timezone())
      ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', $local, $tz ?? self::timezone());
    if (!$dt) {
      return NULL;
    }
    return $dt->setTimezone(new \DateTimeZone('UTC'))->format(self::STORAGE_FORMAT);
  }

  /**
   * Length of one session in seconds, from the event's own start/end.
   *
   * Pure. Falls back to the floor when end is missing or not after start.
   */
  public static function sessionDuration(?string $start, ?string $end): int {
    $s = $start ? strtotime($start) : FALSE;
    $e = $end ? strtotime($end) : FALSE;
    if ($s === FALSE || $e === FALSE || $e <= $s) {
      return self::MIN_DURATION_SECONDS;
    }
    return max(self::MIN_DURATION_SECONDS, $e - $s);
  }

  /**
   * Builds the ordered session list from raw inputs.
   *
   * Pure — unit tested. Given the event's own start/end plus the local
   * session starts from the field (any order, possibly empty, possibly
   * missing the first meeting), returns one row per session:
   * ['start' => local, 'end' => local, 'index' => 0-based, 'count' => n].
   *
   * The event's start_date is always the first session, whether or not the
   * editor remembered to include it in the field: that is what the calendar
   * shows, and the attendance prompt has always fired off it.
   */
  public static function buildSchedule(?string $start_date, ?string $end_date, array $session_starts): array {
    $duration = self::sessionDuration($start_date, $end_date);
    $starts = [];
    if ($start_date) {
      $starts[] = substr($start_date, 0, 19);
    }
    foreach ($session_starts as $s) {
      $s = trim((string) $s);
      if ($s !== '' && strtotime($s) !== FALSE) {
        $starts[] = date(self::LOCAL_FORMAT, strtotime($s));
      }
    }
    // Same minute counts as the same session (the field stores seconds the
    // widget never shows).
    $unique = [];
    foreach ($starts as $s) {
      $unique[substr($s, 0, 16)] = $s;
    }
    $starts = array_values($unique);
    sort($starts);

    $rows = [];
    $n = count($starts);
    foreach ($starts as $i => $s) {
      $rows[] = [
        'start' => $s,
        'end' => date(self::LOCAL_FORMAT, strtotime($s) + $duration),
        'index' => $i,
        'count' => $n,
      ];
    }
    return $rows;
  }

  /**
   * Generates evenly spaced session starts from the first one.
   *
   * Pure. Returns local 'Y-m-d H:i:s' strings including the first. Wall-clock
   * time is preserved across a DST change (a 6 pm class stays at 6 pm).
   */
  public static function generate(string $first_start, int $count, int $interval_days, ?\DateTimeZone $tz = NULL): array {
    $count = max(1, $count);
    $interval_days = max(1, $interval_days);
    $tz = $tz ?? self::timezone();
    $first = \DateTimeImmutable::createFromFormat(self::LOCAL_FORMAT, substr($first_start, 0, 19), $tz)
      ?: new \DateTimeImmutable($first_start, $tz);
    $out = [];
    for ($i = 0; $i < $count; $i++) {
      $out[] = $first->modify('+' . ($i * $interval_days) . ' days')->format(self::LOCAL_FORMAT);
    }
    return $out;
  }

  /**
   * Moves every session by the same calendar offset as the first one.
   *
   * Pure. Used when an event is rescheduled: if the start moves from
   * Sept 12 to Sept 19, the six Saturdays move with it. Works in whole
   * calendar days plus the time-of-day change so DST cannot skew later
   * sessions by an hour.
   */
  public static function shift(array $session_starts, string $old_start, string $new_start, ?\DateTimeZone $tz = NULL): array {
    $tz = $tz ?? self::timezone();
    $old = \DateTimeImmutable::createFromFormat(self::LOCAL_FORMAT, substr($old_start, 0, 19), $tz);
    $new = \DateTimeImmutable::createFromFormat(self::LOCAL_FORMAT, substr($new_start, 0, 19), $tz);
    if (!$old || !$new) {
      return $session_starts;
    }
    $days = (int) $old->setTime(0, 0)->diff($new->setTime(0, 0))->format('%r%a');
    $time = $new->format('H:i:s');
    $out = [];
    foreach ($session_starts as $s) {
      $dt = \DateTimeImmutable::createFromFormat(self::LOCAL_FORMAT, substr((string) $s, 0, 19), $tz);
      if (!$dt) {
        continue;
      }
      $moved = $dt->modify(($days >= 0 ? '+' : '') . $days . ' days');
      [$h, $m, $sec] = array_map('intval', explode(':', $time));
      $out[] = $moved->setTime($h, $m, $sec)->format(self::LOCAL_FORMAT);
    }
    return $out;
  }

  /**
   * Whether the field-held session starts include the event start.
   *
   * Pure. Compared to the minute, like buildSchedule().
   */
  public static function includesStart(array $session_starts, ?string $start_date): bool {
    if (!$start_date) {
      return TRUE;
    }
    $key = substr($start_date, 0, 16);
    foreach ($session_starts as $s) {
      if (substr((string) $s, 0, 16) === $key) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Full schedule for an event: one row per session, ordered.
   *
   * @return array[]
   *   See buildSchedule(). A single-session event returns one row. Unknown
   *   event returns [].
   */
  public function getSchedule(int $event_id): array {
    if (isset($this->cache[$event_id])) {
      return $this->cache[$event_id];
    }
    $row = $this->database->select('civicrm_event', 'e')
      ->fields('e', ['start_date', 'end_date'])
      ->condition('e.id', $event_id)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return $this->cache[$event_id] = [];
    }
    return $this->cache[$event_id] = self::buildSchedule(
      $row['start_date'] ?: NULL,
      $row['end_date'] ?: NULL,
      $this->fieldSessionStarts([$event_id])[$event_id] ?? []
    );
  }

  /**
   * Local session starts held in the field, for many events at once.
   *
   * @return array<int, string[]>
   *   event_id => local 'Y-m-d H:i:s' starts (unsorted, as stored).
   */
  public function fieldSessionStarts(array $event_ids): array {
    $event_ids = array_values(array_filter(array_map('intval', $event_ids)));
    if (!$event_ids || !$this->database->schema()->tableExists('civicrm_event__field_civi_event_sessions')) {
      return [];
    }
    $q = $this->database->select('civicrm_event__field_civi_event_sessions', 's');
    $q->fields('s', ['entity_id', 'field_civi_event_sessions_value']);
    $q->condition('s.entity_id', $event_ids, 'IN');
    $q->condition('s.deleted', 0);
    $out = [];
    $tz = self::timezone();
    foreach ($q->execute() as $r) {
      $local = self::storageToLocal((string) $r->field_civi_event_sessions_value, $tz);
      if ($local) {
        $out[(int) $r->entity_id][] = $local;
      }
    }
    return $out;
  }

  /**
   * Whether the event meets more than once.
   */
  public function isMultiSession(int $event_id): bool {
    return count($this->getSchedule($event_id)) > 1;
  }

  /**
   * Number of sessions (1 for an ordinary class).
   */
  public function count(int $event_id): int {
    return max(1, count($this->getSchedule($event_id)));
  }

  /**
   * Local time the class is over: the end of its last session.
   *
   * NULL for an unknown event.
   */
  public function effectiveEnd(int $event_id): ?string {
    $schedule = $this->getSchedule($event_id);
    if (!$schedule) {
      return NULL;
    }
    return end($schedule)['end'];
  }

  /**
   * Whether the class still has a session ahead of it at local $now.
   */
  public function hasSessionAfter(int $event_id, string $now): bool {
    foreach ($this->getSchedule($event_id) as $s) {
      if ($s['start'] > $now) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The session that best matches "now" for taking attendance.
   *
   * The session that started most recently, or the first one when the class
   * has not begun. Returns the schedule row.
   */
  public function currentSession(int $event_id, string $now): ?array {
    $schedule = $this->getSchedule($event_id);
    if (!$schedule) {
      return NULL;
    }
    $current = $schedule[0];
    foreach ($schedule as $s) {
      if ($s['start'] <= $now) {
        $current = $s;
      }
    }
    return $current;
  }

  /**
   * Finds a schedule row by its local start (to the minute).
   */
  public function findSession(int $event_id, string $start): ?array {
    $key = substr($start, 0, 16);
    foreach ($this->getSchedule($event_id) as $s) {
      if (substr($s['start'], 0, 16) === $key) {
        return $s;
      }
    }
    return NULL;
  }

  /**
   * Next session on or after local $now, if any.
   */
  public function nextSession(int $event_id, string $now): ?array {
    foreach ($this->getSchedule($event_id) as $s) {
      if ($s['start'] >= $now) {
        return $s;
      }
    }
    return NULL;
  }

  /**
   * Events whose class is OVER inside a local window — the cron primitive.
   *
   * Replaces `COALESCE(e.end_date, e.start_date) BETWEEN lo AND hi`. Two
   * candidate sets are unioned, then each is checked precisely in PHP:
   * events whose own end falls in the window (but which may have a later
   * session and so are NOT over), and events with a field session starting
   * shortly before the window (whose last session end may land in it).
   *
   * @param string $lo
   *   Local 'Y-m-d H:i:s' lower bound (inclusive).
   * @param string $hi
   *   Local 'Y-m-d H:i:s' upper bound (inclusive).
   * @param callable|null $scope
   *   Optional callback receiving the SelectInterface on civicrm_event alias
   *   'e' to add the caller's conditions (types, is_active, instructor join).
   *   It must add its own fields; this method only adds e.id.
   *
   * @return array<int, array>
   *   event_id => the row the scoped query produced (with 'event_id' and
   *   'ended' = local effective end added).
   */
  public function endedBetween(string $lo, string $hi, ?callable $scope = NULL): array {
    $rows = [];

    // Candidate set A: own end in the window.
    $q = $this->database->select('civicrm_event', 'e');
    $q->addField('e', 'id', 'event_id');
    $q->where('COALESCE(e.end_date, e.start_date) BETWEEN :lo AND :hi', [':lo' => $lo, ':hi' => $hi]);
    if ($scope) {
      $scope($q);
    }
    foreach ($q->execute() as $r) {
      $rows[(int) $r->event_id] = (array) $r;
    }

    // Candidate set B: a field session starts up to a day before the window
    // opens (so its end, at most a day later, can land inside it).
    if ($this->database->schema()->tableExists('civicrm_event__field_civi_event_sessions')) {
      $tz = self::timezone();
      $lo_utc = self::localToStorage(date(self::LOCAL_FORMAT, strtotime($lo) - 86400), $tz);
      $hi_utc = self::localToStorage($hi, $tz);
      $q = $this->database->select('civicrm_event', 'e');
      $q->addField('e', 'id', 'event_id');
      $q->innerJoin('civicrm_event__field_civi_event_sessions', 's', 'e.id = s.entity_id AND s.deleted = 0');
      $q->where('s.field_civi_event_sessions_value BETWEEN :lo AND :hi', [':lo' => $lo_utc, ':hi' => $hi_utc]);
      $q->distinct();
      if ($scope) {
        $scope($q);
      }
      foreach ($q->execute() as $r) {
        $rows[(int) $r->event_id] = (array) $r;
      }
    }

    // Precise check.
    foreach ($rows as $event_id => $row) {
      $ended = $this->effectiveEnd($event_id);
      if ($ended === NULL || $ended < $lo || $ended > $hi) {
        unset($rows[$event_id]);
        continue;
      }
      $rows[$event_id]['ended'] = $ended;
    }
    uasort($rows, static fn(array $a, array $b): int => strcmp($b['ended'], $a['ended']));
    return $rows;
  }

  /**
   * Sessions that START inside a local window — the attendance-prompt primitive.
   *
   * @return array[]
   *   Rows ['event_id' => int, 'session' => schedule row] plus whatever the
   *   scoped query selected, one per (event, session).
   */
  public function sessionsStartingBetween(string $lo, string $hi, ?callable $scope = NULL): array {
    $candidates = [];

    $q = $this->database->select('civicrm_event', 'e');
    $q->addField('e', 'id', 'event_id');
    $q->where('e.start_date BETWEEN :lo AND :hi', [':lo' => $lo, ':hi' => $hi]);
    if ($scope) {
      $scope($q);
    }
    foreach ($q->execute() as $r) {
      $candidates[(int) $r->event_id] = (array) $r;
    }

    if ($this->database->schema()->tableExists('civicrm_event__field_civi_event_sessions')) {
      $tz = self::timezone();
      $q = $this->database->select('civicrm_event', 'e');
      $q->addField('e', 'id', 'event_id');
      $q->innerJoin('civicrm_event__field_civi_event_sessions', 's', 'e.id = s.entity_id AND s.deleted = 0');
      $q->where('s.field_civi_event_sessions_value BETWEEN :lo AND :hi', [
        ':lo' => self::localToStorage($lo, $tz),
        ':hi' => self::localToStorage($hi, $tz),
      ]);
      $q->distinct();
      if ($scope) {
        $scope($q);
      }
      foreach ($q->execute() as $r) {
        $candidates[(int) $r->event_id] = (array) $r;
      }
    }

    $out = [];
    foreach ($candidates as $event_id => $row) {
      foreach ($this->getSchedule($event_id) as $session) {
        if ($session['start'] >= $lo && $session['start'] <= $hi) {
          $out[] = $row + ['session' => $session];
        }
      }
    }
    return $out;
  }

  /**
   * Writes the session list to the event's field (local starts in, UTC out).
   *
   * Goes through the entity API so caches and hooks behave. The event's own
   * start_date is always kept as the first session. Returns the stored local
   * starts.
   */
  public function setSessions(int $event_id, array $local_starts): array {
    $event = $this->entityTypeManager->getStorage('civicrm_event')->load($event_id);
    if (!$event || !$event->hasField('field_civi_event_sessions')) {
      return [];
    }
    // The entity exposes CiviCRM's local dates in UTC storage form.
    $tz = self::timezone();
    $schedule = self::buildSchedule(
      self::storageToLocal((string) $event->get('start_date')->value, $tz),
      self::storageToLocal((string) $event->get('end_date')->value, $tz),
      $local_starts
    );
    $values = [];
    foreach ($schedule as $s) {
      $values[] = ['value' => self::localToStorage($s['start'], $tz)];
    }
    // A single date is just the event itself; store nothing.
    $event->set('field_civi_event_sessions', count($values) > 1 ? $values : []);
    $event->save();
    unset($this->cache[$event_id]);
    return array_column($schedule, 'start');
  }

  /**
   * Human label like "Sat, Sep 12 · 5:00 pm" for a local start.
   */
  public static function label(string $local_start, string $format = 'D, M j · g:ia'): string {
    $ts = strtotime($local_start);
    return $ts ? date($format, $ts) : $local_start;
  }

  /**
   * Summary line for a schedule, like "6 sessions, Sep 12 – Oct 17".
   */
  public static function summary(array $schedule): string {
    $n = count($schedule);
    if ($n <= 1) {
      return '';
    }
    $first = strtotime($schedule[0]['start']);
    $last = strtotime(end($schedule)['start']);
    $same_year = date('Y', $first) === date('Y', $last);
    return sprintf('%d sessions, %s – %s', $n,
      date($same_year ? 'M j' : 'M j, Y', $first),
      date('M j, Y', $last));
  }

  /**
   * Clears the per-request cache (after writes elsewhere).
   */
  public function resetCache(?int $event_id = NULL): void {
    if ($event_id === NULL) {
      $this->cache = [];
    }
    else {
      unset($this->cache[$event_id]);
    }
  }

}
