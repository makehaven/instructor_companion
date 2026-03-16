<?php

namespace Drupal\instructor_companion\Tests\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests that the instructor registration flow sends the correct notification.
 *
 * Uses Drupal's test mail collector (TestMailCollector) to capture outgoing
 * messages and assert on their subject and body without a real SMTP server.
 *
 * @group instructor_companion
 */
class ProposalMailNotificationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'webform',
    'profile',
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

    // Allow open registration so we can exercise the instructor path.
    $this->config('user.settings')
      ->set('register', 'visitors')
      ->set('verify_mail', FALSE)
      ->save();

    // Route all outgoing mail to the test collector.
    $this->config('system.mail')
      ->set('interface.default', 'test_mail_collector')
      ->save();

    // Set the site notification address.
    $this->config('instructor_companion.settings')
      ->set('notification_email', 'staff@example.com')
      ->save();
    $this->config('system.site')
      ->set('mail', 'site@example.com')
      ->save();
  }

  /**
   * Tests that registering via ?profile=instructor triggers a staff email.
   */
  public function testInstructorRegistrationSendsStaffEmail(): void {
    $this->drupalGet('user/register', ['query' => ['profile' => 'instructor']]);
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'name'        => 'instructor_jane',
      'mail'        => 'jane@example.com',
      'pass[pass1]' => 'SecurePass123!',
      'pass[pass2]' => 'SecurePass123!',
    ], 'Create new account');

    // Verify the user-facing success message.
    $this->assertSession()->pageTextContains(
      'Your instructor application has been started. Staff have been notified.'
    );

    // Retrieve captured emails.
    $captured = \Drupal::state()->get('system.test_mail_collector') ?? [];
    $this->assertNotEmpty($captured, 'At least one email should have been sent');

    $last_mail = end($captured);

    // Subject should contain the username.
    $this->assertStringContainsString(
      'New Instructor Application:',
      (string) $last_mail['subject'],
      'Email subject should identify it as an instructor application'
    );
    $this->assertStringContainsString('instructor_jane', (string) $last_mail['subject']);

    // Body should contain identifying information.
    // TestMailCollector::format() joins body parts into a single string.
    $body = is_array($last_mail['body']) ? implode("\n", $last_mail['body']) : (string) $last_mail['body'];
    $this->assertStringContainsString('instructor_jane', $body, 'Email body should include the username');
    $this->assertStringContainsString('jane@example.com', $body, 'Email body should include the email address');
    $this->assertStringContainsString(
      'Please review their application',
      $body,
      'Email body should contain the approval instruction'
    );
  }

  /**
   * Tests that registering WITHOUT ?profile=instructor sends NO staff email.
   */
  public function testNormalRegistrationDoesNotSendInstructorEmail(): void {
    // Clear any previously captured mail.
    \Drupal::state()->set('system.test_mail_collector', []);

    $this->drupalGet('user/register');
    $this->submitForm([
      'name'        => 'regular_user',
      'mail'        => 'regular@example.com',
      'pass[pass1]' => 'SecurePass123!',
      'pass[pass2]' => 'SecurePass123!',
    ], 'Create new account');

    $captured = \Drupal::state()->get('system.test_mail_collector') ?? [];

    // There should be no instructor application email.
    foreach ($captured as $mail) {
      $this->assertStringNotContainsString(
        'New Instructor Application',
        (string) $mail['subject'],
        'A standard registration should not send an instructor application email'
      );
    }
  }

  /**
   * Tests that notification falls back to system.site mail when unconfigured.
   */
  public function testFallsBackToSiteMailWhenNotificationEmailBlank(): void {
    // Clear the module-level notification email.
    $this->config('instructor_companion.settings')
      ->set('notification_email', '')
      ->save();
    \Drupal::state()->set('system.test_mail_collector', []);

    $this->drupalGet('user/register', ['query' => ['profile' => 'instructor']]);
    $this->submitForm([
      'name'        => 'instructor_bob',
      'mail'        => 'bob@example.com',
      'pass[pass1]' => 'SecurePass123!',
      'pass[pass2]' => 'SecurePass123!',
    ], 'Create new account');

    $captured = \Drupal::state()->get('system.test_mail_collector') ?? [];
    $this->assertNotEmpty($captured, 'An email should still be sent via the site fallback address');

    $last_mail = end($captured);
    $this->assertSame(
      'site@example.com',
      $last_mail['to'],
      'When notification_email is blank, mail should go to the site email address'
    );
  }

}
