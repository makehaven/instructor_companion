<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\InstructorProfileNudge;
use Drupal\Tests\UnitTestCase;

/**
 * The wording of the incomplete-profile nudge.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\InstructorProfileNudge
 */
class InstructorProfileNudgeComposeTest extends UnitTestCase {

  /**
   * @covers ::compose
   */
  public function testNamesWhatIsMissing(): void {
    $r = ['name' => 'Denise', 'missing' => ['photo', 'bio']];
    $out = InstructorProfileNudge::compose($r, 'https://x/profile/1794/edit', 'https://x/profile/1794');
    $this->assertSame('Your MakeHaven instructor page is missing a photo and a short bio', $out['subject']);
    $this->assertStringContainsString('Hi Denise,', $out['body']);
    $this->assertStringContainsString('https://x/profile/1794/edit', $out['body']);
    $this->assertStringNotContainsString('[', $out['body']);

    $one = InstructorProfileNudge::compose(['name' => 'D', 'missing' => ['bio']], 'e', 'p');
    $this->assertStringContainsString('missing a short bio', $one['subject']);
  }

}
