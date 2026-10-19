<?php

namespace Drupal\Tests\instructor_companion\Kernel;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\instructor_companion\Service\InstructorApprovalGate;
use Drupal\instructor_companion\Service\InstructorDoorAccess;
use Drupal\instructor_companion\Service\InstructorInviteManager;
use Drupal\instructor_companion\Service\InstructorSetupChecklist;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * The instructor setup list: which steps show, and when each one is done.
 */
#[Group('instructor_companion')]
class InstructorSetupChecklistTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'node', 'field', 'text', 'path_alias'];

  /**
   * The module itself is not installed, so its settings have no schema here.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Whether the gate reports a signed agreement.
   */
  protected bool $signed = FALSE;

  /**
   * Door badge status the door service reports, or NULL for none.
   */
  protected ?string $doorStatus = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('user', ['users_data']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node']);
    NodeType::create(['type' => 'policy', 'name' => 'Policy'])->save();
    // Uid 1 out of the way so test accounts are ordinary users.
    User::create(['name' => 'admin', 'status' => 1])->save();
  }

  /**
   * The service, with the profile and door lookups stubbed.
   */
  protected function checklist(): InstructorSetupChecklist {
    $gate = $this->createMock(InstructorApprovalGate::class);
    $gate->method('loadInstructorProfile')->willReturn(NULL);
    $gate->method('hasSignedAgreement')->willReturnCallback(fn() => $this->signed);

    $door = $this->createMock(InstructorDoorAccess::class);
    $door->method('loadDoorBadgeRequest')->willReturnCallback(function () {
      if ($this->doorStatus === NULL) {
        return NULL;
      }
      $status = $this->createMock(FieldItemListInterface::class);
      $status->method('__get')->with('value')->willReturn($this->doorStatus);
      $node = $this->createMock(NodeInterface::class);
      $node->method('hasField')->willReturn(TRUE);
      $node->method('get')->willReturn($status);
      return $node;
    });

    return new InstructorSetupChecklist(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('user.data'),
      $this->container->get('keyvalue'),
      $gate,
      $door,
      $this->container->get('datetime.time'),
      $this->container->get('path_alias.manager'),
    );
  }

  /**
   * An account the invite made: every step, starting with the password.
   */
  public function testInviteMadeAccountGetsEveryStep(): void {
    $user = User::create(['name' => 'ada', 'mail' => 'ada@example.com', 'status' => 1]);
    $user->save();
    $this->container->get('keyvalue')->get(InstructorInviteManager::COLLECTION)
      ->set($user->id(), ['invited_at' => 1, 'last_sent' => 1, 'created' => TRUE]);

    $checklist = $this->checklist();
    $steps = $checklist->steps($user);
    $this->assertSame([
      InstructorSetupChecklist::STEP_PASSWORD,
      InstructorSetupChecklist::STEP_PROFILE,
      InstructorSetupChecklist::STEP_AGREEMENT,
      InstructorSetupChecklist::STEP_ORIENTATION,
      InstructorSetupChecklist::STEP_SITE,
    ], array_keys($steps), 'No guide step until a guide page exists.');
    $this->assertSame('0 of 5', InstructorSetupChecklist::progress($steps));
    $this->assertSame('user.pass', $steps['password']['url']->getRouteName(), 'Without the session token: reset by email.');

    $with_token = $checklist->steps($user, 'tok');
    $this->assertSame('entity.user.edit_form', $with_token['password']['url']->getRouteName());
    $this->assertSame(['pass-reset-token' => 'tok'], $with_token['password']['url']->getOption('query'));

    $checklist->markPasswordSet((int) $user->id());
    $this->signed = TRUE;
    $this->doorStatus = 'pending';
    $steps = $checklist->steps($user);
    $this->assertTrue($steps['password']['done']);
    $this->assertTrue($steps['agreement']['done']);
    $this->assertFalse($steps['orientation']['done'], 'A pending door request is not the quiz.');
    $this->assertFalse($steps['site_orientation']['done']);

    $this->assertArrayHasKey((int) $user->id(), $checklist->inProgress());
  }

  /**
   * The guide is the page at the handbook link, and gates the agreement.
   */
  public function testGuideStepAndAgreementLock(): void {
    $guide = Node::create(['type' => 'policy', 'title' => 'Instructor Guidebook', 'status' => 1]);
    $guide->save();
    $this->container->get('entity_type.manager')->getStorage('path_alias')->create([
      'path' => '/node/' . $guide->id(),
      'alias' => '/instructor-handbook',
    ])->save();
    $this->config('instructor_companion.settings')->set('instructor_handbook_url', '/instructor-handbook')->save();

    $user = User::create(['name' => 'mary', 'mail' => 'mary@example.com', 'status' => 1]);
    $user->save();
    $uid = (int) $user->id();

    $checklist = $this->checklist();
    $this->assertSame((int) $guide->id(), (int) $checklist->guideNode()->id());
    $this->assertTrue($checklist->guideRequiredFirst($uid));
    $steps = $checklist->steps($user);
    $this->assertArrayNotHasKey('password', $steps, 'An account they made themselves already has their password.');
    $this->assertFalse($steps['guide']['done']);
    $this->assertSame('Read the Guidebook first.', $steps['agreement']['locked']);

    $checklist->acknowledgeGuide($uid);
    $this->assertFalse($checklist->guideRequiredFirst($uid));
    $steps = $checklist->steps($user);
    $this->assertTrue($steps['guide']['done']);
    $this->assertSame('', $steps['agreement']['locked']);
    $this->assertSame((int) $guide->getRevisionId(), $checklist->guideAcknowledgement($uid)['vid']);

    // Unpublishing the page switches the step off rather than stranding people.
    $guide->setUnpublished()->save();
    $this->assertNull($checklist->guideNode());
    $other = User::create(['name' => 'x', 'status' => 1]);
    $other->save();
    $this->assertFalse($checklist->guideRequiredFirst((int) $other->id()));
  }

  /**
   * Only invited people are asked to book a Site Orientation.
   *
   * Instructors from before invites who already have door access are not;
   * invited people are, until the card is on their account.
   */
  public function testSiteOrientationStep(): void {
    $this->doorStatus = 'active';
    $legacy = User::create(['name' => 'old_hand', 'status' => 1]);
    $legacy->save();
    $steps = $this->checklist()->steps($legacy);
    $this->assertTrue($steps['orientation']['done']);
    $this->assertTrue($steps['site_orientation']['done']);

    $invited = User::create(['name' => 'new_hand', 'status' => 1]);
    $invited->save();
    $this->container->get('keyvalue')->get(InstructorInviteManager::COLLECTION)
      ->set($invited->id(), ['invited_at' => 1, 'created' => FALSE]);
    $steps = $this->checklist()->steps($invited);
    $this->assertTrue($steps['orientation']['done']);
    $this->assertFalse($steps['site_orientation']['done'], 'Invited: done only when the card is on the account.');
  }

}
