<?php

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Staff-sent invitations to sign the instructor agreement.
 *
 * This is the staff-initiated half of instructor onboarding: education staff
 * have already met the person and decided they will teach, and now need them
 * in the system. The invite creates the account if there is none, and emails a
 * signed link that logs the person in and lands them on the agreement. Signing
 * then does everything it already does for the self-serve path — instructor
 * profile, instructor role, pending door badge for non-members, staff
 * notification, dashboard.
 *
 * The link is the only way a person who has not spoken to staff reaches the
 * agreement, which is the ordering the 2026-08-13 rollback established. See
 * docs/ops/2026-08-13-instructor-pages-rollback.md in the site repo.
 *
 * Links are signed with the site hash salt plus the account's password hash
 * (mirroring core's one-time login links), so setting a password later
 * invalidates any outstanding invite. Core's own links were not reused because
 * they expire with user.settings:password_reset_timeout (24h on this site),
 * which is too short for an email a person may open days later.
 */
class InstructorInviteManager {

  /**
   * How long an invite link stays valid, in seconds.
   */
  public const TTL = 14 * 86400;

  /**
   * Key/value collection holding one record per invited user, keyed by uid.
   */
  public const COLLECTION = 'instructor_companion.invites';

  protected KeyValueStoreInterface $store;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    KeyValueFactoryInterface $keyValueFactory,
    protected InstructorApprovalGate $approvalGate,
    protected MailManagerInterface $mailManager,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {
    $this->store = $keyValueFactory->get(self::COLLECTION);
  }

  /**
   * Invites a person to sign the agreement, creating their account if needed.
   *
   * @param string $name
   *   Full name as staff typed it.
   * @param string $mail
   *   Email address.
   * @param \Drupal\Core\Session\AccountInterface $sender
   *   The staff member sending the invite.
   * @param string $note
   *   Optional personal note included in the email.
   *
   * @return array{user: \Drupal\user\UserInterface, created: bool, sent: bool}
   *   The account the invite went to, whether it was created just now, and
   *   whether the email was handed to the mail system.
   */
  public function invite(string $name, string $mail, AccountInterface $sender, string $note = ''): array {
    $mail = trim($mail);
    $name = trim($name);

    $user = $this->loadByMail($mail);
    $created = FALSE;
    if (!$user) {
      $user = $this->createAccount($name, $mail);
      $created = TRUE;
    }

    $now = $this->time->getRequestTime();
    $record = $this->store->get($user->id(), []);
    $record += [
      'invited_at' => $now,
      'invited_by' => (int) $sender->id(),
      'count' => 0,
    ];
    $record['name'] = $name !== '' ? $name : $user->getDisplayName();
    $record['mail'] = $mail;
    $record['note'] = $note;
    $record['last_sent'] = $now;
    $record['last_sent_by'] = (int) $sender->id();
    $record['count']++;
    $this->store->set($user->id(), $record);

    $sent = $this->sendMail($user, $sender, $note, $now);

    $this->logger->notice('Instructor agreement invite @action to @name <@mail> (uid @uid) by uid @by.', [
      '@action' => $record['count'] > 1 ? 'resent' : 'sent',
      '@name' => $record['name'],
      '@mail' => $mail,
      '@uid' => $user->id(),
      '@by' => $sender->id(),
    ]);

    return ['user' => $user, 'created' => $created, 'sent' => $sent];
  }

  /**
   * Resends the invite to an already-invited user.
   *
   * @return bool
   *   FALSE when there is no invite record to resend.
   */
  public function resend(UserInterface $user, AccountInterface $sender): bool {
    $record = $this->store->get($user->id());
    if (!$record) {
      return FALSE;
    }
    $this->invite($record['name'] ?? '', $user->getEmail() ?: ($record['mail'] ?? ''), $sender, $record['note'] ?? '');
    return TRUE;
  }

  /**
   * The signed invite URL for a user, valid for TTL seconds from $timestamp.
   */
  public function buildUrl(UserInterface $user, int $timestamp): Url {
    return Url::fromRoute('instructor_companion.invite_accept', [
      'user' => $user->id(),
      'timestamp' => $timestamp,
      'hash' => $this->hash($user, $timestamp),
    ], ['absolute' => TRUE]);
  }

  /**
   * Checks an invite link's parameters.
   *
   * @return string
   *   One of: 'valid', 'expired', 'invalid', 'signed' (the agreement is already
   *   on file, so there is nothing left to invite them to), 'blocked'.
   */
  public function validate(UserInterface $user, int $timestamp, string $hash): string {
    if (!$user->isActive()) {
      return 'blocked';
    }
    if ($timestamp > $this->time->getRequestTime()
      || !hash_equals($this->hash($user, $timestamp), $hash)) {
      return 'invalid';
    }
    if ($this->approvalGate->hasSignedAgreement((int) $user->id())) {
      return 'signed';
    }
    if (!self::isWithinTtl($timestamp, $this->time->getRequestTime())) {
      return 'expired';
    }
    return 'valid';
  }

  /**
   * Whether an invite issued at $issued is still live at $now.
   */
  public static function isWithinTtl(int $issued, int $now): bool {
    return $issued + self::TTL > $now;
  }

  /**
   * Records that the person followed their link.
   */
  public function markAccepted(UserInterface $user): void {
    $record = $this->store->get($user->id());
    if ($record) {
      $record['accepted_at'] = $this->time->getRequestTime();
      $this->store->set($user->id(), $record);
    }
  }

  /**
   * The invite record for a user, or NULL.
   */
  public function record(UserInterface $user): ?array {
    return $this->store->get($user->id()) ?: NULL;
  }

  /**
   * Invited people who have not yet signed, newest invite first.
   *
   * @return array<int, array>
   *   Records keyed by uid, each with a loaded 'user' entry.
   */
  public function pending(): array {
    $records = $this->store->getAll();
    if (!$records) {
      return [];
    }
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple(array_keys($records));
    $pending = [];
    foreach ($records as $uid => $record) {
      $user = $users[$uid] ?? NULL;
      if (!$user || $this->approvalGate->hasSignedAgreement((int) $uid)) {
        continue;
      }
      $record['user'] = $user;
      $pending[(int) $uid] = $record;
    }
    uasort($pending, static fn(array $a, array $b): int => ($b['last_sent'] ?? 0) <=> ($a['last_sent'] ?? 0));
    return $pending;
  }

  /**
   * HMAC over the things an invite must not outlive: the account, its email
   * and its password hash.
   */
  protected function hash(UserInterface $user, int $timestamp): string {
    return self::computeHash(
      (int) $user->id(),
      $timestamp,
      (string) $user->getEmail(),
      (string) $user->getPassword(),
      Settings::getHashSalt(),
    );
  }

  /**
   * Pure hash function, separated so it can be unit tested without a site.
   */
  public static function computeHash(int $uid, int $timestamp, string $mail, string $password_hash, string $salt): string {
    return Crypt::hmacBase64($uid . ':' . $timestamp . ':' . $mail, $salt . $password_hash);
  }

  /**
   * Finds an active or blocked account by email, case-insensitively.
   */
  protected function loadByMail(string $mail): ?UserInterface {
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $mail]);
    $user = $users ? reset($users) : NULL;
    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * Creates a role-less, password-less account for a new instructor.
   *
   * The account gets no roles here — signing the agreement grants
   * `instructor`. No password either: the invite link is the credential, and
   * the dashboard prompts them to set one afterwards.
   */
  protected function createAccount(string $name, string $mail): UserInterface {
    $storage = $this->entityTypeManager->getStorage('user');

    $username = $this->uniqueUsername($name !== '' ? $name : (strstr($mail, '@', TRUE) ?: $mail));

    /** @var \Drupal\user\UserInterface $user */
    $user = $storage->create([
      'name' => $username,
      'mail' => $mail,
      'status' => 1,
      'init' => $mail,
    ]);

    // Split "First Last" into the site's user name fields when they exist so
    // emails and the dashboard greet them properly.
    [$first, $last] = self::splitName($name);
    if ($user->hasField('field_first_name') && $first !== '') {
      $user->set('field_first_name', $first);
    }
    if ($user->hasField('field_last_name') && $last !== '') {
      $user->set('field_last_name', $last);
    }
    $user->save();

    $this->logger->notice('Created account @name <@mail> (uid @uid) for an instructor agreement invite.', [
      '@name' => $username,
      '@mail' => $mail,
      '@uid' => $user->id(),
    ]);
    return $user;
  }

  /**
   * "First Last" → [First, Last]; single word → [word, ''].
   */
  public static function splitName(string $name): array {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') {
      return ['', ''];
    }
    $pos = strrpos($name, ' ');
    if ($pos === FALSE) {
      return [$name, ''];
    }
    return [substr($name, 0, $pos), substr($name, $pos + 1)];
  }

  /**
   * A username Drupal will accept that no other account holds.
   */
  protected function uniqueUsername(string $base): string {
    $base = trim(preg_replace('/[^\p{L}\p{N}\s@._\'-]/u', '', $base));
    $base = mb_substr($base !== '' ? $base : 'instructor', 0, 50);
    $storage = $this->entityTypeManager->getStorage('user');
    $candidate = $base;
    $i = 2;
    while ($storage->loadByProperties(['name' => $candidate])) {
      $candidate = $base . ' ' . $i++;
    }
    return $candidate;
  }

  /**
   * Sends the invite email.
   */
  protected function sendMail(UserInterface $user, AccountInterface $sender, string $note, int $timestamp): bool {
    $to = $user->getEmail();
    if (!$to) {
      return FALSE;
    }
    $sender_user = $this->entityTypeManager->getStorage('user')->load($sender->id());
    $sender_name = $sender_user ? $sender_user->getDisplayName() : (string) $sender->getDisplayName();

    $result = $this->mailManager->mail(
      'instructor_companion',
      'instructor_invite',
      $to,
      $user->getPreferredLangcode(),
      [
        'user' => $user,
        'link' => $this->buildUrl($user, $timestamp)->toString(),
        'note' => $note,
        'sender' => $sender_name,
      ],
      NULL,
      TRUE,
    );
    return (bool) ($result['result'] ?? FALSE);
  }

}
