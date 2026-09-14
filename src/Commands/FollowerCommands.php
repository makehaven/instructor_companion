<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Commands;

use Drupal\Core\State\StateInterface;
use Drupal\instructor_companion\Service\CourseFollowerNotifier;
use Drush\Commands\DrushCommands;

/**
 * Who follows a course, and what the follower notice would do.
 */
final class FollowerCommands extends DrushCommands {

  public function __construct(
    private readonly CourseFollowerNotifier $notifier,
    private readonly StateInterface $state,
  ) {
    parent::__construct();
  }

  /**
   * Lists the people following a course ("Notify Me" on its page).
   *
   * @param int $nid
   *   The course node id.
   *
   * @command instructor-companion:followers
   * @aliases ic-followers
   * @usage instructor-companion:followers 40290
   */
  public function followers(int $nid): void {
    $rows = $this->notifier->followers($nid);
    if (!$rows) {
      $this->io()->writeln("Course $nid: nobody is following it.");
      return;
    }
    $this->io()->writeln("Course $nid: " . count($rows) . ' follower(s)');
    foreach ($rows as $f) {
      $this->io()->writeln(sprintf('  uid %d  %s  %s', $f['uid'], $f['name'], $f['email']));
    }
  }

  /**
   * Shows which open events followers would be told about, or sends.
   *
   * @command instructor-companion:follower-notices
   * @aliases ic-follower-notices
   * @option send Actually send (default: report only).
   * @usage instructor-companion:follower-notices
   * @usage instructor-companion:follower-notices --send
   */
  public function notices(array $options = ['send' => FALSE]): void {
    $events = $this->notifier->openEvents();
    $state = (array) $this->state->get(CourseFollowerNotifier::STATE_KEY, []);
    $this->io()->writeln('Enabled: ' . ($this->notifier->isEnabled() ? 'yes' : 'no') . ' · seeded: ' . (empty($state['_seeded']) ? 'NO (first run records open events without sending)' : date('Y-m-d H:i', (int) $state['_seeded'])));
    foreach ($events as $id => $e) {
      $followers = count($this->notifier->followers($e['course_nid']));
      $done = isset($state[$id]) ? 'already notified (' . (int) ($state[$id]['sent'] ?? 0) . ' sent' . (!empty($state[$id]['seeded']) ? ', seeded' : '') . ')' : "would email $followers follower(s)";
      $this->io()->writeln(sprintf('  event %d %s "%s" ← course %d "%s" (%s): %s', $id, substr($e['start'], 0, 16), $e['title'], $e['course_nid'], $e['course_title'], CourseFollowerNotifier::noun($e['course_type']), $done));
    }
    if (!$events) {
      $this->io()->writeln('  no open future events linked to a course.');
    }
    if (!empty($options['send'])) {
      $n = $this->notifier->run();
      $this->io()->success("Sent $n notice(s).");
    }
  }

}
