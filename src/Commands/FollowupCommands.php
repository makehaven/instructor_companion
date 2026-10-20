<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\instructor_companion\Service\AbandonedRegistrationService;
use Drupal\instructor_companion\Service\AttendeeFollowupService;
use Drupal\instructor_companion\Service\EventHygieneService;
use Drupal\instructor_companion\Service\InstructorProfileNudge;
use Drupal\instructor_companion\Service\PreClassReminderService;
use Drush\Commands\DrushCommands;

/**
 * Dry runs and one-time backfills for the follow-up loop.
 */
final class FollowupCommands extends DrushCommands {

  public function __construct(
    private readonly AttendeeFollowupService $followup,
    private readonly AbandonedRegistrationService $abandoned,
    private readonly EventHygieneService $hygiene,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly ?InstructorProfileNudge $profileNudge = NULL,
    private readonly ?PreClassReminderService $preclass = NULL,
  ) {
    parent::__construct();
  }

  /**
   * Shows which classes are due the post-class follow-up, or sends.
   *
   * Lists what each attendee would get.
   *
   * @command instructor-companion:followups
   * @aliases ic-followups
   * @option send Actually send (default: report only).
   * @option event Plan one event id regardless of its date (report only).
   * @usage instructor-companion:followups
   * @usage instructor-companion:followups --event=932
   * @usage instructor-companion:followups --send
   */
  public function followups(array $options = ['send' => FALSE, 'event' => NULL]): void {
    $now = $this->time->getRequestTime();
    $state = (array) $this->state->get(AttendeeFollowupService::STATE_KEY, []);
    $this->io()->writeln(sprintf('Enabled: %s · delay: %d day(s) · types: %s · seeded: %s',
      $this->followup->isEnabled() ? 'yes' : 'no',
      $this->followup->delayDays(),
      implode(',', $this->followup->eventTypes()),
      empty($state['_seeded']) ? 'NO (first run records due classes without sending)' : date('Y-m-d H:i', (int) $state['_seeded'])
    ));
    if (!empty($options['event'])) {
      $this->printPlan((int) $options['event'], '(forced)', $state, $now);
      return;
    }
    $due = $this->followup->dueClasses($now);
    if (!$due) {
      $this->io()->writeln('  no class reached its follow-up day in the current window.');
    }
    foreach ($due as $event_id => $row) {
      $done = isset($state['e' . $event_id]) ? 'already handled (' . (int) ($state['e' . $event_id]['sent'] ?? 0) . ' sent' . (!empty($state['e' . $event_id]['seeded']) ? ', seeded' : '') . ')' : 'DUE';
      $this->io()->writeln(sprintf('event %d "%s" ended %s: %s', $event_id, $row['title'], substr($row['ended'], 0, 16), $done));
      $this->printPlan($event_id, $row['title'], $state, $now);
    }
    if (!empty($options['send'])) {
      $n = $this->followup->run();
      $this->io()->success("Sent $n email(s).");
    }
  }

  /**
   * Prints the plan for one event.
   */
  private function printPlan(int $event_id, string $title, array $state, int $now): void {
    foreach ($this->followup->plan($event_id, $state, $now) as $p) {
      $flag = isset($state['p' . $p['participant_id']]) ? ' [already sent]' : '';
      $this->io()->writeln(sprintf('    %-6s %-28s participant %d  %s  (%s)%s',
        $p['kind'] ?? '—', $p['first_name'], $p['participant_id'], $p['email'], $p['reason'] . (isset($p['badge']) ? ': ' . $p['badge']['name'] : ''), $flag));
    }
  }

  /**
   * Lists stalled registrations that would be chased, or sends.
   *
   * @command instructor-companion:abandoned
   * @aliases ic-abandoned
   * @option send Actually send (default: report only).
   * @usage instructor-companion:abandoned
   * @usage instructor-companion:abandoned --send
   */
  public function abandoned(array $options = ['send' => FALSE]): void {
    $now = $this->time->getRequestTime();
    $state = (array) $this->state->get(AbandonedRegistrationService::STATE_KEY, []);
    $this->io()->writeln(sprintf('Enabled: %s · after: %d h · seeded: %s · stalled on upcoming classes: %d',
      $this->abandoned->isEnabled() ? 'yes' : 'no',
      $this->abandoned->afterHours(),
      empty($state['_seeded']) ? 'NO (first run records candidates without sending)' : date('Y-m-d H:i', (int) $state['_seeded']),
      $this->abandoned->openCount($now)
    ));
    $rows = $this->abandoned->candidates($now);
    foreach ($rows as $c) {
      $flag = isset($state['p' . $c['participant_id']]) || isset($state['c' . $c['contact_id'] . '-' . $c['event_id']]) ? ' [already sent]' : ' would send';
      $this->io()->writeln(sprintf('  %-24s %s  event %d "%s" on %s  (tried %s)%s',
        $c['first_name'], $c['email'], $c['event_id'], $c['title'], substr($c['start'], 0, 16), substr($c['registered'], 0, 16), $flag));
    }
    if (!$rows) {
      $this->io()->writeln('  nothing to chase right now.');
    }
    if (!empty($options['send'])) {
      $n = $this->abandoned->run();
      $this->io()->success("Sent $n email(s).");
    }
  }

  /**
   * Lists (or switches off) the cloned, superseded per-event reminders.
   *
   * @command instructor-companion:reminder-cleanup
   * @aliases ic-reminder-cleanup
   * @option apply Switch them off (default: list only).
   * @usage instructor-companion:reminder-cleanup
   * @usage instructor-companion:reminder-cleanup --apply
   */
  public function reminderCleanup(array $options = ['apply' => FALSE]): void {
    $rows = $this->hygiene->allClonedReminders();
    $this->io()->writeln(count($rows) . ' active per-event reminder(s) with a superseded title (' . implode(' | ', $this->hygiene->clonedReminderTitles()) . ')');
    foreach ($rows as $r) {
      $this->io()->writeln(sprintf('  schedule %d "%s" on event %d "%s" (%s)', $r['id'], $r['title'], $r['event_id'], $r['event_title'], substr($r['start'], 0, 10)));
    }
    if (!empty($options['apply']) && $rows) {
      $n = $this->hygiene->disable(array_column($rows, 'id'));
      $this->io()->success("Switched off $n reminder(s). The type-level 'Thanks for Attending!' still sends as every class ends.");
    }
  }

  /**
   * Lists (or sets) the waitlist offer window where it is missing.
   *
   * @command instructor-companion:waitlist-expiry
   * @aliases ic-waitlist-expiry
   * @option apply Set it (default: list only).
   * @usage instructor-companion:waitlist-expiry
   * @usage instructor-companion:waitlist-expiry --apply
   */
  public function waitlistExpiry(array $options = ['apply' => FALSE]): void {
    $hours = $this->hygiene->waitlistOfferHours();
    $rows = $this->hygiene->eventsMissingWaitlistExpiry();
    $this->io()->writeln(sprintf('Offer window: %s · %d waitlisted event(s)/template(s) without one', $hours > 0 ? "$hours h" : 'OFF (waitlist_offer_hours = 0)', count($rows)));
    foreach ($rows as $r) {
      $this->io()->writeln(sprintf('  %s %d "%s" %s', $r['is_template'] ? 'template' : 'event   ', $r['id'], $r['title'], substr($r['start'], 0, 10)));
    }
    if (!empty($options['apply'])) {
      $n = $this->hygiene->backfillWaitlistExpiry();
      $this->io()->success("expiration_time set to $hours h on $n row(s). CiviCRM's 'Update Participant Statuses' job now expires unpaid offers and moves to the next person.");
    }
  }

  /**
   * Lists active instructors with an incomplete public page, or nudges them.
   *
   * @command instructor-companion:profile-nudge
   * @aliases ic-profile-nudge
   * @option send Actually send (default: report only).
   * @usage instructor-companion:profile-nudge
   * @usage instructor-companion:profile-nudge --send
   */
  public function profileNudge(array $options = ['send' => FALSE]): void {
    if (!$this->profileNudge) {
      $this->io()->error('Profile nudge service unavailable.');
      return;
    }
    $rows = $this->profileNudge->incomplete();
    $due = $this->profileNudge->due();
    $this->io()->writeln(sprintf('%d active instructor(s) with an incomplete page; %d due a nudge (not emailed in %d days)', count($rows), count($due), InstructorProfileNudge::REPEAT_DAYS));
    foreach ($rows as $r) {
      $this->io()->writeln(sprintf('  %-26s %-34s missing %-14s %s', $r['name'], $r['email'], implode('+', $r['missing']), $r['last_nudged'] ? 'nudged ' . date('Y-m-d', $r['last_nudged']) : 'never nudged'));
    }
    if (!empty($options['send'])) {
      $n = $this->profileNudge->sendAll();
      $this->io()->success("Sent $n nudge(s).");
    }
  }

  /**
   * Lists instructor profiles missing a photo or bio that a proposal can fill.
   *
   * @command instructor-companion:fill-profiles
   * @aliases ic-fill-profiles
   * @option apply Fill them (default: list only).
   * @usage instructor-companion:fill-profiles
   * @usage instructor-companion:fill-profiles --apply
   */
  public function fillProfiles(array $options = ['apply' => FALSE]): void {
    $filler = \Drupal::service('instructor_companion.profile_filler');
    $n = 0;
    foreach ($filler->incompleteProfiles() as $profile) {
      $plan = $filler->plan($profile);
      if (!$plan) {
        continue;
      }
      $n++;
      $what = [];
      if (isset($plan['photo'])) {
        $what[] = 'photo from ' . $plan['photo']['source'] . ' (file ' . $plan['photo']['fid'] . ')';
      }
      if (isset($plan['bio'])) {
        $what[] = 'bio from proposal ' . $plan['bio']['sid'];
      }
      $this->io()->writeln(sprintf('  profile %d %-26s %s', $profile->id(), $profile->getOwner()->getDisplayName(), implode('; ', $what)));
      if (!empty($options['apply'])) {
        $filler->fill($profile);
      }
    }
    $this->io()->writeln($n ? "$n profile(s) " . (!empty($options['apply']) ? 'filled.' : 'can be filled (--apply).') : 'Nothing to fill.');
  }

  /**
   * Shows the pre-class reminders due now and the CiviCRM ones they replace.
   *
   * @command instructor-companion:preclass
   * @aliases ic-preclass
   * @option send Actually send, and switch off the replaced CiviCRM reminders
   *   (only when preclass_enabled is on; default: report only).
   * @option preview Print the composed email for the first due reminder.
   * @usage instructor-companion:preclass
   * @usage instructor-companion:preclass --preview
   * @usage instructor-companion:preclass --send
   */
  public function preclass(array $options = ['send' => FALSE, 'preview' => FALSE]): void {
    if (!$this->preclass) {
      $this->io()->error('Pre-class reminder service unavailable.');
      return;
    }
    $now = $this->time->getRequestTime();
    $state = (array) $this->state->get(PreClassReminderService::STATE_KEY, []);
    $this->io()->writeln(sprintf('Enabled: %s · types: %s · seeded: %s',
      $this->preclass->isEnabled() ? 'yes' : 'NO (CiviCRM reminders still run)',
      implode(',', $this->preclass->eventTypes()),
      empty($state['_seeded']) ? 'NO (first run records what CiviCRM already sent)' : date('Y-m-d H:i', (int) $state['_seeded'])
    ));
    $rows = $this->preclass->due($now);
    foreach ($rows as $r) {
      $this->io()->writeln(sprintf('  %-5s %-20s %-32s event %d "%s" %s%s',
        $r['stage'], $r['first_name'], $r['email'], $r['event_id'], $r['title'], substr($r['start'], 0, 16),
        isset($state[$r['key']]) ? ' [already sent]' : ''));
    }
    if (!$rows) {
      $this->io()->writeln('  no reminder due right now.');
    }
    if (!empty($options['preview']) && $rows) {
      $r = $rows[0];
      $message = PreClassReminderService::compose($r, [
        'week_subject' => '', 'week_body' => '', 'soon_subject' => '', 'soon_body' => '',
      ], '', $this->preclass->doorPassNote($r['type']), 'https://www.makehaven.org/visit');
      $this->io()->writeln("\n--- " . $message['subject'] . " ---\n" . $message['body']);
    }
    $retire = $this->preclass->civiRemindersToRetire();
    $this->io()->writeln(sprintf('%d active CiviCRM reminder(s) these replace%s:', count($retire), $this->preclass->isEnabled() ? '' : ' (switched off once enabled)'));
    foreach ($retire as $x) {
      $this->io()->writeln(sprintf('  #%d %-26s %s %d "%s"', $x['id'], $x['title'], $x['is_template'] ? 'template' : 'event', $x['event_id'], $x['event_title']));
    }
    if (!empty($options['send'])) {
      $n = $this->preclass->run();
      $this->io()->success("Sent $n reminder(s).");
    }
  }

}
