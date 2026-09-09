<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\InstructorApprovalGate;
use Drupal\instructor_companion\Service\PostEventStatusService;
use Drupal\instructor_companion\Service\ProposalHoldManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

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

    $build['tiles'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__tiles']],
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
      'low_rated' => $this->tile(
        $this->t('Evaluations to read'),
        count($low_rated),
        $this->t('rated @n or lower, last 90 days', ['@n' => \Drupal\instructor_companion\Service\PostEventStatusService::LOW_RATING]),
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

    if ($closeout) {
      $build['closeout'] = $this->closeoutTable($closeout, $now);
    }

    if ($held) {
      $build['held'] = $this->heldTable($held, $holds);
    }

    $build['links'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('More'),
      '#attributes' => ['class' => ['education-console__links']],
      '#items' => [
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
  public function remindCloseout(int $event_id): \Symfony\Component\HttpFoundation\RedirectResponse {
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
  protected function closeoutTable(array $rows, int $now): array {
    $table_rows = [];
    foreach ($rows as $row) {
      $remind_url = Url::fromRoute('instructor_companion.closeout_remind', ['event_id' => $row['event_id']]);
      $remind_url->setOption('query', [
        'token' => \Drupal::csrfToken()->get($remind_url->getInternalPath()),
        'destination' => '/admin/education',
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
            '#links' => [
              'remind' => ['title' => $this->t('Remind instructor'), 'url' => $remind_url],
              'hub' => [
                'title' => $this->t('Open wrap-up page'),
                'url' => Url::fromRoute('instructor_companion.post_event_hub', ['event_id' => $row['event_id']]),
              ],
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__closeout'], 'id' => 'closeout'],
      'heading' => ['#markup' => '<h2>' . $this->t('Classes to close out') . '</h2>'],
      'note' => [
        '#markup' => '<p class="education-console__held-note">' . $this->t(
          'Classes from the last 30 days whose instructor still owes wrap-up.
           Classes nobody attended are left out. The instructor is emailed
           automatically after the class; "Remind instructor" sends it again.
           Evaluations counts the participant Event Feedback survey, which the
           attendees are asked for separately — a low number there is not the
           instructor\'s doing.'
        ) . '</p>',
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
        '#attributes' => ['class' => ['education-console__table', 'education-console__table--closeout']],
      ],
    ];
  }

  /**
   * One count tile.
   *
   * Workload tiles read green at zero ("nothing waiting"); tiles where the
   * count is the good news ($count_is_good) read green when positive.
   */
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
        'No action needed: each session publishes automatically the moment its
         instructor finishes the missing steps (they were emailed the list at
         approval).'
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

}
