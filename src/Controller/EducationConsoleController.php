<?php

namespace Drupal\instructor_companion\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\InstructorApprovalGate;
use Drupal\instructor_companion\Service\PostEventStatusService;
use Drupal\instructor_companion\Service\ProposalHoldManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * One-page landing for the education team's instructor pipeline.
 *
 * The 2026-08 walkthrough found staff had to know four separate admin URLs
 * (interest queue, workshop-idea queue, pending session proposals,
 * prospective instructors) plus what distinguishes them. This console is the
 * single bookmark: count tiles with oldest-waiting ages up top, one merged
 * "needs attention" list below, links out to the existing detail pages.
 *
 * Webform queues use the same 90-day horizon as StaleReviewNudge — the
 * pre-queue backlog is archived history, not actionable work, and counting
 * it would make the tiles permanently alarming.
 */
class EducationConsoleController extends ControllerBase {

  /**
   * Days of history the "Badges owed after class" tile and list cover.
   *
   * Longer than the 30-day wrap-up window because an unissued badge blocks a
   * member for as long as it stays unissued; the summer 2026 metal classes
   * were 80 days old when staff noticed.
   */
  public const BADGES_OWED_DAYS = 180;

  /**
   * Seconds of history the webform tiles count (90 days, per StaleReviewNudge).
   */
  private const RECENT_HORIZON = 7776000;

  /**
   * Max rows in the merged needs-attention table.
   */
  private const LIST_LIMIT = 40;

  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
    protected ProposalHoldManager $holdManager,
    protected InstructorApprovalGate $approvalGate,
    protected PostEventStatusService $postEventStatus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('instructor_companion.proposal_hold_manager'),
      $container->get('instructor_companion.approval_gate'),
      $container->get('instructor_companion.post_event_status'),
    );
  }

  /**
   * Builds the console page.
   */
  public function build(): array {
    $now = \Drupal::time()->getRequestTime();

    // An unpublished proposal is either awaiting a staff decision or already
    // approved and held pending the instructor's onboarding — a held one
    // must NOT re-appear as "needs a decision" after staff decided it.
    $holds = $this->holdManager->allHolds();
    $all_proposals = $this->pendingProposals();
    $proposals = array_filter($all_proposals, fn($p) => !isset($holds[$p['id']]));
    $held = array_filter($all_proposals, fn($p) => isset($holds[$p['id']]));
    $interest = $this->unreviewedSubmissions('webform_14366', $now);
    $ideas = $this->unreviewedSubmissions('webform_497', $now);
    $signed = $this->agreementsSigned($now);
    $invited = \Drupal::service('instructor_companion.invite')->pending();
    $closeout = $this->postEventStatus->closeoutBacklog();
    // Badge-awarding classes whose attendees are still not checked out, over a
    // longer horizon than the wrap-up list: a member stays stuck until this
    // happens, so it must not scroll off after 30 days.
    $badges_owed = $this->postEventStatus->closeoutBacklog(self::BADGES_OWED_DAYS, 40, 0, 0, TRUE);
    $low_rated = $this->postEventStatus->lowRatedEvaluations();

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console']],
      '#attached' => ['library' => ['instructor_companion/education_console']],
      // Never cache: staff act (approve/deny/status) and come straight back —
      // even a short TTL shows them the row they just handled still "waiting"
      // (caught by the 2026-08-11 scenario simulation). The queries are cheap
      // and this is a low-traffic admin page.
      '#cache' => ['max-age' => 0],
    ];

    $build['intro'] = [
      '#markup' => '<p class="education-console__intro">' . $this->t(
        'Everything instructor-related in one place. Work the list below top to
         bottom; each row links to the page where the decision happens. A weekly
         digest emails the education inbox about anything unreviewed for more
         than 7 days.'
      ) . '</p>',
    ];

    // The one action that starts with staff rather than with a form: bringing
    // in someone they have already met. Everything below it is reactive.
    $build['invite_cta'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__cta']],
      'button' => [
        '#type' => 'link',
        '#title' => $this->t('Invite an instructor'),
        '#url' => Url::fromRoute('instructor_companion.invite_form', [], ['query' => ['destination' => '/admin/education']]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'hint' => [
        '#markup' => '<span class="education-console__cta-hint">' . $this->t(
          'For someone you have already talked to: one email, a link that signs
           them in and opens the agreement, role and dashboard on signing.'
        ) . '</span>',
      ],
    ];

    $oldest_line = function (array $timestamps) use ($now): ?string {
      $age = $this->oldestAge($timestamps, $now);
      return $age ? (string) $this->t('oldest: @age', ['@age' => $age]) : NULL;
    };

    $active_instructors = $this->activeInstructorCount();

    // Goal progress first: the queues below say what needs doing, this says
    // whether the programme is on track.
    $build['goals'] = $this->goalStrip();
    $build['goals']['#weight'] = -5;

    $build['tiles'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__tiles']],
      'roster' => $this->tile(
        $this->t('Active instructors'),
        $active_instructors,
        $this->t('the roster — approve, deactivate, fix profiles'),
        Url::fromRoute('instructor_companion.roster'),
        NULL,
        TRUE
      ),
      'proposals' => $this->tile(
        $this->t('Session proposals'),
        count($proposals),
        $this->t('draft sessions awaiting approve / deny'),
        Url::fromUserInput('/admin/structure/proposals'),
        $oldest_line(array_map(fn($p) => $p['created'], $proposals))
      ),
      'interest' => $this->tile(
        $this->t('Instructor interest'),
        count($interest),
        $this->t('unreviewed submissions (90 days)'),
        Url::fromRoute('instructor_companion.instructor_interest_queue'),
        $oldest_line(array_column($interest, 'created'))
      ),
      'ideas' => $this->tile(
        $this->t('Workshop ideas'),
        count($ideas),
        $this->t('unreviewed submissions (90 days)'),
        Url::fromRoute('instructor_companion.workshop_proposal_queue'),
        $oldest_line(array_column($ideas, 'created'))
      ),
      'invited' => $this->tile(
        $this->t('Invited, not yet signed'),
        count($invited),
        $this->t('agreement invites out (14-day links)'),
        Url::fromRoute('instructor_companion.education_console', [], ['fragment' => 'onboarding']),
        $invited ? (string) $this->t('oldest: @age', ['@age' => $this->age(min(array_map(fn($r) => (int) ($r['last_sent'] ?? $now), $invited)), $now)]) : NULL
      ),
      'closeout' => $this->tile(
        $this->t('Classes to close out'),
        count($closeout),
        $this->t('ended recently, wrap-up outstanding'),
        Url::fromRoute('instructor_companion.education_console', [], ['fragment' => 'closeout']),
        $closeout ? (string) $this->t('oldest: @age', ['@age' => $this->age((int) min(array_filter(array_column($closeout, 'ended'))), $now)]) : NULL
      ),
      'badges_owed' => $this->tile(
        $this->t('Badges owed after class'),
        count($badges_owed),
        $this->t('classes with students not checked out, last @days days', ['@days' => self::BADGES_OWED_DAYS]),
        Url::fromRoute('instructor_companion.education_console', [], ['fragment' => 'badges-owed']),
        $badges_owed ? (string) $this->t('oldest: @age', ['@age' => $this->age((int) min(array_filter(array_column($badges_owed, 'ended'))), $now)]) : NULL
      ),
      'low_rated' => $this->tile(
        $this->t('Evaluations to read'),
        count($low_rated),
        $this->t('rated @n or lower, last 90 days', ['@n' => PostEventStatusService::LOW_RATING]),
        Url::fromRoute('instructor_companion.education_console', [], ['fragment' => 'low-rated']),
        $low_rated ? (string) $this->t('newest: @age ago', ['@age' => $this->age((int) $low_rated[0]['created'], $now)]) : NULL
      ),
      'agreements' => $this->tile(
        $this->t('Agreements signed'),
        $signed['count'],
        $this->t('in the last 90 days'),
        Url::fromUserInput('/admin/structure/webform/manage/webform_5220/results/submissions'),
        $signed['newest'] ? (string) $this->t('newest: @age ago', ['@age' => $this->age($signed['newest'], $now)]) : NULL,
        TRUE
      ),
    ];

    $build['attention'] = $this->needsAttentionTable($proposals, $interest, $ideas, $now);

    // Who wants to hear about each program: the coordinator's outreach list.
    $build['program_interest'] = $this->programInterestTable();

    // Onboarding in progress — the same three lists as Prospective
    // Instructors, rendered here so nobody has to know a second page exists.
    $build['onboarding'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__onboarding'], 'id' => 'onboarding'],
      'heading' => ['#markup' => '<h2>' . $this->t('Instructor onboarding in progress') . '</h2>'],
      'note' => [
        '#markup' => '<p class="education-console__held-note">' . $this->t(
          'Invited people until they sign; then door access to approve (non-members
           only) and, for anyone signed before the role became automatic, the
           role to grant.'
        ) . '</p>',
      ],
    ] + ProspectiveInstructorsController::create(\Drupal::getContainer())->onboardingSections('/admin/education');

    if ($low_rated) {
      $build['low_rated'] = $this->lowRatedTable($low_rated, $now);
    }

    if ($badges_owed) {
      $build['badges_owed'] = $this->closeoutTable($badges_owed, $now, 'badges');
    }
    if ($closeout) {
      $build['closeout'] = $this->closeoutTable($closeout, $now);
    }
    $build['older_closeout'] = [
      '#type' => 'link',
      '#title' => $this->t('Older outstanding classes (more than 30 days ago) →'),
      '#url' => Url::fromRoute('instructor_companion.older_closeout'),
      '#attributes' => ['class' => ['button']],
    ];

    if ($held) {
      $build['held'] = $this->heldTable($held, $holds);
    }

    $build['links'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('More'),
      '#attributes' => ['class' => ['education-console__links']],
      '#items' => [
        $this->link($this->t('Workshops (upcoming classes by pace, demand turned away, what the automatic follow-ups did)'), Url::fromRoute('instructor_companion.workshops_console')),
        $this->link($this->t('Instructor roster (who is approved to teach, who actually does, whose public page is incomplete)'), Url::fromRoute('instructor_companion.roster')),
        $this->link($this->t('Prospective instructors (the onboarding lists above, plus members who said they would teach)'), Url::fromRoute('instructor_companion.prospective_instructors')),
        $this->link($this->t('Instructor dashboard (what instructors see)'), Url::fromRoute('instructor_companion.dashboard')),
        $this->link($this->t('Notification & email settings'), Url::fromRoute('instructor_companion.settings')),
      ],
    ];

    return $build;
  }

  /**
   * The Evaluations cell: how many responded, and whether any rated it low.
   *
   * Linked to the responses themselves — the free text is the useful part,
   * and a count nobody can click through to is not worth printing.
   */
  protected function evaluationCell(array $row): array {
    $eval = $row['evaluation'];
    $summary = $this->t('@n of @total', ['@n' => $eval['count'], '@total' => $row['attendees']]);

    if (!$eval['count']) {
      return ['#markup' => '<span class="education-console__eval education-console__eval--none">' . $summary . '</span>'];
    }

    $link = Url::fromRoute('entity.webform.results_submissions', ['webform' => PostEventStatusService::EVALUATION_WEBFORM], [
      'query' => ['search' => $row['event_id']],
    ]);
    $build = [
      'link' => [
        '#type' => 'link',
        '#title' => $summary,
        '#url' => $link,
      ],
    ];
    if ($eval['lowest'] !== NULL && $eval['lowest'] <= PostEventStatusService::LOW_RATING) {
      $build['flag'] = [
        '#markup' => ' <span class="education-console__eval--low">'
        . $this->t('⚠ lowest @n/5', ['@n' => $eval['lowest']]) . '</span>',
      ];
    }
    elseif ($eval['average'] !== NULL) {
      $build['avg'] = ['#markup' => ' <span class="education-console__eval--avg">' . $this->t('avg @n/5', ['@n' => $eval['average']]) . '</span>'];
    }
    return $build;
  }

  /**
   * Evaluations that flag a problem, newest first.
   *
   * Separate from the close-out list on purpose: a class can be fully wrapped
   * up and still have gone badly, and those are the ones worth reading. Two
   * instructor no-shows in August 2026 were reported here and seen by nobody.
   */
  protected function lowRatedTable(array $rows, int $now): array {
    $table_rows = [];
    foreach ($rows as $row) {
      $title = $row['event_title'] !== '' ? $row['event_title'] : $this->t('(class not recorded)');
      $what = $row['event_id']
        ? [
          'data' => [
            '#type' => 'link',
            '#title' => $title,
            '#url' => Url::fromRoute('instructor_companion.post_event_hub', ['event_id' => $row['event_id']]),
          ],
        ]
        : $title;

      $comment = $row['comment'];
      if ($comment !== '' && mb_strlen($comment) > 180) {
        $comment = mb_substr($comment, 0, 180) . '…';
      }

      $table_rows[] = [
        'rating' => $this->t('@n/5', ['@n' => $row['rating']]),
        'what' => $what,
        'when' => $this->t('@age ago', ['@age' => $this->age($row['created'], $now)]),
        'comment' => $comment !== '' ? $comment : $this->t('(no comment left)'),
        'action' => [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Read the response'),
            '#url' => Url::fromRoute('entity.webform_submission.canonical', [
              'webform' => PostEventStatusService::EVALUATION_WEBFORM,
              'webform_submission' => $row['sid'],
            ]),
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__low-rated'], 'id' => 'low-rated'],
      'heading' => ['#markup' => '<h2>' . $this->t('Evaluations to read') . '</h2>'],
      'note' => [
        '#markup' => '<p class="education-console__held-note">' . $this->t(
          'Attendees who rated a class @n out of 5 or lower in the last 90 days.
           Volume is low enough that a per-class average means little, but a
           single low score with a comment usually says something real. Nobody
           is emailed about these — reading them is the whole mechanism.',
          ['@n' => PostEventStatusService::LOW_RATING]
        ) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'rating' => $this->t('Rated'),
          'what' => $this->t('Class'),
          'when' => $this->t('When'),
          'comment' => $this->t('What they said could be better'),
          'action' => $this->t(''),
        ],
        '#rows' => $table_rows,
        '#attributes' => ['class' => ['education-console__table', 'education-console__table--low-rated']],
      ],
      'all' => [
        '#type' => 'link',
        '#title' => $this->t('All event feedback →'),
        '#url' => Url::fromRoute('entity.webform.results_submissions', ['webform' => PostEventStatusService::EVALUATION_WEBFORM]),
      ],
    ];
  }

  /**
   * Staff action: re-send the post-class reminder for one class.
   */
  public function remindCloseout(int $event_id): RedirectResponse {
    $sent = \Drupal::service('instructor_companion.post_event_reminder')->remindNow($event_id);
    if ($sent) {
      $this->messenger()->addStatus($this->t('Reminder sent to the instructor.'));
    }
    else {
      $this->messenger()->addWarning($this->t('Nothing sent — the class has no instructor on it, that account has no email, or the wrap-up is already complete.'));
    }
    // ?destination (set by the console link) takes it from here.
    return $this->redirect('instructor_companion.education_console');
  }

  /**
   * Classes that ended with wrap-up outstanding.
   *
   * The same four steps the instructor sees on their post-event hub, for
   * every recent class at once, plus how many attendees returned the
   * participant survey. "Remind" re-sends the post-class email.
   */
  protected function closeoutTable(array $rows, int $now, string $mode = 'recent'): array {
    $older = $mode === 'older';
    $badges = $mode === 'badges';
    $table_rows = [];
    foreach ($rows as $row) {
      $remind_url = Url::fromRoute('instructor_companion.closeout_remind', ['event_id' => $row['event_id']]);
      $remind_url->setOption('query', [
        'token' => \Drupal::csrfToken()->get($remind_url->getInternalPath()),
        'destination' => $older ? '/admin/education/closeout/older' : '/admin/education',
      ]);

      $ticks = [];
      foreach ($row['status']['steps'] as $step) {
        if (!$step['applicable']) {
          continue;
        }
        $ticks[] = ($step['complete'] ? '✅ ' : '⬜ ') . $step['label'];
      }

      $table_rows[] = [
        'what' => [
          'data' => [
            '#type' => 'link',
            '#title' => $row['title'],
            '#url' => Url::fromRoute('instructor_companion.post_event_hub', ['event_id' => $row['event_id']]),
          ],
        ],
        'who' => $row['instructor'] ?: $this->t('(no instructor on the event)'),
        'ended' => $row['ended'] ? $this->t('@age ago', ['@age' => $this->age($row['ended'], $now)]) : '—',
        'progress' => $row['status']['progress'],
        'outstanding' => implode(' · ', $ticks),
        'evaluations' => ['data' => $this->evaluationCell($row)],
        'action' => [
          'data' => [
            '#type' => 'dropbutton',
            '#links' => array_filter([
              'remind' => ['title' => $this->t('Remind instructor'), 'url' => $remind_url],
              // Staff can run the class checkout themselves when the
              // instructor never will (the route allows staff as well).
              'checkout' => $badges ? [
                'title' => $this->t('Class checkout (issue badges)'),
                'url' => Url::fromRoute('instructor_companion.class_checkout', ['event_id' => $row['event_id']]),
              ] : NULL,
              'hub' => [
                'title' => $this->t('Open wrap-up page'),
                'url' => Url::fromRoute('instructor_companion.post_event_hub', ['event_id' => $row['event_id']]),
              ],
            ]),
          ],
        ],
      ];
    }

    if ($badges) {
      $heading = $this->t('Badges owed after class');
      $note = $this->t(
        'Badge-awarding classes from the last @days days where at least one
         student has neither been checked out by the instructor nor holds the
         badge. Until this happens the student cannot use the tool, so it is
         listed separately from the rest of the wrap-up. "Class checkout"
         opens the same page the instructor uses; a staff member can mark the
         students complete on their behalf.',
        ['@days' => self::BADGES_OWED_DAYS]
      );
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__closeout'], 'id' => $badges ? 'badges-owed' : 'closeout'],
      'heading' => ['#markup' => '<h2>' . ($badges ? $heading : ($older ? $this->t('Older outstanding classes') : $this->t('Classes to close out'))) . '</h2>'],
      'note' => [
        '#markup' => '<p class="education-console__held-note">' . ($badges ? $note : ($older ? $this->t(
          'Classes that ended more than 30 days ago with wrap-up still outstanding, newest first. Older records may predate task tracking: review the class before reminding the instructor. Classes with no counted participants are excluded.'
        ) : $this->t(
          'Classes from the last 30 days whose instructor still owes wrap-up.
           Classes nobody attended are left out. The instructor is emailed
           automatically after the class; "Remind instructor" sends it again.
           Evaluations counts the participant Event Feedback survey, which the
           attendees are asked for separately — a low number there is not the
           instructor\'s doing.'
        ))) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'what' => $this->t('Class'),
          'who' => $this->t('Instructor'),
          'ended' => $this->t('Ended'),
          'progress' => $this->t('Done'),
          'outstanding' => $this->t('Wrap-up steps'),
          'evaluations' => $this->t('Evaluations'),
          'action' => $this->t('Action'),
        ],
        '#rows' => $table_rows,
        '#empty' => $this->t('No outstanding classes in this list.'),
        '#attributes' => ['class' => ['education-console__table', 'education-console__table--closeout']],
      ],
    ];
  }

  /**
   * Lists older unfinished classes without an expiry horizon.
   */
  public function olderCloseout(Request $request): array {
    $page = max(0, $request->query->getInt('page'));
    $page_size = 20;
    $rows = $this->postEventStatus->closeoutBacklog(0, $page_size + 1, 30, $page * $page_size);
    $build = [
      '#attached' => ['library' => ['instructor_companion/education_console']],
      '#cache' => ['max-age' => 0],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('← Back to Education'),
        '#url' => Url::fromRoute('instructor_companion.education_console'),
      ],
      'classes' => $this->closeoutTable(array_slice($rows, 0, $page_size), \Drupal::time()->getRequestTime(), 'older'),
    ];
    if ($page > 0) {
      $build['previous'] = [
        '#type' => 'link',
        '#title' => $this->t('← Previous page'),
        '#url' => Url::fromRoute('instructor_companion.older_closeout', [], ['query' => ['page' => $page - 1]]),
        '#attributes' => ['class' => ['button']],
      ];
    }
    if (count($rows) > $page_size) {
      $build['next'] = [
        '#type' => 'link',
        '#title' => $this->t('Next page →'),
        '#url' => Url::fromRoute('instructor_companion.older_closeout', [], ['query' => ['page' => $page + 1]]),
        '#attributes' => ['class' => ['button']],
      ];
    }
    return $build;
  }

  /**
   * One count tile.
   *
   * Workload tiles read green at zero ("nothing waiting"); tiles where the
   * count is the good news ($count_is_good) read green when positive.
   */
  /**
   * One row per program: interest-list size, followers, and where to mail it.
   *
   * JR (2026-09-14): "make it easy for our coordinator to reach out and invite
   * people to join the program when it is going to run." The CiviCRM group
   * link opens the contacts; Send a mailing goes to CiviMail with that group
   * as the audience.
   */
  protected function programInterestTable(): array {
    $rows = [];
    /** @var \Drupal\instructor_companion\Service\CourseInterestGroups $groups */
    $groups = \Drupal::service('instructor_companion.course_interest_groups');
    /** @var \Drupal\instructor_companion\Service\CourseFollowerNotifier $notifier */
    $notifier = \Drupal::service('instructor_companion.course_follower_notifier');
    $nids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'course')
      ->condition('status', 1)
      ->condition('field_course_type', 'program')
      ->condition('field_publicly_listed', 1)
      ->sort('title')
      ->execute();
    foreach (\Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids) as $node) {
      $nid = (int) $node->id();
      $followers = count($notifier->followers($nid));
      try {
        $group_count = $groups->count($nid);
        $group_url = $groups->groupUrl($nid);
      }
      catch (\Throwable $e) {
        $group_count = 0;
        $group_url = NULL;
      }
      $upcoming = (int) ($node->get('field_stat_upcoming')->value ?? 0);
      $mode = $node->hasField('field_offering_mode') ? (string) ($node->get('field_offering_mode')->value ?? '') : '';
      $mode_labels = [
        'public_schedule' => $this->t('public schedule'),
        'interest_list' => $this->t('interest list'),
        'partner_groups' => $this->t('for partner groups'),
        'partner_delivered' => $this->t('running with a partner'),
        'paused' => $this->t('not offered'),
      ];
      $last = (string) ($node->get('field_stat_last_run')->value ?? '');
      $actions = [
        '#type' => 'container',
        'page' => ['#type' => 'link', '#title' => $this->t('Program page'), '#url' => $node->toUrl(), '#attributes' => ['class' => ['button', 'button--small']]],
      ];
      if ($group_url) {
        $actions['group'] = ['#type' => 'link', '#title' => $this->t('Open list in CiviCRM'), '#url' => Url::fromUserInput($group_url), '#attributes' => ['class' => ['button', 'button--small']]];
        $actions['mail'] = ['#type' => 'link', '#title' => $this->t('Send a mailing'), '#url' => Url::fromUserInput('/civicrm/a/#/mailing/new'), '#attributes' => ['class' => ['button', 'button--small', 'button--primary'], 'title' => (string) $this->t('CiviMail: choose the "Program interest" group as the recipients')]];
      }
      $rows[] = [
        ['data' => ['#markup' => '<strong>' . htmlspecialchars($node->label()) . '</strong>']],
        ($upcoming > 0 ? $this->t('@n upcoming', ['@n' => $upcoming]) : ($last !== '' ? $this->t('last ran @d', ['@d' => substr($last, 0, 10)]) : $this->t('never run')))
        . ($mode !== '' ? ' · ' . ($mode_labels[$mode] ?? $mode) : ''),
        $group_count,
        $followers,
        ['data' => $actions],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__program-interest'], 'id' => 'program-interest'],
      'heading' => ['#markup' => '<h2>' . $this->t('Program interest lists') . '</h2>'],
      'note' => [
        '#markup' => '<p class="education-console__held-note">' . $this->t(
          'Everyone who asked to hear when a program next runs: members who pressed
           Notify Me on the program page and visitors who left an email. Each list is
           a CiviCRM group named "Program interest: …". When a cohort is published
           under the program, the site emails the whole list once; to invite people
           earlier or by hand, open the list in CiviCRM or send it a mailing.'
        ) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Program'), $this->t('Status · offering mode'), $this->t('Interest list'), $this->t('of which Notify Me'), $this->t('Actions')],
        '#rows' => $rows,
        '#empty' => $this->t('No published programs.'),
      ],
    ];
  }

  protected function tile($label, int $count, $sublabel, Url $url, ?string $age_line, bool $count_is_good = FALSE): array {
    $classes = ['education-console__tile'];
    if ($count_is_good ? $count > 0 : $count === 0) {
      $classes[] = 'education-console__tile--clear';
    }
    $oldest_markup = $age_line
      ? '<span class="education-console__tile-age">' . $age_line . '</span>'
      : '';
    return [
      '#type' => 'link',
      '#url' => $url,
      '#attributes' => ['class' => $classes],
      '#title' => [
        '#markup' => '<span class="education-console__tile-count">' . $count . '</span>'
        . '<span class="education-console__tile-label">' . $label . '</span>'
        . '<span class="education-console__tile-sub">' . $sublabel . '</span>'
        . $oldest_markup,
      ],
    ];
  }

  /**
   * The merged needs-attention table: proposals, then interest, then ideas.
   */
  protected function needsAttentionTable(array $proposals, array $interest, array $ideas, int $now): array {
    $rows = [];

    foreach ($proposals as $p) {
      $rows[] = [
        'type' => ['data' => ['#markup' => '<span class="ec-chip ec-chip--proposal">' . $this->t('Session proposal') . '</span>']],
        'what' => $p['title'],
        'who' => $p['who'],
        'age' => $p['created'] ? $this->age($p['created'], $now) : '—',
        'action' => [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Review & decide'),
            '#url' => Url::fromRoute('instructor_companion.proposal_review', ['event_id' => $p['id']]),
          ],
        ],
      ];
    }
    $groups = [
      [
        'rows' => $interest,
        'chip' => 'interest',
        'label' => $this->t('Interest'),
        'route' => 'instructor_companion.instructor_interest_queue',
      ],
      [
        'rows' => $ideas,
        'chip' => 'idea',
        'label' => $this->t('Workshop idea'),
        'route' => 'instructor_companion.workshop_proposal_queue',
      ],
    ];
    foreach ($groups as $group) {
      foreach ($group['rows'] as $s) {
        $rows[] = [
          'type' => ['data' => ['#markup' => '<span class="ec-chip ec-chip--' . $group['chip'] . '">' . $group['label'] . '</span>']],
          'what' => $s['title'],
          'who' => $s['who'],
          'age' => $this->age($s['created'], $now),
          'action' => [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('Open queue'),
              '#url' => Url::fromRoute($group['route']),
            ],
          ],
        ];
      }
    }

    $truncated = count($rows) > self::LIST_LIMIT;
    $rows = array_slice($rows, 0, self::LIST_LIMIT);

    $build['heading'] = ['#markup' => '<h2>' . $this->t('Needs attention') . '</h2>'];
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'type' => $this->t('Type'),
        'what' => $this->t('What'),
        'who' => $this->t('Who'),
        'age' => $this->t('Waiting'),
        'action' => $this->t('Action'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Nothing waiting — the pipeline is clear. 🎉'),
      '#attributes' => ['class' => ['education-console__table']],
    ];
    if ($truncated) {
      $build['more'] = [
        '#markup' => '<p class="education-console__truncated">' . $this->t(
          'Showing the first @n items — use the queue pages for the rest.',
          ['@n' => self::LIST_LIMIT]
        ) . '</p>',
      ];
    }
    return $build;
  }

  /**
   * Approved proposals waiting on the instructor's onboarding.
   *
   * These need no staff action — the session publishes itself the moment the
   * instructor finishes (ProposalHoldManager) — but staff should be able to
   * see what's in limbo and exactly which steps the instructor still owes.
   */
  protected function heldTable(array $held, array $holds): array {
    $rows = [];
    foreach ($held as $p) {
      $uid = (int) $holds[$p['id']];
      $missing = [];
      if ($this->approvalGate->isOrientationRequired() && !$this->approvalGate->hasOrientationBadge($uid)) {
        $missing[] = $this->t('orientation quiz');
      }
      if (!$this->approvalGate->hasSignedAgreement($uid)) {
        $missing[] = $this->t('agreement');
      }
      $rows[] = [
        'what' => $p['title'],
        'who' => $p['who'],
        // An empty list means the hold is about to release on the next
        // opportunistic check — call that out rather than showing nothing.
        'missing' => $missing ? implode(' + ', $missing) : (string) $this->t('nothing — releasing shortly'),
        'action' => [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('View details'),
            '#url' => Url::fromRoute('instructor_companion.proposal_review', ['event_id' => $p['id']]),
          ],
        ],
      ];
    }

    $build['heading'] = ['#markup' => '<h2>' . $this->t('Approved — waiting on instructor onboarding') . '</h2>'];
    $build['note'] = [
      '#markup' => '<p class="education-console__held-note">' . $this->t(
        'Each session publishes automatically when its instructor finishes the
         missing steps. The education team should contact the instructor if
         onboarding stalls or the session date is approaching; the instructor
         received the missing steps at approval.'
      ) . '</p>',
    ];
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'what' => $this->t('Session'),
        'who' => $this->t('Instructor'),
        'missing' => $this->t('Still missing'),
        'action' => $this->t('Details'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['education-console__table', 'education-console__table--held']],
    ];
    return $build;
  }

  /**
   * Pending session proposals.
   *
   * Same criteria as the pending_proposals view: unpublished, not a
   * template, has a parent course.
   *
   * @return array[]
   *   Rows of id, title, who, created (created falls back to NULL when the
   *   entity exposes no creation time).
   */
  protected function pendingProposals(): array {
    // Direct SQL, mirroring the pending_proposals view's joins: the
    // civicrm_event ENTITY query silently returns nothing for is_active=0
    // drafts (civicrm_entity storage quirk — verified 2026-08-11: entity
    // query 0 rows vs 20 by SQL on the same data). Do not "simplify" this
    // back to an entity query.
    $query = $this->database->select('civicrm_event', 'e');
    $query->join('civicrm_event__field_parent_course', 'pc', 'pc.entity_id = e.id');
    $query->addField('e', 'id');
    $query->condition('e.is_active', 0);
    $query->condition('e.is_template', 0);
    $query->orderBy('e.id', 'ASC');
    $query->range(0, 100);
    $ids = $query->execute()->fetchCol();

    $storage = $this->entityTypeManager()->getStorage('civicrm_event');
    $out = [];
    foreach ($storage->loadMultiple($ids) as $event) {
      $who = '';
      if ($event->hasField('field_civi_event_instructor') && !$event->get('field_civi_event_instructor')->isEmpty()) {
        $instructor = $event->get('field_civi_event_instructor')->entity;
        $who = $instructor ? $instructor->getDisplayName() : '';
      }
      // civicrm_entity exposes CiviCRM's created_date (not a Drupal
      // "created" timestamp); tolerate either or neither.
      $created = NULL;
      foreach (['created', 'created_date'] as $field) {
        if ($event->hasField($field) && !$event->get($field)->isEmpty()) {
          $value = $event->get($field)->value;
          $created = is_numeric($value) ? (int) $value : (strtotime((string) $value) ?: NULL);
          break;
        }
      }
      $out[] = [
        'id' => (int) $event->id(),
        'title' => $event->label(),
        'who' => $who,
        'created' => $created,
      ];
    }
    return $out;
  }

  /**
   * Unreviewed webform submissions within the recent horizon, oldest first.
   *
   * @return array[]
   *   Rows of sid, title, who, created.
   */
  protected function unreviewedSubmissions(string $webform_id, int $now): array {
    $query = $this->database->select('webform_submission', 'ws');
    $query->leftJoin('webform_submission_data', 'sd', "sd.sid = ws.sid AND sd.name = 'review_status_38'");
    $query->fields('ws', ['sid', 'created']);
    $query->condition('ws.webform_id', $webform_id);
    $query->condition('ws.in_draft', 0);
    $query->condition('ws.created', $now - self::RECENT_HORIZON, '>');
    $or = $query->orConditionGroup()
      ->isNull('sd.value')
      ->condition('sd.value', '');
    $query->condition($or);
    $query->orderBy('ws.created', 'ASC');
    $sids = $query->execute()->fetchAllKeyed(0, 1);
    if (!$sids) {
      return [];
    }

    $out = [];
    $storage = $this->entityTypeManager()->getStorage('webform_submission');
    foreach ($storage->loadMultiple(array_keys($sids)) as $submission) {
      $data = $submission->getData();
      // Both forms are usually submitted logged-out, so the owner is
      // "Anonymous" — the real name lives in a webform element whose key
      // carries a per-form suffix (name_6 on 14366, your_name_25 on 497).
      // Scan for name-shaped keys, then email-shaped ones, before falling
      // back to an authenticated owner.
      $who = '';
      foreach (['/^(?:your_)?name(?:_\d+)?$/', '/^first_name(?:_\d+)?$/', '/^email(?:_\d+)?$/'] as $pattern) {
        foreach ($data as $key => $value) {
          if (is_string($value) && trim($value) !== '' && preg_match($pattern, $key)) {
            $who = trim($value);
            break 2;
          }
        }
      }
      if ($who === '' && $submission->getOwnerId() && $submission->getOwner()) {
        $who = $submission->getOwner()->getDisplayName();
      }
      if ($who === '') {
        $who = (string) $this->t('—');
      }
      $title = $data['proposed_class_title'] ?? $data['proposed_class_title_26']
        ?? $data['areas_of_interest_skill'] ?? $data['areas_of_interest_skill_6']
        ?? (string) $this->t('(submission @sid)', ['@sid' => $submission->id()]);
      $out[] = [
        'sid' => (int) $submission->id(),
        'title' => is_array($title) ? implode(', ', $title) : (string) $title,
        'who' => $who,
        'created' => (int) $submission->getCreatedTime(),
      ];
    }
    return $out;
  }

  /**
   * Recent agreement signings (webform_5220) within the recent horizon.
   *
   * The team tracks NEW signings, not the historical unsigned backlog —
   * a backlog count would sit permanently alarming on the console.
   *
   * @return array
   *   'count' of completed submissions and 'newest' created timestamp
   *   (NULL when none).
   */
  protected function agreementsSigned(int $now): array {
    $query = $this->database->select('webform_submission', 'ws');
    $query->condition('ws.webform_id', 'webform_5220');
    $query->condition('ws.in_draft', 0);
    $query->condition('ws.created', $now - self::RECENT_HORIZON, '>');
    $query->addExpression('COUNT(ws.sid)', 'n');
    $query->addExpression('MAX(ws.created)', 'newest');
    $row = $query->execute()->fetchAssoc();
    return [
      'count' => (int) ($row['n'] ?? 0),
      'newest' => $row['newest'] ? (int) $row['newest'] : NULL,
    ];
  }

  /**
   * Formats the age of the oldest timestamp, or NULL when none exist.
   */
  protected function oldestAge(array $timestamps, int $now): ?string {
    $timestamps = array_filter($timestamps);
    return $timestamps ? $this->age(min($timestamps), $now) : NULL;
  }

  /**
   * Formats a timestamp's age as a single-unit interval ("3 weeks").
   */
  protected function age(int $timestamp, int $now): string {
    return (string) $this->dateFormatter->formatInterval(max(0, $now - $timestamp), 1);
  }

  /**
   * Builds a link render array.
   */
  protected function link($title, Url $url): array {
    return ['#type' => 'link', '#title' => $title, '#url' => $url];
  }

  /**
   * How many instructor profiles are staff-approved on a live account.
   *
   * Reads field_instructor_status = active. Cheap; feeds the roster tile.
   */
  protected function activeInstructorCount(): int {
    try {
      $q = $this->database->select('profile', 'p');
      $q->join('profile__field_instructor_status', 's', 's.entity_id = p.profile_id');
      $q->join('users_field_data', 'u', 'u.uid = p.uid');
      $q->condition('p.type', 'instructor')->condition('p.status', 1)
        ->condition('u.status', 1)
        ->condition('s.field_instructor_status_value', 'active');
      return (int) $q->countQuery()->execute()->fetchField();
    }
    catch (\Throwable $e) {
      return 0;
    }
  }


  /**
   * Target workshop sessions per month, from the 2026 budget's unit model
   * (144 runs a year). Actual has run at about 9.
   */
  protected const SESSIONS_TARGET_PER_MONTH = 12;

  /**
   * Workshop capacity utilisation goal, from the education KPI set.
   */
  protected const FILL_RATE_GOAL = 0.70;

  /**
   * Attendee seats a year that may carry no payment record before it is worth
   * a look. Coverage has sat at 92-94% since 2024 on roughly 450 seats, so a
   * handful is normal and a jump is not.
   */
  protected const UNPAID_SEATS_TOLERANCE = 45;

  /**
   * Clamp unlimited/placeholder capacities so a value such as 99 cannot
   * dominate the workshop fill KPI. Matches the education dashboard.
   */
  protected const WORKSHOP_CAPACITY_CAP = 25;

  /**
   * Builds the goal-progress strip shown above the work queues.
   *
   * The console answers "what needs doing". This answers "are we on track",
   * using the few numbers the manager can actually move: how many sessions are
   * on the calendar, how much demand is being turned away, how full the rooms
   * are, and whether evaluations are coming back.
   *
   * Every figure degrades to an em dash rather than breaking the page: this
   * reads CiviCRM tables directly and a schema change should not take the
   * console down with it.
   */
  protected function goalStrip(): array {
    $now = new \DateTimeImmutable('now');
    $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
    $monthEnd = $monthStart->modify('+1 month');
    $ninetyAgo = $now->modify('-90 days');
    $yearStart = $now->modify('first day of January this year')->setTime(0, 0, 0);

    $sessions = $this->countWorkshopSessions($monthStart, $monthEnd);
    $waitlist = $this->countWaitlisted($yearStart);
    $fill = $this->fillRate($ninetyAgo, $now);
    $evals = $this->evaluationResponseRate($ninetyAgo, $now);
    $unpaid = $this->seatsWithoutPaymentRecord($yearStart, $now);

    $pace = $sessions === NULL
      ? NULL
      : $sessions . ' / ' . self::SESSIONS_TARGET_PER_MONTH;

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__goals']],
      'heading' => [
        '#markup' => '<h2>' . $this->t('On track?') . '</h2>',
      ],
      'note' => [
        '#markup' => '<p class="education-console__goals-note">'
        . $this->t('Progress against the education goals. The queues below are what needs doing; these are whether it is adding up.')
        . '</p>',
      ],
      'tiles' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['education-console__tiles']],
        'waitlist' => $this->goalTile(
          $this->t('People waitlisted (workshops)'),
          $waitlist === NULL ? NULL : (string) $waitlist,
          $this->t('turned away from Ticketed Workshops this year — each one is a class worth scheduling'),
          Url::fromRoute('instructor_companion.workshops_console', [], ['fragment' => 'demand']),
          $waitlist !== NULL && $waitlist > 0 ? 'attention' : 'clear'
        ),
        'sessions' => $this->goalTile(
          $this->t('Workshop sessions this month'),
          $pace,
          $this->t('Ticketed Workshops, against the @n a month the budget assumes', ['@n' => self::SESSIONS_TARGET_PER_MONTH]),
          Url::fromRoute('instructor_companion.workshops_console'),
          $sessions === NULL ? NULL : ($sessions >= self::SESSIONS_TARGET_PER_MONTH ? 'clear' : 'attention')
        ),
        'fill' => $this->goalTile(
          $this->t('Workshop seats filled'),
          $fill === NULL ? NULL : round($fill * 100) . '%',
          $this->t('of capacity, Ticketed Workshops only (not meetups, programs or Build & Badge), last 90 days — goal @n%', ['@n' => round(self::FILL_RATE_GOAL * 100)]),
          Url::fromUserInput('/makerspace-dashboard/education'),
          $fill === NULL ? NULL : ($fill >= self::FILL_RATE_GOAL ? 'clear' : 'attention')
        ),
        'evaluations' => $this->goalTile(
          $this->t('Workshop evaluations returned'),
          $evals === NULL ? NULL : round($evals * 100) . '%',
          $this->t('of seats, last 90 days — the only quality signal there is'),
          Url::fromUserInput('/makerspace-dashboard/education'),
          $evals === NULL ? NULL : ($evals >= 0.20 ? 'clear' : 'attention')
        ),
        'unpaid' => $this->goalTile(
          $this->t('Workshop seats with no payment linked'),
          $unpaid === NULL ? NULL : (string) $unpaid,
          $this->t('registrations this year with no CiviCRM payment attached — comped, paid offline, or a link the system never wrote; the list says who'),
          Url::fromRoute('instructor_companion.workshops_unpaid'),
          $unpaid === NULL ? NULL : ($unpaid > self::UNPAID_SEATS_TOLERANCE ? 'attention' : 'clear')
        ),
      ],
      'waitlist_table' => $this->waitlistByCourseTable($yearStart),
      'more' => [
        '#type' => 'link',
        '#title' => $this->t('Full education dashboard — KPIs, trends and demographics →'),
        '#url' => Url::fromUserInput('/makerspace-dashboard/education'),
        '#attributes' => ['class' => ['education-console__goals-link']],
      ],
    ];
  }

  /**
   * Attendee seats this year carrying no payment record at all.
   *
   * Not a revenue figure and not an alarm: a comped or scholarship seat
   * legitimately has no contribution. The point is that each one should be a
   * deliberate choice rather than a registration nobody chased. Coverage has
   * held around 92-94% for three years, so a sharp move is the signal, not the
   * level.
   *
   * EXISTS rather than a join — a participant can carry several payment rows,
   * and joining would count them more than once.
   */
  protected function seatsWithoutPaymentRecord(\DateTimeImmutable $start, \DateTimeImmutable $end): ?int {
    try {
      $eventTypeId = $this->workshopTypeId();
      if (!$eventTypeId) {
        return NULL;
      }
      $query = $this->database->select('civicrm_participant', 'p');
      $query->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
      $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
      $query->condition('pst.is_counted', 1);
      $query->condition('p.is_test', 0);
      $query->condition('p.role_id', '1');
      $query->condition('e.is_active', 1);
      $query->condition('e.is_template', 0);
      $query->condition('e.event_type_id', $eventTypeId);
      $query->condition('e.start_date', [
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
      ], 'BETWEEN');

      $paid = $this->database->select('civicrm_participant_payment', 'pp');
      $paid->addField('pp', 'participant_id');
      $paid->where('pp.participant_id = p.id');
      $query->notExists($paid);

      return (int) $query->countQuery()->execute()->fetchField();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * One goal tile. $value of NULL renders an em dash rather than a zero.
   */
  protected function goalTile($label, ?string $value, $sublabel, Url $url, ?string $state): array {
    $classes = ['education-console__tile', 'education-console__tile--goal'];
    if ($state === 'clear') {
      $classes[] = 'education-console__tile--clear';
    }
    elseif ($state === 'attention') {
      $classes[] = 'education-console__tile--attention';
    }
    return [
      '#type' => 'link',
      '#url' => $url,
      '#attributes' => ['class' => $classes],
      '#title' => [
        '#markup' => '<span class="education-console__tile-count">' . ($value ?? '&mdash;') . '</span>'
        . '<span class="education-console__tile-label">' . $label . '</span>'
        . '<span class="education-console__tile-sub">' . $sublabel . '</span>',
      ],
    ];
  }

  /**
   * The id of the CiviCRM "Ticketed Workshop" event type, or NULL.
   */
  protected function workshopTypeId(): ?int {
    try {
      $id = $this->database->query(
        "SELECT ov.value FROM {civicrm_option_value} ov
         INNER JOIN {civicrm_option_group} og ON og.id = ov.option_group_id AND og.name = 'event_type'
         WHERE ov.label = 'Ticketed Workshop' LIMIT 1"
      )->fetchField();
      return $id === FALSE ? NULL : (int) $id;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Workshop sessions starting in a window.
   */
  protected function countWorkshopSessions(\DateTimeImmutable $start, \DateTimeImmutable $end): ?int {
    $type = $this->workshopTypeId();
    if ($type === NULL) {
      return NULL;
    }
    try {
      return (int) $this->database->query(
        "SELECT COUNT(*) FROM {civicrm_event} e
         WHERE e.event_type_id = :type AND e.is_template = 0 AND e.is_active = 1
           AND e.start_date >= :start AND e.start_date < :end",
        [
          ':type' => $type,
          ':start' => $start->format('Y-m-d H:i:s'),
          ':end' => $end->format('Y-m-d H:i:s'),
        ]
      )->fetchField();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Registrations that could not get a seat, on events since a date.
   *
   * Statuses 7 and 9 are "On waitlist" and "Pending from waitlist".
   */
  protected function countWaitlisted(\DateTimeImmutable $since): ?int {
    $type = $this->workshopTypeId();
    if ($type === NULL) {
      return NULL;
    }
    try {
      return (int) $this->database->query(
        "SELECT COUNT(*) FROM {civicrm_participant} p
         INNER JOIN {civicrm_event} e ON e.id = p.event_id AND e.is_template = 0
           AND e.is_active = 1 AND e.event_type_id = :type
         WHERE p.is_test = 0 AND p.status_id IN (7, 9) AND e.start_date >= :since",
        [':type' => $type, ':since' => $since->format('Y-m-d H:i:s')]
      )->fetchField();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Seats sold as a share of capacity net of hosts, for classes in a window.
   *
   * Active, non-template workshops only, and the window is closed at both ends:
   * a future class with nobody registered yet is not a class that failed to fill,
   * and counting one drags the rate down for no reason.
   */
  protected function fillRate(\DateTimeImmutable $start, \DateTimeImmutable $end): ?float {
    $type = $this->workshopTypeId();
    if ($type === NULL) {
      return NULL;
    }
    try {
      $row = $this->database->query(
        "SELECT SUM(t.seats) AS seats,
                SUM(GREATEST(LEAST(t.cap, :capacity_cap) - t.staff, 0)) AS capacity FROM (
           SELECT e.id,
             COALESCE(e.max_participants, 0) AS cap,
             SUM(CASE WHEN rv.label = 'Attendee' AND sv.is_counted = 1 THEN 1 ELSE 0 END) AS seats,
             SUM(CASE WHEN rv.label <> 'Attendee' AND sv.is_counted = 1 THEN 1 ELSE 0 END) AS staff
           FROM {civicrm_event} e
           LEFT JOIN {civicrm_participant} p ON p.event_id = e.id AND p.is_test = 0
           LEFT JOIN {civicrm_participant_status_type} sv ON sv.id = p.status_id
           LEFT JOIN {civicrm_option_value} rv ON rv.value = p.role_id
             AND rv.option_group_id = (SELECT id FROM {civicrm_option_group} WHERE name = 'participant_role')
           WHERE e.event_type_id = :type AND e.is_template = 0 AND e.is_active = 1
             AND e.start_date >= :start AND e.start_date < :end
             AND COALESCE(e.max_participants, 0) > 0
           GROUP BY e.id, e.max_participants
         ) t",
        [
          ':type' => $type,
          ':capacity_cap' => self::WORKSHOP_CAPACITY_CAP,
          ':start' => $start->format('Y-m-d H:i:s'),
          ':end' => $end->format('Y-m-d H:i:s'),
        ]
      )->fetchAssoc();
      $capacity = (float) ($row['capacity'] ?? 0);
      return $capacity > 0 ? ((float) $row['seats']) / $capacity : NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Evaluation submissions as a share of seats taught in a window.
   */
  protected function evaluationResponseRate(\DateTimeImmutable $start, \DateTimeImmutable $end): ?float {
    $type = $this->workshopTypeId();
    if ($type === NULL) {
      return NULL;
    }
    try {
      $seats = (int) $this->database->query(
        "SELECT COUNT(*) FROM {civicrm_participant} p
         INNER JOIN {civicrm_event} e ON e.id = p.event_id AND e.is_template = 0
           AND e.is_active = 1 AND e.event_type_id = :type
           AND e.start_date >= :start AND e.start_date < :end
         INNER JOIN {civicrm_participant_status_type} sv ON sv.id = p.status_id AND sv.is_counted = 1
         INNER JOIN {civicrm_option_value} rv ON rv.value = p.role_id
           AND rv.option_group_id = (SELECT id FROM {civicrm_option_group} WHERE name = 'participant_role')
           AND rv.label = 'Attendee'
         WHERE p.is_test = 0",
        [
          ':type' => $type,
          ':start' => $start->format('Y-m-d H:i:s'),
          ':end' => $end->format('Y-m-d H:i:s'),
        ]
      )->fetchField();
      if ($seats <= 0) {
        return NULL;
      }
      $submissions = (int) $this->database->query(
        "SELECT COUNT(DISTINCT ws.sid)
         FROM {webform_submission} ws
         INNER JOIN {webform_submission_data} sd
           ON sd.sid = ws.sid AND sd.webform_id = ws.webform_id AND sd.name = 'event_id'
         INNER JOIN {civicrm_event} eval_event
           ON eval_event.id = CAST(sd.value AS UNSIGNED)
          AND eval_event.is_template = 0 AND eval_event.is_active = 1
          AND eval_event.event_type_id = :type
          AND eval_event.start_date >= :start_date AND eval_event.start_date < :end_date
         WHERE ws.webform_id = :form",
        [
          ':form' => 'webform_1181',
          ':type' => $type,
          ':start_date' => $start->format('Y-m-d H:i:s'),
          ':end_date' => $end->format('Y-m-d H:i:s'),
        ]
      )->fetchField();
      return $submissions / $seats;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Courses with people waiting, worst first — the scheduling action list.
   */
  protected function waitlistByCourseTable(\DateTimeImmutable $since): array {
    $type = $this->workshopTypeId();
    if ($type === NULL) {
      return [];
    }
    try {
      $rows = $this->database->query(
        "SELECT COALESCE(n.title, :unlinked) AS course,
                COUNT(*) AS waiting,
                COUNT(DISTINCT e.id) AS runs,
                MAX(e.start_date) AS latest
         FROM {civicrm_participant} p
         INNER JOIN {civicrm_event} e ON e.id = p.event_id AND e.is_template = 0
           AND e.is_active = 1 AND e.event_type_id = :type
         LEFT JOIN {civicrm_event__field_parent_course} pc ON pc.entity_id = e.id
         LEFT JOIN {node_field_data} n ON n.nid = pc.field_parent_course_target_id
         WHERE p.is_test = 0 AND p.status_id IN (7, 9) AND e.start_date >= :since
         GROUP BY n.nid, n.title
         ORDER BY waiting DESC
         LIMIT 8",
        [
          ':type' => $type,
          ':since' => $since->format('Y-m-d H:i:s'),
          ':unlinked' => (string) $this->t('Not linked to a course'),
        ]
      )->fetchAll();
    }
    catch (\Exception $e) {
      return [];
    }
    if (!$rows) {
      return [];
    }

    $table_rows = [];
    foreach ($rows as $row) {
      $table_rows[] = [
        $row->course,
        $row->waiting,
        $row->runs,
        $row->latest ? $this->dateFormatter->format(strtotime($row->latest), 'custom', 'j M Y') : '—',
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__waitlist']],
      'heading' => [
        '#markup' => '<h3>' . $this->t('Demand being turned away') . '</h3>',
      ],
      'note' => [
        '#markup' => '<p class="education-console__goals-note">'
        . $this->t('People who tried to register and could not get a seat. A course near the top with a recent run is one to schedule again — or to reprice.')
        . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Course'),
          $this->t('Waiting'),
          $this->t('Runs'),
          $this->t('Most recent run'),
        ],
        '#rows' => $table_rows,
        '#empty' => $this->t('Nobody is waiting for a seat.'),
      ],
    ];
  }

}
