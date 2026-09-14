<?php

declare(strict_types=1);

namespace Drupal\Tests\instructor_companion\Unit;

use Drupal\instructor_companion\Service\CourseFollowerNotifier;
use Drupal\Tests\UnitTestCase;

/**
 * The follower notice wording, per course type.
 *
 * @group instructor_companion
 * @coversDefaultClass \Drupal\instructor_companion\Service\CourseFollowerNotifier
 */
class CourseFollowerNotifierTest extends UnitTestCase {

  /**
   * @covers ::noun
   */
  public function testNounFollowsTheThemeTable(): void {
    $this->assertSame('cohort', CourseFollowerNotifier::noun('program'));
    $this->assertSame('session', CourseFollowerNotifier::noun('workshop'));
    $this->assertSame('meetup', CourseFollowerNotifier::noun('meetup'));
    $this->assertSame('class', CourseFollowerNotifier::noun('badge_class'));
    $this->assertSame('session', CourseFollowerNotifier::noun(''), 'Unknown types read as sessions.');
  }

  /**
   * @covers ::compose
   */
  public function testComposeNamesTheCourseTheRunAndTheDate(): void {
    $event = [
      'id' => 999,
      'title' => 'Foundations of Fabrication - Cohort 10',
      'start' => '2027-01-11 19:00:00',
      'course_nid' => 40290,
      'course_title' => 'Foundations of Fabrication',
      'course_type' => 'program',
    ];
    $m = CourseFollowerNotifier::compose('Ada', $event, 'https://x/civicrm/event/info?id=999&reset=1', 'https://x/foundations-fabrication');
    $this->assertSame('Foundations of Fabrication: a new cohort is open', $m['subject']);
    $this->assertStringStartsWith("Hi Ada,\n", $m['body']);
    $this->assertStringContainsString('Foundations of Fabrication - Cohort 10 starts Monday, January 11, 2027 at 7:00 PM.', $m['body']);
    $this->assertStringContainsString('https://x/civicrm/event/info?id=999&reset=1', $m['body']);
    $this->assertStringContainsString('click Following to stop: https://x/foundations-fabrication', $m['body']);
  }

  /**
   * @covers ::compose
   */
  public function testComposeWithoutDateStillReads(): void {
    $event = [
      'id' => 1,
      'title' => 'Intro to Sewing',
      'start' => '',
      'course_nid' => 1,
      'course_title' => 'Intro to Sewing',
      'course_type' => 'workshop',
    ];
    $m = CourseFollowerNotifier::compose('Sam', $event, 'https://x/e', 'https://x/c');
    $this->assertSame('Intro to Sewing: a new session is open', $m['subject']);
    $this->assertStringContainsString('It does: Intro to Sewing.', $m['body']);
  }

}
