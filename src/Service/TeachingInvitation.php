<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\user\UserDataInterface;

/**
 * Decides who is offered teaching on the member hub, and what it says.
 *
 * JR 2026-11-05: make the option available once someone has shown skill,
 * without pushing. So this is one card on a page the member already visits,
 * never an email, and "Not for me" ends it. Of 76 people who started teaching
 * since 2024, 44 held five or more badges before their first class. The other
 * main group came with outside expertise, so this gates only the card; the
 * public pages stay open to everyone.
 */
class TeachingInvitation {

  /**
   * Member Resources, the page members land on after logging in.
   */
  public const HUB_NID = 619;

  public const SHOWN = 'teach_invitation_shown';
  public const OPENED = 'teach_invitation_opened';
  public const DISMISSED = 'teach_invitation_dismissed';

  /**
   * Badges that show someone came in the door, not that they built a skill.
   */
  private const NOT_SKILL_PATTERNS = ['%Door%', '%Orientation%', '%Safety%'];

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected UserDataInterface $userData,
    protected TimeInterface $time,
    protected WorkshopInsightsService $insights,
  ) {}

  /**
   * Whether the qualifying signals are enough. Roles are checked by the caller.
   */
  public static function qualifies(bool $ticked_teach, bool $facilitator_or_issuer, int $skill_badges, int $min_badges, int $months_here, int $min_months): bool {
    return $ticked_teach || $facilitator_or_issuer
      || ($skill_badges >= $min_badges && $months_here >= $min_months);
  }

  /**
   * The class to mention, if any: most people waiting, among badges held.
   *
   * @param array<int, array{title:string, badges:int[], waiting:int}> $courses
   *   Candidate courses.
   * @param array<int, bool> $held
   *   Badge term ids the member holds, as keys.
   *
   * @return string|null
   *   The course title, or NULL to leave the card general.
   */
  public static function topicFor(array $courses, array $held): ?string {
    $best = NULL;
    $most = 0;
    foreach ($courses as $course) {
      if ($course['waiting'] > $most && array_intersect_key(array_flip($course['badges']), $held)) {
        $most = $course['waiting'];
        $best = $course['title'];
      }
    }
    return $best;
  }

  /**
   * Stores when a member first did something with the card.
   */
  public function record(int $uid, string $what): void {
    if ($uid && !$this->userData->get('instructor_companion', $uid, $what)) {
      $this->userData->set('instructor_companion', $uid, $what, $this->time->getRequestTime());
    }
    // The hub is cached per member for an hour; "Not for me" must take effect
    // on the page they are sent back to.
    if ($what === self::DISMISSED) {
      Cache::invalidateTags([self::cacheTag($uid)]);
    }
  }

  /**
   * Cache tag on a member's hub, cleared when they dismiss the card.
   */
  public static function cacheTag(int $uid): string {
    return 'instructor_companion:teach_invitation:' . $uid;
  }

  /**
   * Whether this member should see the card right now.
   */
  public function shouldShow(int $uid, array $roles): bool {
    $config = $this->configFactory->get('instructor_companion.settings');
    if (!$uid || !$config->get('teach_invitation_enabled')
      || !in_array('member', $roles, TRUE) || in_array('instructor', $roles, TRUE)
      || $this->userData->get('instructor_companion', $uid, self::DISMISSED)
      || $this->hasTaught($uid)) {
      return FALSE;
    }
    $created = (int) $this->database->query("SELECT MIN(created) FROM {profile} WHERE uid = :uid AND type = 'main'", [':uid' => $uid])->fetchField();
    $months = $created ? (int) floor(($this->time->getRequestTime() - $created) / (30.44 * 86400)) : 0;
    return self::qualifies(
      $this->tickedTeach($uid),
      in_array('facilitator', $roles, TRUE) || $this->isBadgeIssuer($uid),
      $this->skillBadgeCount($uid),
      (int) ($config->get('teach_invitation_min_badges') ?? 5),
      $months,
      (int) ($config->get('teach_invitation_min_months') ?? 6),
    );
  }

  /**
   * A class with people waiting that this member holds a badge for.
   */
  public function topic(int $uid): ?string {
    $held = $this->activeBadgeTids($uid);
    if (!$held) {
      return NULL;
    }
    $courses = [];
    $rows = $this->database->query("
      SELECT n.nid, n.title, b.field_course_badges_target_id AS badge
      FROM {node_field_data} n
      INNER JOIN {node__field_course_badges} b ON b.entity_id = n.nid AND b.deleted = 0
      INNER JOIN {node__field_publicly_listed} l ON l.entity_id = n.nid AND l.field_publicly_listed_value = 1
      WHERE n.type = 'course' AND n.status = 1");
    foreach ($rows as $r) {
      $courses[(int) $r->nid]['title'] = (string) $r->title;
      $courses[(int) $r->nid]['badges'][] = (int) $r->badge;
    }
    foreach ($this->insights->teachingDemand(array_keys($courses)) as $nid => $d) {
      $courses[$nid]['waiting'] = $d['waiting'];
    }
    return self::topicFor($courses, $held);
  }

  /**
   * Badge term ids the member actively holds, as keys.
   *
   * @return array<int, bool>
   *   Term ids.
   */
  public function activeBadgeTids(int $uid): array {
    $tids = $this->database->query("
      SELECT DISTINCT r.field_badge_requested_target_id
      FROM {node__field_member_to_badge} m
      INNER JOIN {node__field_badge_requested} r ON r.entity_id = m.entity_id AND r.deleted = 0
      INNER JOIN {node__field_badge_status} s ON s.entity_id = m.entity_id AND s.deleted = 0 AND s.field_badge_status_value = 'active'
      WHERE m.field_member_to_badge_target_id = :uid AND m.deleted = 0", [':uid' => $uid])->fetchCol();
    return array_fill_keys(array_map('intval', $tids), TRUE);
  }

  /**
   * Active badges that show a skill, leaving out door and orientation ones.
   */
  protected function skillBadgeCount(int $uid): int {
    $held = array_keys($this->activeBadgeTids($uid));
    if (!$held) {
      return 0;
    }
    $q = $this->database->select('taxonomy_term_field_data', 't')->condition('t.tid', $held, 'IN');
    foreach (self::NOT_SKILL_PATTERNS as $pattern) {
      $q->condition('t.name', $pattern, 'NOT LIKE');
    }
    return (int) $q->countQuery()->execute()->fetchField();
  }

  /**
   * Whether the member ticked "available to teach" on their profile.
   */
  protected function tickedTeach(int $uid): bool {
    return (bool) $this->database->query("
      SELECT 1 FROM {profile} p
      INNER JOIN {profile__field_member_availability} a ON a.entity_id = p.profile_id AND a.deleted = 0
      WHERE p.uid = :uid AND p.type = 'main' AND a.field_member_availability_value IN ('teach_paid', 'teach_volunteer')", [':uid' => $uid])->fetchField();
  }

  /**
   * Whether the member can issue any badge.
   */
  protected function isBadgeIssuer(int $uid): bool {
    return (bool) $this->database->query('SELECT 1 FROM {taxonomy_term__field_badge_issuer} WHERE field_badge_issuer_target_id = :uid AND deleted = 0', [':uid' => $uid])->fetchField();
  }

  /**
   * Whether the member is named as instructor on any class.
   */
  protected function hasTaught(int $uid): bool {
    return (bool) $this->database->query('SELECT 1 FROM {civicrm_event__field_civi_event_instructor} WHERE field_civi_event_instructor_target_id = :uid AND deleted = 0', [':uid' => $uid])->fetchField();
  }

}
