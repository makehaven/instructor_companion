<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\instructor_companion\Service\ProposalNotifier;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\ClientInterface;

/**
 * Tests ProposalNotifier::buildMessageText().
 *
 * @covers \Drupal\instructor_companion\Service\ProposalNotifier
 * @group instructor_companion
 */
class ProposalNotifierMessageTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();

    // ProposalNotifier::buildMessageText() calls Url::fromRoute() which
    // needs a url_generator and router in the container.
    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->willReturnCallback(
      function ($route, $params = [], $options = []) {
        // Return a predictable URL per route so assertions can check it.
        return 'https://example.test/' . $route;
      }
    );

    $container = new ContainerBuilder();
    $container->set('url_generator', $url_generator);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Builds a notifier with mocked dependencies (not used in buildMessageText).
   */
  protected function newNotifier(): ProposalNotifier {
    return new ProposalNotifier(
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerChannelInterface::class),
    );
  }

  /**
   * Builds a submission mock with data + webform id + submission id.
   */
  protected function submission(string $webform_id, int $sid, array $data): WebformSubmissionInterface {
    $webform = $this->createMock(WebformInterface::class);
    $webform->method('id')->willReturn($webform_id);

    $submission = $this->createMock(WebformSubmissionInterface::class);
    $submission->method('getWebform')->willReturn($webform);
    $submission->method('id')->willReturn($sid);
    $submission->method('getData')->willReturn($data);
    return $submission;
  }

  /**
   * Workshop Proposal message extracts title and submitter from suffixed keys.
   */
  public function testWorkshopProposalMessage(): void {
    $submission = $this->submission('webform_497', 42, [
      'your_name_25' => 'Jane Doe',
      'e_mail_address_25' => 'jane@example.com',
      'proposed_class_title_26' => 'Intro to MIG Welding',
    ]);

    $notifier = $this->newNotifier();
    $text = $notifier->buildMessageText(
      $submission,
      ProposalNotifier::HANDLED_WEBFORMS['webform_497']
    );

    $this->assertStringContainsString('Workshop Proposal', $text);
    $this->assertStringContainsString('Jane Doe', $text);
    $this->assertStringContainsString('jane@example.com', $text);
    $this->assertStringContainsString('Intro to MIG Welding', $text);
  }

  /**
   * Instructor Interest message extracts name and email from _6 keys.
   */
  public function testInstructorInterestMessage(): void {
    $submission = $this->submission('webform_14366', 7, [
      'name_6' => 'Alex Traveler',
      'email_6' => 'alex@roaming.example',
    ]);

    $notifier = $this->newNotifier();
    $text = $notifier->buildMessageText(
      $submission,
      ProposalNotifier::HANDLED_WEBFORMS['webform_14366']
    );

    $this->assertStringContainsString('Instructor Interest', $text);
    $this->assertStringContainsString('Alex Traveler', $text);
    $this->assertStringContainsString('alex@roaming.example', $text);
  }

  /**
   * Missing fields degrade gracefully — no PHP errors, sensible fallback.
   */
  public function testMissingFieldsFallback(): void {
    $submission = $this->submission('webform_497', 1, []);
    $notifier = $this->newNotifier();
    $text = $notifier->buildMessageText(
      $submission,
      ProposalNotifier::HANDLED_WEBFORMS['webform_497']
    );
    $this->assertStringContainsString('(no title)', $text);
    $this->assertStringContainsString('Workshop Proposal', $text);
  }

  /**
   * HANDLED_WEBFORMS covers the two intake forms we care about.
   */
  public function testHandledWebformsList(): void {
    $keys = array_keys(ProposalNotifier::HANDLED_WEBFORMS);
    $this->assertSame(['webform_497', 'webform_14366'], $keys);
  }

}
