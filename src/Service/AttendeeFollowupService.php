<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The one email after the survey: what this person should do next.
 *
 * CiviCRM's type-level "Thanks for Attending!" (24 h after the class) asks
 * for the survey and offers the $5 code; that stays. This runs a week later,
 * after the class's LAST session, and sends at most one thing per attendee:
 *
 *  - someone with no member-class account gets the join / tour offer. In the
 *    twelve months to 2026-09-22, 4.9% of first-time non-member attendees
 *    joined, median 24 days later, with nothing asking them to.
 *  - a member whose badge_request for the class's badge is still *pending*
 *    gets "your badge is waiting". Members of public badge classes held the
 *    badge only 31% of the time; the pending ones are one step from active.
 *  - everyone else gets nothing. Instructors and hosts are never in the
 *    audience (attendee role only).
 *
 * Once per participant, one join offer per contact per 60 days (people take
 * two classes in a month), seeded on first run so history is not mailed.
 */
class AttendeeFollowupService {

  public const STATE_KEY = 'instructor_companion.attendee_followup_sent';
  public const MAIL_JOIN = 'attendee_join_offer';
  public const MAIL_BADGE = 'attendee_badge_nudge';
  public const DEFAULT_DELAY_DAYS = 7;
  public const DEFAULT_EVENT_TYPES = [6, 16, 8];
  public const DEFAULT_JOIN_URL = '/join-makehaven';
  public const DEFAULT_TOUR_URL = '/visit';

  /**
   * How many days past the due moment a class stays eligible.
   */
  public const WINDOW_DAYS = 3;

  /**
   * Days before the same contact can get a second join offer.
   */
  public const JOIN_REPEAT_DAYS = 60;

  /**
   * Days a sent record is kept.
   */
  protected const PRUNE_DAYS = 120;

  /**
   * CiviCRM participant role value for Attendee.
   */
  protected const ROLE_ATTENDEE = '1';

  /**
   * Participant statuses that mean "was in the class".
   */
  public const COUNTED_STATUSES = [1, 2, 14, 15];

  /**
   * Roles that make an account something other than a prospect.
   */
  public const NON_PROSPECT_ROLES = [
    'member', 'member_pending_approval', 'instructor', 'manager', 'administrator',
    'event_management', 'facilitator', 'data', 'special', 'content_editor', 'librarian',
  ];

  public function __construct(
    protected Connection $database,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected SessionSchedule $sessions,
    protected RequestStack $requestStack,
    protected AliasManagerInterface $aliasManager,
  ) {}

  /**
   * Switched on (default on).
   */
  public function isEnabled(): bool {
    return (bool) ($this->configFactory->get('instructor_companion.settings')->get('followup_enabled') ?? TRUE);
  }

  /**
   * Days after the last session.
   */
  public function delayDays(): int {
    $d = (int) ($this->configFactory->get('instructor_companion.settings')->get('followup_delay_days') ?? 0);
    return $d > 0 ? $d : self::DEFAULT_DELAY_DAYS;
  }

  /**
   * Event types whose attendees get the follow-up.
   */
  public function eventTypes(): array {
    $configured = $this->configFactory->get('instructor_companion.settings')->get('followup_event_types');
    if ($configured === NULL) {
      return self::DEFAULT_EVENT_TYPES;
    }
    return array_values(array_filter(array_map('intval', (array) $configured)));
  }

  /**
   * Which email, if any, one attendee should get. Pure; unit tested.
   *
   * @param bool $is_prospect
   *   No account, or an account with no member/staff/instructor role.
   * @param bool $has_pending_badge
   *   A pending badge_request for one of the class's badges.
   * @param bool $join_offered_recently
   *   The contact got a join offer inside JOIN_REPEAT_DAYS.
   *
   * @return string|null
   *   'join', 'badge' or NULL.
   */
  public static function decide(bool $is_prospect, bool $has_pending_badge, bool $join_offered_recently): ?string {
    if ($is_prospect) {
      return $join_offered_recently ? NULL : 'join';
    }
    return $has_pending_badge ? 'badge' : NULL;
  }

  /**
   * The due window at local $now. Pure; unit tested.
   *
   * Classes whose last session ended between (now - delay - WINDOW) and
   * (now - delay).
   *
   * @return array{0:string,1:string}
   *   Local 'Y-m-d H:i:s' bounds.
   */
  public static function dueWindow(int $now, int $delay_days): array {
    $hi = $now - $delay_days * 86400;
    $lo = $hi - self::WINDOW_DAYS * 86400;
    return [date('Y-m-d H:i:s', $lo), date('Y-m-d H:i:s', $hi)];
  }

  /**
   * Cron entry point. Returns the number of emails sent.
   */
  public function run(): int {
    if (!$this->isEnabled()) {
      return 0;
    }
    foreach (['civicrm_event', 'civicrm_participant', 'civicrm_uf_match'] as $table) {
      if (!$this->database->schema()->tableExists($table)) {
        return 0;
      }
    }
    $now = $this->time->getRequestTime();
    $sent = $this->prune((array) $this->state->get(self::STATE_KEY, []), $now);
    $due = $this->dueClasses($now);

    if (empty($sent['_seeded'])) {
      foreach ($due as $event_id => $row) {
        $sent['e' . $event_id] = ['t' => $now, 'sent' => 0, 'seeded' => TRUE];
      }
      $sent['_seeded'] = $now;
      $this->state->set(self::STATE_KEY, $sent);
      $this->logger->notice('Attendee follow-up seeded: @n class(es) already past the due moment recorded without sending.', ['@n' => count($due)]);
      return 0;
    }

    $total = 0;
    foreach ($due as $event_id => $row) {
      if (isset($sent['e' . $event_id])) {
        continue;
      }
      // Claim the class first so a failure part-way cannot re-send.
      $sent['e' . $event_id] = ['t' => $now, 'sent' => 0];
      $this->state->set(self::STATE_KEY, $sent);
      $count = 0;
      foreach ($this->plan($event_id, $sent, $now) as $p) {
        if ($p['kind'] === NULL || isset($sent['p' . $p['participant_id']])) {
          continue;
        }
        if ($this->email($p, $row)) {
          $count++;
          $sent['p' . $p['participant_id']] = ['t' => $now, 'kind' => $p['kind']];
          if ($p['kind'] === 'join') {
            $sent['c' . $p['contact_id']] = $now;
          }
          $this->state->set(self::STATE_KEY, $sent);
        }
      }
      $sent['e' . $event_id]['sent'] = $count;
      $this->state->set(self::STATE_KEY, $sent);
      $total += $count;
      $this->logger->notice('Attendee follow-up for event @e "@t": @n email(s) sent.', [
        '@e' => $event_id,
        '@t' => $row['title'],
        '@n' => $count,
      ]);
    }
    return $total;
  }

  /**
   * Classes whose last session ended inside the due window.
   *
   * @return array<int, array{id:int,title:string,type:int,ended:string}>
   *   Keyed by event id.
   */
  public function dueClasses(int $now): array {
    [$lo, $hi] = self::dueWindow($now, $this->delayDays());
    $types = $this->eventTypes();
    $rows = $this->sessions->endedBetween($lo, $hi, function ($q) use ($types) {
      $q->addField('e', 'title', 'title');
      $q->addField('e', 'event_type_id', 'type');
      $q->condition('e.is_active', 1);
      $q->condition('e.is_template', 0);
      if ($types) {
        $q->condition('e.event_type_id', $types, 'IN');
      }
    });
    $out = [];
    foreach ($rows as $event_id => $r) {
      $out[(int) $event_id] = [
        'id' => (int) $event_id,
        'title' => (string) ($r['title'] ?? ''),
        'type' => (int) ($r['type'] ?? 0),
        'ended' => (string) ($r['ended'] ?? ''),
      ];
    }
    return $out;
  }

  /**
   * What each counted attendee of one class would get.
   *
   * @return array<int, array>
   *   participant_id, contact_id, uid, email, first_name, kind ('join' |
   *   'badge' | NULL), reason, badge (name, url) when kind is 'badge'.
   */
  public function plan(int $event_id, ?array $sent = NULL, ?int $now = NULL): array {
    $now = $now ?? $this->time->getRequestTime();
    $sent = $sent ?? (array) $this->state->get(self::STATE_KEY, []);
    $people = $this->audience($event_id);
    if (!$people) {
      return [];
    }
    $uids = array_values(array_filter(array_column($people, 'uid')));
    $roles = $this->rolesByUid($uids);
    $badges = $this->eventBadges($event_id);
    $pending = $badges && $uids ? $this->pendingBadges($uids, array_keys($badges)) : [];

    foreach ($people as &$p) {
      $uid = $p['uid'];
      $is_prospect = $uid === NULL || !array_intersect($roles[$uid] ?? [], self::NON_PROSPECT_ROLES);
      $has_pending = $uid !== NULL && isset($pending[$uid]);
      $recent = isset($sent['c' . $p['contact_id']]) && ($now - (int) $sent['c' . $p['contact_id']]) < self::JOIN_REPEAT_DAYS * 86400;
      $p['kind'] = self::decide($is_prospect, $has_pending, $recent);
      $p['reason'] = $is_prospect
        ? ($recent ? 'prospect, offered recently' : 'prospect')
        : ($has_pending ? 'member, badge pending' : ($badges ? 'member, no pending badge' : 'member'));
      if ($p['kind'] === 'badge') {
        $tid = $pending[$uid];
        $p['badge'] = ['tid' => $tid, 'name' => $badges[$tid], 'url' => $this->badgeUrl($tid)];
      }
    }
    unset($p);
    return $people;
  }

  /**
   * Counted attendees with a deliverable email, plus their Drupal uid.
   */
  protected function audience(int $event_id): array {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_contact', 'c', 'c.id = p.contact_id');
    $q->innerJoin('civicrm_email', 'em', 'em.contact_id = c.id AND em.is_primary = 1');
    $q->leftJoin('civicrm_uf_match', 'uf', 'uf.contact_id = c.id');
    $q->addField('p', 'id', 'participant_id');
    $q->addField('p', 'contact_id', 'contact_id');
    $q->addField('uf', 'uf_id', 'uid');
    $q->addField('em', 'email', 'email');
    $q->addField('c', 'first_name', 'first_name');
    $q->addField('c', 'display_name', 'display_name');
    $q->condition('p.event_id', $event_id)
      ->condition('p.is_test', 0)
      ->condition('p.status_id', self::COUNTED_STATUSES, 'IN')
      ->condition('c.is_deleted', 0)
      ->condition('c.is_deceased', 0)
      ->condition('c.do_not_email', 0)
      ->condition('c.is_opt_out', 0)
      ->condition('em.on_hold', 0)
      ->condition('em.email', '', '<>');
    $role = self::ROLE_ATTENDEE;
    $or = $q->orConditionGroup()
      ->condition('p.role_id', $role)
      ->condition('p.role_id', '%' . $this->database->escapeLike("\x01" . $role . "\x01") . '%', 'LIKE')
      ->condition('p.role_id', $this->database->escapeLike($role . "\x01") . '%', 'LIKE')
      ->condition('p.role_id', '%' . $this->database->escapeLike("\x01" . $role), 'LIKE');
    $q->condition($or)->orderBy('p.id');
    $out = [];
    foreach ($q->execute() as $r) {
      $first = trim((string) $r->first_name);
      $out[] = [
        'participant_id' => (int) $r->participant_id,
        'contact_id' => (int) $r->contact_id,
        'uid' => $r->uid !== NULL ? (int) $r->uid : NULL,
        'email' => (string) $r->email,
        'first_name' => $first !== '' ? $first : (string) $r->display_name,
      ];
    }
    return $out;
  }

  /**
   * Uid => role ids.
   */
  protected function rolesByUid(array $uids): array {
    if (!$uids || !$this->database->schema()->tableExists('user__roles')) {
      return [];
    }
    $out = [];
    $rows = $this->database->select('user__roles', 'r')
      ->fields('r', ['entity_id', 'roles_target_id'])
      ->condition('r.entity_id', $uids, 'IN')
      ->execute();
    foreach ($rows as $r) {
      $out[(int) $r->entity_id][] = (string) $r->roles_target_id;
    }
    return $out;
  }

  /**
   * Badge term id => name for the badges a class awards.
   */
  protected function eventBadges(int $event_id): array {
    if (!$this->database->schema()->tableExists('civicrm_event__field_civi_event_badges')) {
      return [];
    }
    $q = $this->database->select('civicrm_event__field_civi_event_badges', 'b');
    $q->innerJoin('taxonomy_term_field_data', 't', 't.tid = b.field_civi_event_badges_target_id');
    $q->fields('t', ['tid', 'name'])
      ->condition('b.entity_id', $event_id)
      ->condition('b.deleted', 0);
    return $q->execute()->fetchAllKeyed(0, 1);
  }

  /**
   * Uid => first badge tid with a *pending* request among the class's badges.
   */
  protected function pendingBadges(array $uids, array $badge_tids): array {
    foreach (['node__field_member_to_badge', 'node__field_badge_requested', 'node__field_badge_status'] as $table) {
      if (!$this->database->schema()->tableExists($table)) {
        return [];
      }
    }
    $q = $this->database->select('node__field_member_to_badge', 'm');
    $q->innerJoin('node__field_badge_requested', 'b', 'b.entity_id = m.entity_id');
    $q->innerJoin('node__field_badge_status', 's', 's.entity_id = m.entity_id');
    $q->addField('m', 'field_member_to_badge_target_id', 'uid');
    $q->addField('b', 'field_badge_requested_target_id', 'tid');
    $q->addField('s', 'field_badge_status_value', 'status');
    $q->condition('m.field_member_to_badge_target_id', $uids, 'IN')
      ->condition('b.field_badge_requested_target_id', $badge_tids, 'IN')
      ->condition('s.field_badge_status_value', ['pending', 'active'], 'IN')
      ->orderBy('m.entity_id', 'DESC');
    $status = [];
    foreach ($q->execute() as $r) {
      $status[(int) $r->uid][(int) $r->tid][] = (string) $r->status;
    }
    $out = [];
    foreach ($status as $uid => $per_badge) {
      foreach ($per_badge as $tid => $states) {
        // An active record anywhere wins; only a purely pending badge nudges.
        if (!in_array('active', $states, TRUE) && in_array('pending', $states, TRUE)) {
          $out[$uid] = $tid;
          break;
        }
      }
    }
    return $out;
  }

  /**
   * Absolute URL of a badge page.
   */
  protected function badgeUrl(int $tid): string {
    return $this->baseUrl() . $this->aliasManager->getAliasByPath('/taxonomy/term/' . $tid);
  }

  /**
   * The site's absolute base, even from drush cron where there is no host.
   */
  public function baseUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    $host = $request ? (string) $request->getSchemeAndHttpHost() : '';
    return preg_match('#^https?://[^/]+\.[a-z]+#i', $host) ? rtrim($host, '/') : 'https://www.makehaven.org';
  }

  /**
   * Makes a configured path absolute.
   */
  protected function absolute(string $path_or_url): string {
    if (preg_match('#^https?://#i', $path_or_url)) {
      return $path_or_url;
    }
    return $this->baseUrl() . '/' . ltrim($path_or_url, '/');
  }

  /**
   * Subject and body for one attendee. Pure; unit tested.
   *
   * @return array{subject:string, body:string}
   *   Subject and plain-text body.
   */
  public static function compose(array $p, array $event, array $texts, array $urls): array {
    $replacements = [
      '[first_name]' => $p['first_name'],
      '[event_title]' => $event['title'],
      '[join_url]' => $urls['join'],
      '[tour_url]' => $urls['tour'],
      '[badge_name]' => $p['badge']['name'] ?? '',
      '[badge_url]' => $p['badge']['url'] ?? '',
    ];
    if ($p['kind'] === 'badge') {
      $subject = $texts['badge_subject'] ?: self::defaultBadgeSubject();
      $body = $texts['badge_body'] ?: self::defaultBadgeBody();
    }
    else {
      $subject = $texts['join_subject'] ?: self::defaultJoinSubject();
      $body = $texts['join_body'] ?: self::defaultJoinBody();
    }
    return ['subject' => strtr($subject, $replacements), 'body' => strtr($body, $replacements)];
  }

  /**
   * Sends one email.
   */
  protected function email(array $p, array $event): bool {
    $config = $this->configFactory->get('instructor_companion.settings');
    $texts = [
      'join_subject' => (string) $config->get('followup_join_subject'),
      'join_body' => (string) $config->get('followup_join_body'),
      'badge_subject' => (string) $config->get('followup_badge_subject'),
      'badge_body' => (string) $config->get('followup_badge_body'),
    ];
    $urls = [
      'join' => $this->absolute((string) ($config->get('followup_join_url') ?: self::DEFAULT_JOIN_URL)),
      'tour' => $this->absolute((string) ($config->get('followup_tour_url') ?: self::DEFAULT_TOUR_URL)),
    ];
    $message = self::compose($p, $event, $texts, $urls);
    $result = $this->mailManager->mail(
      'instructor_companion',
      $p['kind'] === 'badge' ? self::MAIL_BADGE : self::MAIL_JOIN,
      $p['email'],
      \Drupal::languageManager()->getDefaultLanguage()->getId(),
      ['subject' => $message['subject'], 'body' => $message['body']],
      NULL,
      TRUE
    );
    if (empty($result['result'])) {
      $this->logger->warning('Attendee follow-up (@k) to @mail for event @e did not send.', [
        '@k' => $p['kind'],
        '@mail' => $p['email'],
        '@e' => $event['id'],
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Default subject of the join / tour offer.
   */
  public static function defaultJoinSubject(): string {
    return "Enjoyed [event_title]? Here's what's next at MakeHaven";
  }

  /**
   * Default body of the join / tour offer.
   */
  public static function defaultJoinBody(): string {
    return "Hi [first_name],\n\n"
      . "It was great to have you at [event_title]. If you'd like to keep making, there are two easy next steps.\n\n"
      . "Take a free tour. See the whole shop — wood, metal, textiles, electronics, laser cutters and more — and ask anything:\n[tour_url]\n\n"
      . "Become a member. Members get the tools, the community and the member rate on classes like the one you just took:\n[join_url]\n\n"
      . "Questions? Reply to this email and a real person will answer.\n\n"
      . "The MakeHaven Team\nwww.makehaven.org";
  }

  /**
   * Default subject of the badge nudge.
   */
  public static function defaultBadgeSubject(): string {
    return 'Your [badge_name] badge is waiting';
  }

  /**
   * Default body of the badge nudge.
   */
  public static function defaultBadgeBody(): string {
    return "Hi [first_name],\n\n"
      . "You took [event_title], which covers the [badge_name] badge. The badge is still marked pending on your account, which means the last step has not been recorded yet.\n\n"
      . "What to do: the badge page shows what is left — usually the short online quiz, or the instructor's sign-off from the class:\n[badge_url]\n\n"
      . "If you finished the class and the quiz, reply to this email or write education@makehaven.org and we will sort it out.\n\n"
      . "Once the badge is active you can use the tool during open hours and book it on the calendar.\n\n"
      . "The MakeHaven Team\nwww.makehaven.org";
  }

  /**
   * Drops records older than PRUNE_DAYS.
   */
  protected function prune(array $sent, int $now): array {
    $cutoff = $now - self::PRUNE_DAYS * 86400;
    foreach ($sent as $k => $v) {
      if ($k === '_seeded') {
        continue;
      }
      $t = is_array($v) ? (int) ($v['t'] ?? 0) : (int) $v;
      if ($t < $cutoff) {
        unset($sent[$k]);
      }
    }
    return $sent;
  }

}
