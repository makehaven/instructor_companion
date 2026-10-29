<?php

namespace Drupal\Tests\instructor_companion\Functional;

use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\Webform;

/**
 * A staff invite link signs the person in and lands them on their setup list.
 *
 * @group instructor_companion
 */
class InstructorInviteAcceptTest extends BrowserTestBase {

  use AssertMailTrait {
    getMails as drupalGetMails;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'node',
    'webform',
    'profile',
    'token',
    'path_alias',
    'profile_registration',
    'instructor_companion',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Stand-in for the site's agreement (site config, not module config).
    Webform::create([
      'id' => 'webform_5220',
      'title' => 'MakeHaven Instructor Agreement',
      'elements' => Yaml::encode([
        'signature' => ['#type' => 'textfield', '#title' => 'Typed legal name', '#required' => TRUE],
      ]),
      'access' => ['create' => ['roles' => ['authenticated'], 'users' => [], 'permissions' => []]],
    ])->save();
    \Drupal::entityTypeManager()->getStorage('path_alias')->create([
      'path' => '/webform/webform_5220',
      'alias' => '/instructor/agreement',
      'langcode' => 'en',
    ])->save();
  }

  /**
   * Inviting creates the account, and its link opens the agreement signed in.
   */
  public function testInviteCreatesAccountAndLinkOpensAgreement(): void {
    /** @var \Drupal\instructor_companion\Service\InstructorInviteManager $invites */
    $invites = \Drupal::service('instructor_companion.invite');
    $staff = $this->drupalCreateUser([], 'edu_staff');

    $result = $invites->invite('Ada Lovelace', 'ada@example.com', $staff, 'See you in October.');

    $this->assertTrue($result['created']);
    $user = $result['user'];
    $this->assertSame('ada@example.com', $user->getEmail());
    $this->assertEmpty($user->getRoles(TRUE), 'No roles before signing.');

    $mails = array_values(array_filter($this->drupalGetMails(), static fn(array $m): bool => ($m['key'] ?? '') === 'instructor_invite'));
    $this->assertCount(1, $mails);
    $this->assertSame('ada@example.com', $mails[0]['to']);
    $this->assertStringContainsString('/instructor/invite/' . $user->id() . '/', $mails[0]['body']);
    $this->assertStringContainsString('See you in October.', $mails[0]['body']);
    $this->assertStringContainsString('edu_staff', $mails[0]['body']);

    // Follow the link as an anonymous visitor.
    $url = $invites->buildUrl($user, \Drupal::time()->getRequestTime());
    $this->drupalGet($url);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressMatches('#/instructor/setup$#');
    $this->assertSession()->pageTextContains('You are signed in');
    $this->assertSession()->pageTextContains('Choose a password');
    $this->assertSession()->pageTextContains('Add your bio and a photo');
    $this->assertSession()->pageTextContains('Watch the member orientation video and pass the quiz');
    $this->assertSession()->pageTextContains('Book your in-person Site Orientation');

    // The password step carries the session token, so the account form lets
    // them choose one without the random current password.
    $this->clickLink('Choose a password');
    $this->assertSession()->addressMatches('#/user/' . $user->id() . '/edit#');
    $this->assertSession()->fieldNotExists('current_pass');

    // The agreement is still one click away when no guide is configured.
    $this->drupalGet('instructor/setup');
    $this->clickLink('Sign the instructor agreement');
    $this->assertSession()->addressMatches('#/instructor/agreement$#');
    $this->assertSession()->pageTextContains('Typed legal name');
  }

  /**
   * With a Guidebook configured, the agreement waits for it.
   */
  public function testGuideComesBeforeAgreement(): void {
    $this->drupalCreateContentType(['type' => 'policy', 'name' => 'Policy']);
    $guide = $this->drupalCreateNode(['type' => 'policy', 'title' => 'Instructor Guidebook', 'status' => 1]);
    \Drupal::entityTypeManager()->getStorage('path_alias')->create([
      'path' => '/node/' . $guide->id(),
      'alias' => '/instructor-handbook',
      'langcode' => 'en',
    ])->save();
    $this->config('instructor_companion.settings')->set('instructor_handbook_url', '/instructor-handbook')->save();

    /** @var \Drupal\instructor_companion\Service\InstructorInviteManager $invites */
    $invites = \Drupal::service('instructor_companion.invite');
    $staff = $this->drupalCreateUser([], 'edu_staff');
    $user = $invites->invite('Mary Somerville', 'mary@example.com', $staff)['user'];
    $this->drupalGet($invites->buildUrl($user, \Drupal::time()->getRequestTime()));
    $this->assertSession()->pageTextContains('Read the Guidebook first.');

    // Going straight to the agreement is turned back to the guide.
    $this->drupalGet('instructor/agreement');
    $this->assertSession()->addressEquals($guide->toUrl()->toString());
    $this->assertSession()->pageTextContains('please read the Instructor Guidebook');

    $this->submitForm(['confirm' => TRUE], 'Confirm and continue');
    $this->assertSession()->addressMatches('#/instructor/setup$#');
    $this->assertSession()->pageTextContains('You confirmed reading it on');

    $this->drupalGet('instructor/agreement');
    $this->assertSession()->addressMatches('#/instructor/agreement$#');
    $this->assertSession()->pageTextContains('Typed legal name');
  }

  /**
   * The old staff bookmark works for staff and for everyone else.
   */
  public function testLegacyRegisterLink(): void {
    $this->drupalGet('register/instructor');
    $this->assertSession()->addressMatches('#/become-instructor$#');

    $this->drupalLogin($this->drupalCreateUser(['access education console']));
    $this->drupalGet('register/instructor');
    $this->assertSession()->addressMatches('#/admin/education/invite$#');
  }

  /**
   * Stale and tampered links do not sign anyone in.
   */
  public function testExpiredOrForgedLinkIsRefused(): void {
    /** @var \Drupal\instructor_companion\Service\InstructorInviteManager $invites */
    $invites = \Drupal::service('instructor_companion.invite');
    $user = User::create(['name' => 'grace', 'mail' => 'grace@example.com', 'status' => 1]);
    $user->save();

    $expired = $invites->buildUrl($user, \Drupal::time()->getRequestTime() - 15 * 86400);
    $this->drupalGet($expired);
    $this->assertSession()->pageTextContains('This invite link has expired');
    $this->drupalGet('user/' . $user->id());
    $this->assertSession()->statusCodeEquals(403);

    $good = $invites->buildUrl($user, \Drupal::time()->getRequestTime());
    $forged = str_replace(substr($good->toString(), -6), 'xxxxxx', $good->toString());
    $this->drupalGet($forged);
    $this->assertSession()->pageTextContains('This invite link is not valid');
    $this->drupalGet('user/' . $user->id());
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * An existing account is reused, not duplicated.
   */
  public function testInviteReusesExistingAccount(): void {
    /** @var \Drupal\instructor_companion\Service\InstructorInviteManager $invites */
    $invites = \Drupal::service('instructor_companion.invite');
    $existing = $this->drupalCreateUser([], 'already_here');
    $staff = $this->drupalCreateUser([], 'edu_staff');

    $result = $invites->invite('Someone Else', $existing->getEmail(), $staff);

    $this->assertFalse($result['created']);
    $this->assertSame($existing->id(), $result['user']->id());
    $this->assertCount(1, $invites->pending());
  }

}
