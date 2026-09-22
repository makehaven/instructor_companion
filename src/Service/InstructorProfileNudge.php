<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Asks an active instructor to finish their public page.
 *
 * Since 2026-09-14 the instructor page leads with photo and bio and is
 * public; 19 of 45 active instructors had no bio and 17 no photo. The
 * education manager asked for a way to encourage them (2026-09-22). One
 * email, listing exactly what is missing, with the edit link; not more often
 * than every REPEAT_DAYS per person. Sent from the roster button or
 * `drush ic-profile-nudge --send`, never from cron — a nudge is a decision.
 */
class InstructorProfileNudge {

  public const STATE_KEY = 'instructor_companion.profile_nudge_sent';
  public const MAIL_KEY = 'instructor_profile_nudge';
  public const REPEAT_DAYS = 60;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected InstructorApprovalGate $gate,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Active instructors whose public page is missing a photo or a bio.
   *
   * @return array<int, array>
   *   profile_id, uid, name, email, missing (list of 'photo' / 'bio'),
   *   last_nudged (timestamp or 0).
   */
  public function incomplete(): array {
    $storage = $this->entityTypeManager->getStorage('profile');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'instructor')
      ->condition('status', 1)
      ->execute();
    $sent = (array) $this->state->get(self::STATE_KEY, []);
    $out = [];
    foreach ($ids ? $storage->loadMultiple($ids) : [] as $p) {
      if ($this->gate->status($p) !== InstructorApprovalGate::STATUS_ACTIVE) {
        continue;
      }
      $owner = $p->getOwner();
      if (!$owner || !$owner->isActive() || !$owner->getEmail()) {
        continue;
      }
      $missing = [];
      if (!$p->hasField('field_instructor_photo') || $p->get('field_instructor_photo')->isEmpty()) {
        $missing[] = 'photo';
      }
      $bio = $p->hasField('field_instructor_bio') ? trim(str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags((string) $p->get('field_instructor_bio')->value), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '';
      if ($bio === '') {
        $missing[] = 'bio';
      }
      if (!$missing) {
        continue;
      }
      $first = $owner->hasField('field_first_name') ? trim((string) $owner->get('field_first_name')->value) : '';
      $out[] = [
        'profile_id' => (int) $p->id(),
        'uid' => (int) $owner->id(),
        'name' => $first !== '' ? $first : $owner->getDisplayName(),
        'email' => (string) $owner->getEmail(),
        'missing' => $missing,
        'last_nudged' => (int) ($sent[(int) $owner->id()] ?? 0),
      ];
    }
    return $out;
  }

  /**
   * Who would be emailed right now (not nudged inside REPEAT_DAYS).
   */
  public function due(): array {
    $cutoff = $this->time->getRequestTime() - self::REPEAT_DAYS * 86400;
    return array_values(array_filter($this->incomplete(), fn($r) => $r['last_nudged'] < $cutoff));
  }

  /**
   * Sends to everyone due. Returns how many.
   */
  public function sendAll(): int {
    $n = 0;
    $sent = (array) $this->state->get(self::STATE_KEY, []);
    foreach ($this->due() as $r) {
      if ($this->email($r)) {
        $sent[$r['uid']] = $this->time->getRequestTime();
        $this->state->set(self::STATE_KEY, $sent);
        $n++;
      }
    }
    if ($n) {
      $this->logger->notice('Instructor profile nudge sent to @n instructor(s).', ['@n' => $n]);
    }
    return $n;
  }

  /**
   * Subject and body. Pure; unit tested.
   *
   * @return array{subject:string, body:string}
   *   Subject and plain-text body.
   */
  public static function compose(array $r, string $edit_url, string $public_url, string $subject_tpl = '', string $body_tpl = ''): array {
    $labels = ['photo' => 'a photo', 'bio' => 'a short bio'];
    $missing = implode(' and ', array_map(fn($m) => $labels[$m] ?? $m, $r['missing']));
    $replacements = [
      '[first_name]' => $r['name'],
      '[missing]' => $missing,
      '[edit_url]' => $edit_url,
      '[public_url]' => $public_url,
    ];
    $subject = $subject_tpl !== '' ? $subject_tpl : self::defaultSubject();
    $body = $body_tpl !== '' ? $body_tpl : self::defaultBody();
    return ['subject' => strtr($subject, $replacements), 'body' => strtr($body, $replacements)];
  }

  /**
   * Default subject.
   */
  public static function defaultSubject(): string {
    return 'Your MakeHaven instructor page is missing [missing]';
  }

  /**
   * Default body.
   */
  public static function defaultBody(): string {
    return "Hi [first_name],\n\n"
      . "Your instructor page on makehaven.org is now the first thing people see when they decide whether to sign up for your class — and right now it is missing [missing].\n\n"
      . "It takes about five minutes. Add it here:\n[edit_url]\n\n"
      . "This is what visitors see today:\n[public_url]\n\n"
      . "A photo of you (in the shop is ideal) and three or four sentences about what you make and what you like teaching are all it needs. If you would rather send them to us, reply to this email and we will put them up for you.\n\n"
      . "Thanks for teaching at MakeHaven,\nThe Education Team\neducation@makehaven.org";
  }

  /**
   * Sends one nudge.
   */
  protected function email(array $r): bool {
    $config = $this->configFactory->get('instructor_companion.settings');
    $edit_url = Url::fromRoute('entity.profile.edit_form', ['profile' => $r['profile_id']], ['absolute' => TRUE])->toString();
    $public_url = Url::fromRoute('entity.profile.canonical', ['profile' => $r['profile_id']], ['absolute' => TRUE])->toString();
    $message = self::compose($r, $edit_url, $public_url, (string) $config->get('profile_nudge_subject'), (string) $config->get('profile_nudge_body'));
    $result = $this->mailManager->mail(
      'instructor_companion',
      self::MAIL_KEY,
      $r['email'],
      \Drupal::languageManager()->getDefaultLanguage()->getId(),
      ['subject' => $message['subject'], 'body' => $message['body']],
      NULL,
      TRUE
    );
    if (empty($result['result'])) {
      $this->logger->warning('Profile nudge to @mail did not send.', ['@mail' => $r['email']]);
      return FALSE;
    }
    return TRUE;
  }

}
