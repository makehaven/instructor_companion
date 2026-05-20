<?php

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\ProposalProcessor;
use Drupal\Tests\UnitTestCase;

/**
 * Tests parsing and template matching functions inside ProposalProcessor.
 *
 * @coversDefaultClass \Drupal\instructor_companion\Service\ProposalProcessor
 * @group instructor_companion
 */
class ProposalProcessorFieldMapTest extends UnitTestCase {

  /**
   * Tests static parseCompensation() helper.
   *
   * @dataProvider compensationProvider
   */
  public function testParseCompensation(?string $input, array $expected): void {
    $this->assertSame($expected, ProposalProcessor::parseCompensation($input));
  }

  /**
   * Data provider for testParseCompensation.
   */
  public static function compensationProvider(): array {
    return [
      'null input' => [
        NULL,
        ['amount' => NULL, 'type' => NULL],
      ],
      'empty input' => [
        '',
        ['amount' => NULL, 'type' => NULL],
      ],
      'whitespace input' => [
        '   ',
        ['amount' => NULL, 'type' => NULL],
      ],
      'hourly with slash' => [
        '$50/hour',
        ['amount' => 50.0, 'type' => 'hourly'],
      ],
      'hourly with hr' => [
        '$45.50/hr',
        ['amount' => 45.5, 'type' => 'hourly'],
      ],
      'hourly with per hour' => [
        '35 per hour',
        ['amount' => 35.0, 'type' => 'hourly'],
      ],
      'hourly with spaces and extra text' => [
        ' 50 / hour (from profile) ',
        ['amount' => 50.0, 'type' => 'hourly'],
      ],
      'fixed with flat' => [
        '$200 flat',
        ['amount' => 200.0, 'type' => 'fixed'],
      ],
      'fixed with fixed' => [
        '150 fixed',
        ['amount' => 150.0, 'type' => 'fixed'],
      ],
      'fixed with total' => [
        '$350 total',
        ['amount' => 350.0, 'type' => 'fixed'],
      ],
      'fallback numeric only (no keyword, default to fixed)' => [
        '$120',
        ['amount' => 120.0, 'type' => 'fixed'],
      ],
      'fallback numeric with word hour (default to hourly)' => [
        '120 and also hour',
        ['amount' => 120.0, 'type' => 'hourly'],
      ],
      'no numeric in text' => [
        'Volunteer/No payment',
        ['amount' => NULL, 'type' => NULL],
      ],
    ];
  }

  /**
   * Tests static parseCapacity() helper.
   *
   * @dataProvider capacityProvider
   */
  public function testParseCapacity(?string $input, ?int $expected): void {
    $this->assertSame($expected, ProposalProcessor::parseCapacity($input));
  }

  /**
   * Data provider for testParseCapacity.
   */
  public static function capacityProvider(): array {
    return [
      'null input' => [NULL, NULL],
      'empty input' => ['', NULL],
      'only spaces' => ['   ', NULL],
      'standard number' => ['12', 12],
      'spaces and number' => ['  8 ', 8],
      'number with text' => ['15 students', 15],
      'number with text surrounding' => ['up to 10', 10],
      'no number' => ['no limit', NULL],
    ];
  }

  /**
   * Tests static matchTemplateId() helper.
   *
   * @dataProvider templateProvider
   */
  public function testMatchTemplateId(string $title, int $expectedTplId): void {
    $this->assertSame($expectedTplId, ProposalProcessor::matchTemplateId($title));
  }

  /**
   * Data provider for testMatchTemplateId.
   */
  public static function templateProvider(): array {
    return [
      'gems exact case matches gems template' => [
        'Introduction to GEMS',
        1,
      ],
      'gems word boundary matches' => [
        'The GEMS of Glassmaking',
        1,
      ],
      'gems inside a word does not match gems template' => [
        'Pigments and Paints',
        3, // Standard Workshop default
      ],
      'meetup word matches meetup template' => [
        'Metalworking meetup',
        72,
      ],
      'gathering word matches meetup template' => [
        'Woodworking Gathering',
        72,
      ],
      'office hours word matches meetup template' => [
        '3D Printing Office Hours',
        72,
      ],
      'tour word matches tour template' => [
        'General Shop Tour',
        54,
      ],
      'field trip word matches tour template' => [
        'High School Field Trip',
        54,
      ],
      'foundations of start matches foundations template' => [
        'Foundations of Blacksmithing',
        166,
      ],
      'foundations of in the middle does not match foundations template' => [
        'Intermediate Foundations of Art',
        3, // Default
      ],
      'pathway word matches pathway template' => [
        'Stained Glass Pathway',
        174,
      ],
      'pathways word matches pathway template' => [
        'Stained Glass Pathways',
        174,
      ],
      'no matches defaults to standard workshop' => [
        'Intermediate Woodturning',
        3,
      ],
    ];
  }

}
