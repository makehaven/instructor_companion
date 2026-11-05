<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\TeachingPipeline;
use Drupal\Tests\UnitTestCase;

/**
 * How the teaching pipeline counts.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\TeachingPipeline
 */
class TeachingPipelineTest extends UnitTestCase {

  /**
   * @covers ::assemble
   */
  public function testStagesAreNestedAndExcludeCurrentTeachers(): void {
    $rows = TeachingPipeline::assemble(
      [1 => 'Wood', 2 => 'Glass'],
      // Member 4 has lapsed from the list; 5 interested only.
      [1, 2, 3, 5],
      // 3 already teaches.
      [3],
      [1, 2, 3, 4],
      [1 => [1, 2, 3, 4, 5], 2 => [1]],
      [1 => [100]],
      [100 => [1, 3, 9]],
      [1 => [70, 70, 71]],
      [1 => [3, 8]],
      [1 => [8]],
      [1 => 6],
    );
    $this->assertSame(
      ['tid' => 1, 'area' => 'Wood', 'interested' => 4, 'willing' => 2, 'badge' => 1, 'applied' => 2, 'taught' => 2, 'active' => 1, 'waiting' => 6],
      $rows[0],
    );
    $this->assertNull($rows[1]['badge'], 'No linked badge is not the same as zero.');
    $this->assertSame(1, $rows[1]['willing']);
  }

  /**
   * @covers ::filter
   * @covers ::sort
   */
  public function testFilterAndSort(): void {
    $rows = [
      ['area' => 'B', 'waiting' => 0, 'active' => 1, 'badge' => NULL],
      ['area' => 'A', 'waiting' => 3, 'active' => 5, 'badge' => 0],
      ['area' => 'C', 'waiting' => 9, 'active' => 0, 'badge' => 4],
    ];
    $this->assertSame(['A', 'C'], array_column(TeachingPipeline::filter($rows, 'waiting'), 'area'));
    $this->assertSame(['B', 'C'], array_column(TeachingPipeline::filter($rows, 'few'), 'area'));
    $this->assertSame(['C', 'A', 'B'], array_column(TeachingPipeline::sort($rows, 'waiting'), 'area'));
    $this->assertSame(['C', 'A', 'B'], array_column(TeachingPipeline::sort($rows, 'badge'), 'area'), 'No link sorts below a real zero.');
    $this->assertSame(['A', 'B', 'C'], array_column(TeachingPipeline::sort($rows, 'area'), 'area'));
  }

}
