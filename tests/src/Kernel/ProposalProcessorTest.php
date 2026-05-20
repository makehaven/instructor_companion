<?php

// Define CiviCRM global stubs so the test doesn't crash on missing CiviCRM functions/classes.
namespace {
  if (!function_exists('civicrm_api3')) {
    function civicrm_api3($entity, $action, $params = []) {
      if ($entity === 'Event' && $action === 'getsingle') {
        return [
          'id' => $params['id'],
          'is_template' => 1,
          'title' => 'Mock Template Event',
        ];
      }
      return ['id' => 123];
    }
  }

  if (!class_exists('CRM_Event_BAO_Event')) {
    class CRM_Event_BAO_Event {
      public static function copy($template_id) {
        $event = new \stdClass();
        $event->id = 123;
        return $event;
      }
    }
  }
}

namespace Civi\Api4 {
  if (!class_exists('Event')) {
    class Event {
      public static function create($checkPermissions = TRUE) {
        $mock = new class {
          public function addValue($field, $value) { return $this; }
          public function execute() {
            return new class {
              public function first() {
                return ['id' => 123];
              }
            };
          }
        };
        return $mock;
      }
    }
  }
}

namespace Drupal\Tests\instructor_companion\Kernel {

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\instructor_companion\Service\ProposalProcessor;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Drupal\webform\WebformSubmissionInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests ProposalProcessor logic.
 *
 * @coversDefaultClass \Drupal\instructor_companion\Service\ProposalProcessor
 * @group instructor_companion
 */
class ProposalProcessorTest extends KernelTestBase {

  /**
   * Skip strict config schema — we set instructor_companion.settings keys
   * directly without installing the module's full schema (avoids pulling
   * the webform + civicrm dependency chain into this small test).
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'options',
  ];

  /**
   * The processor under test.
   */
  protected ProposalProcessor $processor;

  /**
   * A mock civicrm service.
   */
  protected \stdClass $civicrmMock;

  /**
   * Mocked event entity.
   */
  protected $mockEventEntity;

  /**
   * Captured mail() calls, populated by the mock mail manager.
   */
  protected array $mailCalls = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node']);

    // Create course node type.
    $course_type = NodeType::create(['type' => 'course', 'name' => 'Course']);
    $course_type->save();
    $this->createField('node', 'course', 'body', 'text_with_summary');

    // Create required fields on Course.
    $this->createField('node', 'course', 'field_course_status', 'string');
    $this->createField('node', 'course', 'field_course_type', 'string');
    $this->createField('node', 'course', 'field_course_materials', 'string_long');
    $this->createField('node', 'course', 'field_payment_amount', 'float');
    $this->createField('node', 'course', 'field_payment_type', 'string');
    $this->createField('node', 'course', 'field_civicrm_template_id', 'integer');
    $this->createField('node', 'course', 'field_source_submission', 'entity_reference', ['target_type' => 'user']);

    // Register mock civicrm service.
    $this->civicrmMock = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['initialize'])
      ->getMock();
    $this->container->set('civicrm', $this->civicrmMock);

    // Mock entity type manager to return a stubbed civicrm_event storage.
    $real_etm = $this->container->get('entity_type.manager');
    $mock_etm = $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class);

    $this->mockEventEntity = $this->createMock(\Drupal\Core\Entity\ContentEntityInterface::class);
    $this->mockEventEntity->method('hasField')->willReturn(TRUE);
    $this->mockEventEntity->method('set')->willReturnSelf();
    $this->mockEventEntity->expects($this->any())->method('save');

    $mock_event_storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
    $mock_event_storage->method('load')->willReturn($this->mockEventEntity);

    $mock_etm->method('getStorage')->willReturnCallback(function ($entity_type_id) use ($real_etm, $mock_event_storage) {
      if ($entity_type_id === 'civicrm_event') {
        return $mock_event_storage;
      }
      return $real_etm->getStorage($entity_type_id);
    });

    $messenger = $this->container->get('messenger');

    // Use the real config factory; set the keys ProposalProcessor reads.
    // The real config object supplies CacheableMetadata which the token
    // service needs during $token->replace().
    $config_factory = $this->container->get('config.factory');
    $config_factory->getEditable('instructor_companion.settings')
      ->set('interest_approval_enabled', TRUE)
      ->set('interest_approval_subject', 'Next steps to become a MakeHaven instructor')
      ->set('interest_approval_body', "Hi [submission:name],\n\nNext steps: visit /video-instructor")
      ->save();

    // Capturing mail manager: every mail() call lands in $this->mailCalls
    // and reports success.
    $mail_manager = $this->createMock(\Drupal\Core\Mail\MailManagerInterface::class);
    $mail_calls = &$this->mailCalls;
    $mail_manager->method('mail')->willReturnCallback(function ($module, $key, $to, $langcode, $params) use (&$mail_calls) {
      $mail_calls[] = compact('module', 'key', 'to', 'langcode', 'params');
      return ['result' => TRUE, 'send' => TRUE];
    });

    $token = $this->container->get('token');

    $this->processor = new ProposalProcessor(
      $mock_etm,
      $messenger,
      $this->container->get('logger.channel.default'),
      $config_factory,
      $mail_manager,
      $token,
    );
  }

  /**
   * Helper to create field storage + config.
   */
  protected function createField(string $entity_type, string $bundle, string $field_name, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * Test successful processing of an approved submission.
   */
  public function testProcessApprovalSuccess(): void {
    $instructor = User::create([
      'name' => 'John Doe',
      'mail' => 'john.doe@example.com',
      'status' => 1,
    ]);
    $instructor->save();

    // Mock WebformSubmission.
    $submission = $this->createMock(WebformSubmissionInterface::class);
    $submission->method('id')->willReturn('497001');

    $webform = $this->getMockBuilder(\stdClass::class)->addMethods(['id'])->getMock();
    $webform->method('id')->willReturn('webform_497');
    $submission->method('getWebform')->willReturn($webform);

    $submission->method('getOwner')->willReturn($instructor);

    $submission->method('getData')->willReturn([
      'review_status_38' => 'approved',
      'proposed_class_title' => 'Introduction to GEMS and Glassmaking',
      'class_description_26' => 'A wonderful class description.',
      'students_will_learn_to' => 'Learn to cut glass safely.',
      'consumable_materials_supplies_27' => 'Glass sheets, cutters.',
      'instructor_compensation_25' => '$50/hour',
      'maximum_number_of_students' => '8',
    ]);

    // Expect CiviCRM initialization.
    $this->civicrmMock->expects($this->once())->method('initialize');

    // Run the processor!
    $this->processor->processApproval($submission);

    // Verify Course Node was created.
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->execute();

    $this->assertCount(1, $nids);
    $course_nid = reset($nids);
    /** @var \Drupal\node\NodeInterface $course */
    $course = $storage->load($course_nid);

    $this->assertSame('Introduction to GEMS and Glassmaking', $course->label());
    $this->assertSame('draft', $course->get('field_course_status')->value);
    $this->assertSame('workshop', $course->get('field_course_type')->value);
    $this->assertSame('Glass sheets, cutters.', $course->get('field_course_materials')->value);
    $this->assertEquals(50.0, $course->get('field_payment_amount')->value);
    $this->assertSame('hourly', $course->get('field_payment_type')->value);
    $this->assertEquals(1, $course->get('field_civicrm_template_id')->value); // GEMS template ID
    $this->assertEquals(497001, $course->get('field_source_submission')->target_id);
    $this->assertStringContainsString('A wonderful class description.', $course->get('body')->value);
    $this->assertStringContainsString('<h3>What students will learn:</h3>', $course->get('body')->value);
    $this->assertStringContainsString('Learn to cut glass safely.', $course->get('body')->value);
    $this->assertEquals($instructor->id(), $course->getOwnerId());

    // Run processor again to verify idempotency (it should log and skip duplicate creation).
    $this->processor->processApproval($submission);
    $nids_after = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->execute();
    $this->assertCount(1, $nids_after);
  }

  /**
   * webform_14366 approval sends the next-steps email and writes the
   * outreach timestamp. A second call on the same submission is a no-op
   * (idempotency by visible field, not state).
   */
  public function testInstructorInterestApprovalSendsEmailAndIsIdempotent(): void {
    // Stateful fake of a webform submission: getData returns the current
    // $data; setData replaces it; resave is the persistence call our
    // processor uses to record the outreach timestamp.
    $data = [
      'review_status_38' => 'approved',
      'name_6' => 'Casey Tester',
      'email_6' => 'casey@example.com',
    ];

    $webform = $this->getMockBuilder(\stdClass::class)->addMethods(['id'])->getMock();
    $webform->method('id')->willReturn('webform_14366');

    $submission = $this->createMock(WebformSubmissionInterface::class);
    $submission->method('id')->willReturn('14366001');
    $submission->method('getWebform')->willReturn($webform);
    $submission->method('getData')->willReturnCallback(function () use (&$data) {
      return $data;
    });
    $submission->method('setData')->willReturnCallback(function ($new) use (&$data) {
      $data = $new;
    });
    $resave_count = 0;
    $submission->method('resave')->willReturnCallback(function () use (&$resave_count) {
      $resave_count++;
      return SAVED_UPDATED;
    });

    // First call: should send email + write timestamp + resave.
    $this->processor->processApproval($submission);
    $this->assertCount(1, $this->mailCalls, 'Exactly one email is dispatched on first approval.');
    $sent = $this->mailCalls[0];
    $this->assertSame('instructor_companion', $sent['module']);
    $this->assertSame('interest_approval', $sent['key']);
    $this->assertSame('casey@example.com', $sent['to']);
    $this->assertStringContainsString('Casey Tester', $sent['params']['body'], 'Submitter name is interpolated into the body.');
    $this->assertNotEmpty($data['interest_outreach_sent_at'], 'Outreach timestamp is written onto submission data.');
    $this->assertSame(1, $resave_count, 'resave() is called once to persist the timestamp.');

    // Second call on the same submission (timestamp already present): no-op.
    $this->processor->processApproval($submission);
    $this->assertCount(1, $this->mailCalls, 'No second email is sent (idempotency).');
    $this->assertSame(1, $resave_count, 'No additional resave on idempotent re-call.');
  }

  /**
   * Verify that non-approved or incorrect webform submissions are skipped.
   */
  public function testSkipsNonApprovedAndOtherForms(): void {
    // 1. Incorrect webform ID.
    $submission1 = $this->createMock(WebformSubmissionInterface::class);
    $webform1 = $this->getMockBuilder(\stdClass::class)->addMethods(['id'])->getMock();
    $webform1->method('id')->willReturn('other_webform');
    $submission1->method('getWebform')->willReturn($webform1);
    $this->processor->processApproval($submission1);

    // 2. Webform 497 but not approved.
    $submission2 = $this->createMock(WebformSubmissionInterface::class);
    $webform2 = $this->getMockBuilder(\stdClass::class)->addMethods(['id'])->getMock();
    $webform2->method('id')->willReturn('webform_497');
    $submission2->method('getWebform')->willReturn($webform2);
    $submission2->method('getData')->willReturn([
      'review_status_38' => 'pending',
    ]);
    $this->processor->processApproval($submission2);

    // Verify absolutely no Course Node was created.
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->execute();
    $this->assertEmpty($nids);
  }

}
}
