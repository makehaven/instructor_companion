<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * The steps between "staff invited you" and "ready to teach".
 *
 * Agreed with the education manager 2026-10-19: an invited instructor sets
 * up their public page (photo + bio), reads the Instructor Guidebook,
 * signs the agreement, then does exactly what a new member does for building
 * access — the member orientation video and quiz, then an in-person Site
 * Orientation booked through the normal scheduling page, where the card is
 * handed over. One list, shown on /instructor/setup, on the dashboard until
 * it is done, and to staff on the Education console.
 *
 * Every step is read from data the site already keeps, except two that have
 * no other home: reading the guide and choosing a password (user.data).
 */
class InstructorSetupChecklist {

  public const USER_DATA_MODULE = 'instructor_companion';
  public const GUIDE_ACK = 'startup_guide_ack';
  public const PASSWORD_SET = 'password_set';

  public const STEP_PASSWORD = 'password';
  public const STEP_PROFILE = 'profile';
  public const STEP_GUIDE = 'guide';
  public const STEP_AGREEMENT = 'agreement';
  public const STEP_ORIENTATION = 'orientation';
  public const STEP_SITE = 'site_orientation';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected UserDataInterface $userData,
    protected KeyValueFactoryInterface $keyValueFactory,
    protected InstructorApprovalGate $approvalGate,
    protected InstructorDoorAccess $doorAccess,
    protected TimeInterface $time,
    protected AliasManagerInterface $aliasManager,
    protected ?Connection $database = NULL,
  ) {}

  /**
   * The instructor's next class that has not started: id, title, start.
   *
   * Setup has a real deadline — the first class — and the list says so.
   *
   * @return array{id: int, title: string, start: string}|null
   *   start is CiviCRM's local 'Y-m-d H:i:s'.
   */
  public function nextClass(int $uid): ?array {
    $db = $this->database ?? \Drupal::database();
    $q = $db->select('civicrm_event', 'e');
    $q->fields('e', ['id', 'title', 'start_date']);
    $q->innerJoin('civicrm_event__field_civi_event_instructor', 'i', 'e.id = i.entity_id AND i.deleted = 0');
    $q->condition('i.field_civi_event_instructor_target_id', $uid);
    // civicrm_event.start_date is local time.
    $q->condition('e.start_date', date('Y-m-d H:i:s', $this->time->getRequestTime()), '>=');
    $q->condition('e.is_active', 1);
    $q->condition('e.is_template', 0);
    $q->orderBy('e.start_date');
    $q->range(0, 1);
    $row = $q->execute()->fetchAssoc();
    return $row ? ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'start' => (string) $row['start_date']] : NULL;
  }

  /**
   * The Guidebook node, when one is published at the handbook link.
   *
   * That is the "Instructor handbook" link the dashboard already carries
   * (instructor_handbook_url), so the guide is whatever page staff put at
   * that address.
   */
  public function guideNode(): ?NodeInterface {
    $node = $this->nodeAtGuideLink();
    return $node && $node->isPublished() ? $node : NULL;
  }

  /**
   * Whether the Guidebook page exists but is unpublished for review.
   *
   * The education manager asked (2026-10-28) that instructors see "coming
   * soon" rather than nothing until they have approved the text.
   */
  public function guidePending(): bool {
    $node = $this->nodeAtGuideLink();
    return $node !== NULL && !$node->isPublished();
  }

  /**
   * The id of the Guidebook page while it is unpublished, else 0.
   */
  public function pendingGuideId(): int {
    $node = $this->nodeAtGuideLink();
    return $node && !$node->isPublished() ? (int) $node->id() : 0;
  }

  /**
   * The node at the handbook link, published or not.
   */
  protected function nodeAtGuideLink(): ?NodeInterface {
    $path = trim((string) $this->configFactory->get('instructor_companion.settings')->get('instructor_handbook_url'));
    if ($path === '' || !str_starts_with($path, '/')) {
      return NULL;
    }
    $internal = $this->aliasManager->getPathByAlias($path);
    if (!preg_match('#^/node/(\d+)$#', $internal, $m)) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load((int) $m[1]);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * When the user confirmed reading the guide: ['at' => ts, 'vid' => rev].
   */
  public function guideAcknowledgement(int $uid): ?array {
    $ack = $this->userData->get(self::USER_DATA_MODULE, $uid, self::GUIDE_ACK);
    return is_array($ack) && !empty($ack['at']) ? $ack : NULL;
  }

  /**
   * Records that the user has read the current version of the guide.
   */
  public function acknowledgeGuide(int $uid): void {
    $node = $this->guideNode();
    $this->userData->set(self::USER_DATA_MODULE, $uid, self::GUIDE_ACK, [
      'at' => $this->time->getRequestTime(),
      'vid' => $node ? (int) $node->getRevisionId() : 0,
    ]);
  }

  /**
   * Whether the agreement should wait for the guide.
   */
  public function guideRequiredFirst(int $uid): bool {
    return $this->guideNode() !== NULL && $this->guideAcknowledgement($uid) === NULL;
  }

  /**
   * Records that the user chose their own password.
   */
  public function markPasswordSet(int $uid): void {
    $this->userData->set(self::USER_DATA_MODULE, $uid, self::PASSWORD_SET, $this->time->getRequestTime());
  }

  /**
   * The checklist for one person, in the order they should do it.
   *
   * @param \Drupal\user\UserInterface $user
   *   The instructor.
   * @param string|null $pass_reset_token
   *   The session token set when the invite link signed them in. With it the
   *   account form lets them choose a password without knowing the current
   *   one (an invite-made account has a random one nobody knows).
   *
   * @return array<string, array{title: string, help: string, done: bool, pending?: bool, action?: string, url: ?\Drupal\Core\Url, locked: string}>
   *   Steps keyed by STEP_* constant.
   */
  public function steps(UserInterface $user, ?string $pass_reset_token = NULL): array {
    $uid = (int) $user->id();
    $steps = [];

    // Only accounts the invite created have no password the person knows.
    $invite = $this->keyValueFactory->get(InstructorInviteManager::COLLECTION)->get($uid);
    if (!empty($invite['created'])) {
      $steps[self::STEP_PASSWORD] = [
        'title' => 'Choose a password',
        'help' => 'Your invite link signed you in this time. Choose a password so you can sign in again later.',
        'action' => 'Choose password',
        'done' => (bool) $this->userData->get(self::USER_DATA_MODULE, $uid, self::PASSWORD_SET),
        'url' => $pass_reset_token
          ? Url::fromRoute('entity.user.edit_form', ['user' => $uid], ['query' => ['pass-reset-token' => $pass_reset_token]])
          : Url::fromRoute('user.pass'),
        'locked' => '',
      ];
    }

    $profile = $this->approvalGate->loadInstructorProfile($uid);
    $missing = $this->missingProfileParts($profile);
    // JR 2026-10-28: the photo is encouraged, not required — the bio alone
    // finishes the step. A member photo is used when they add none
    // (instructor_companion_profile_presave), so say which applies.
    $no_bio = in_array('a bio', $missing, TRUE);
    $no_photo = in_array('a photo', $missing, TRUE);
    $member_photo = $no_photo && $this->memberPhotoFid($uid) !== NULL;
    if ($no_bio) {
      $help = 'Your instructor page is what people read before they sign up for your class. Add a few sentences about yourself.';
    }
    else {
      $help = 'Your instructor page has a bio.';
    }
    if ($no_photo) {
      $help .= $member_photo
        ? ' We will show your member photo; add a different one if you like.'
        : ' A photo is optional but helps people decide to sign up.';
    }
    $steps[self::STEP_PROFILE] = [
      'title' => 'Add your bio and a photo',
      'help' => $help,
      'done' => !$no_bio,
      'action' => $no_bio ? 'Add your bio' : 'Add a photo',
      'url' => Url::fromUserInput('/user/' . $uid . '/instructor'),
      'locked' => '',
    ];

    $guide = $this->guideNode();
    if ($guide) {
      $ack = $this->guideAcknowledgement($uid);
      $steps[self::STEP_GUIDE] = [
        'title' => 'Read the Instructor Guidebook',
        'help' => $ack
          ? 'You confirmed reading it on ' . date('M j, Y', (int) $ack['at']) . '.'
          : 'How classes run here: getting in, the room, supplies, class day, attendance, getting paid, and what to do in an emergency. Confirm at the bottom of the page.',
        'done' => $ack !== NULL,
        'action' => 'Read it',
        'url' => $guide->toUrl(),
        'locked' => '',
      ];
    }
    elseif ($this->guidePending()) {
      // Nothing to do yet, so it does not hold setup open.
      $steps[self::STEP_GUIDE] = [
        'title' => 'Instructor Guidebook: coming soon',
        'help' => 'Our education team is finishing it. It will appear here when it is ready.',
        'done' => TRUE,
        'pending' => TRUE,
        'url' => NULL,
        'locked' => '',
      ];
    }

    $signed = $this->approvalGate->hasSignedAgreement($uid);
    $steps[self::STEP_AGREEMENT] = [
      'title' => 'Sign the instructor agreement',
      'help' => $signed ? 'Signed.' : 'About five minutes to read. Type your full legal name to sign.',
      'done' => $signed,
      'action' => 'Sign',
      'url' => Url::fromRoute('entity.webform.canonical', ['webform' => 'webform_5220']),
      'locked' => !$signed && $this->guideRequiredFirst($uid) ? 'Read the Guidebook first.' : '',
    ];

    $door = $this->doorAccess->loadDoorBadgeRequest($uid);
    $door_active = $door && $door->hasField('field_badge_status') && $door->get('field_badge_status')->value === 'active';
    $steps[self::STEP_ORIENTATION] = [
      'title' => 'Watch the member orientation video and pass the quiz',
      'help' => $door_active
        ? 'Done. Your door badge is on your account.'
        : 'The same video and quiz every member does, so you know what your students have been told about safety and the space. Passing the quiz puts the door badge on your account.',
      'done' => $door_active,
      'action' => 'Watch the video',
      'url' => Url::fromUserInput('/video'),
      'locked' => '',
    ];

    // Instructors from before invites (no invite record) who already hold an
    // active Door badge got their access the old way; asking them to book an
    // orientation now would be noise on every dashboard visit.
    $has_card = ($user->hasField('field_card_serial_number') && !$user->get('field_card_serial_number')->isEmpty())
      || (!$invite && $door_active);
    $steps[self::STEP_SITE] = [
      'title' => 'Book your in-person Site Orientation',
      'help' => $has_card
        ? 'Done. Your access card is on file.'
        : 'A short walkthrough of the building and safety, booked like any member\'s. You pick up your access card there.',
      'done' => $has_card,
      'action' => 'Book a time',
      'url' => Url::fromUserInput('/schedule'),
      'locked' => '',
    ];

    return $steps;
  }

  /**
   * Whether every step is done.
   */
  public static function isComplete(array $steps): bool {
    foreach ($steps as $step) {
      if (empty($step['done'])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Progress for staff tables, such as "3 of 6".
   */
  public static function progress(array $steps): string {
    $done = count(array_filter($steps, fn($s) => !empty($s['done'])));
    return $done . ' of ' . count($steps);
  }

  /**
   * Invited people whose setup is not finished, newest invite first.
   *
   * @return array<int, array>
   *   Invite records keyed by uid, each with 'user' and 'steps'.
   */
  public function inProgress(): array {
    $records = $this->keyValueFactory->get(InstructorInviteManager::COLLECTION)->getAll();
    if (!$records) {
      return [];
    }
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple(array_keys($records));
    $out = [];
    foreach ($records as $uid => $record) {
      $user = $users[$uid] ?? NULL;
      if (!$user instanceof UserInterface || !$user->isActive()) {
        continue;
      }
      $steps = $this->steps($user);
      if (self::isComplete($steps)) {
        continue;
      }
      $record['user'] = $user;
      $record['steps'] = $steps;
      $out[(int) $uid] = $record;
    }
    uasort($out, static fn(array $a, array $b): int => ($b['last_sent'] ?? 0) <=> ($a['last_sent'] ?? 0));
    return $out;
  }

  /**
   * The member (main profile) photo's file id, or NULL.
   */
  public function memberPhotoFid(int $uid): ?int {
    if (!$this->entityTypeManager->hasDefinition('profile')) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('profile');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('type', 'main')
      ->exists('field_member_photo')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $main = $storage->load(reset($ids));
    $fid = $main ? (int) $main->get('field_member_photo')->target_id : 0;
    return $fid > 0 ? $fid : NULL;
  }

  /**
   * Which of 'a photo' / 'a bio' the instructor profile still lacks.
   */
  protected function missingProfileParts($profile): array {
    $missing = [];
    if (!$profile || !$profile->hasField('field_instructor_photo') || $profile->get('field_instructor_photo')->isEmpty()) {
      $missing[] = 'a photo';
    }
    $bio = $profile && $profile->hasField('field_instructor_bio')
      ? trim(str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags((string) $profile->get('field_instructor_bio')->value), ENT_QUOTES | ENT_HTML5, 'UTF-8')))
      : '';
    if ($bio === '') {
      $missing[] = 'a bio';
    }
    return $missing;
  }

}
