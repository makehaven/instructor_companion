<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Commands;

use Drupal\instructor_companion\Service\InstructorStatusSync;
use Drush\Commands\DrushCommands;

/**
 * Active / inactive instructors from who has taught a class.
 */
final class InstructorStatusCommands extends DrushCommands {

  public function __construct(
    private readonly InstructorStatusSync $sync,
  ) {
    parent::__construct();
  }

  /**
   * Shows, or with --apply makes, the instructor status changes cron would.
   *
   * @command instructor-companion:status-sync
   * @aliases ic-status-sync
   * @option apply Save the changes instead of listing them.
   * @usage instructor-companion:status-sync
   *   List who would become active or inactive.
   * @usage instructor-companion:status-sync --apply
   *   Make the changes now.
   */
  public function statusSync(array $options = ['apply' => FALSE]): void {
    $apply = !empty($options['apply']);
    $months = $this->sync->months();
    if ($months === 0) {
      $this->io()->writeln('auto_status_months is 0: cron leaves instructor status alone. Showing what a 12-month rule would do.');
    }
    $changes = $this->sync->apply(!$apply);
    $this->io()->writeln(($apply ? 'Changed: ' : 'Would change: ') . count($changes));
    foreach ($changes as $c) {
      $this->io()->writeln(sprintf('  uid %d  %s  %s -> %s  (%s)', $c['uid'], $c['name'], $c['from'], $c['to'], $c['reason']));
    }
  }

}
