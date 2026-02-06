<?php

namespace Drupal\instructor_companion\Tests\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the instructor registration flow.
 *
 * @group instructor_companion
 */
class InstructorRegistrationTest extends BrowserTestBase {

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

    // Allow visitors to register and show password field.
    $this->config('user.settings')
      ->set('register', 'visitors')
      ->set('verify_mail', FALSE)
      ->save();
  }

  /**
   * Tests the instructor registration flow.
   */
  public function testInstructorRegistration() {
    // Navigate to registration page with instructor profile.
    $this->drupalGet('user/register', ['query' => ['profile' => 'instructor']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Create new account');

    // Fill out the registration form.
    $edit = [
      'name' => 'test_instructor',
      'mail' => 'instructor_test@example.com',
      'pass[pass1]' => 'InstructorPass123!',
      'pass[pass2]' => 'InstructorPass123!',
    ];
    
    // Note: If profile fields are on the registration form, they might have
    // different names. However, the hook_form_alter in instructor_companion
    // doesn't add fields, it just adds a submit handler if ?profile=instructor
    // is present.
    $this->submitForm($edit, 'Create new account');

    // Verify the success message from the custom submit handler.
    $this->assertSession()->pageTextContains('Your instructor application has been started. Staff have been notified.');

    // Verify that the user was created.
    $user = user_load_by_name('test_instructor');
    $this->assertNotEmpty($user);
    $this->assertEquals('instructor_test@example.com', $user->getEmail());
  }

  /**
   * Tests instructor dashboard access.
   */
  public function testInstructorDashboardAccess() {
    $instructor = $this->drupalCreateUser(['access instructor dashboard']);
    $this->drupalLogin($instructor);

    $this->drupalGet('instructor/dashboard');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Instructor Dashboard');

    $non_instructor = $this->drupalCreateUser();
    $this->drupalLogin($non_instructor);
    $this->drupalGet('instructor/dashboard');
    $this->assertSession()->statusCodeEquals(403);
  }

}
