<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
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
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the console page.
   */
  public function build(): array {
    $now = \Drupal::time()->getRequestTime();

    $proposals = $this->pendingProposals();
    $interest = $this->unreviewedSubmissions('webform_14366', $now);
    $ideas = $this->unreviewedSubmissions('webform_497', $now);
    $signed = $this->agreementsSigned($now);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console']],
      '#attached' => ['library' => ['instructor_companion/education_console']],
      // Counts move whenever submissions or proposal drafts change; a short
      // TTL keeps the page honest without entity-type cache plumbing.
      '#cache' => ['max-age' => 300],
    ];

    $build['intro'] = [
      '#markup' => '<p class="education-console__intro">' . $this->t(
        'Everything instructor-related in one place. Work the list below top to
         bottom; each row links to the page where the decision happens. A weekly
         digest emails the education inbox about anything unreviewed for more
         than 7 days.'
      ) . '</p>',
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

    $build['links'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('More'),
      '#attributes' => ['class' => ['education-console__links']],
      '#items' => [
        $this->link($this->t('Prospective instructors (grant role / door access)'), Url::fromRoute('instructor_companion.prospective_instructors')),
        $this->link($this->t('Instructor dashboard (what instructors see)'), Url::fromRoute('instructor_companion.dashboard')),
        $this->link($this->t('Notification & email settings'), Url::fromRoute('instructor_companion.settings')),
      ],
    ];

    return $build;
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
    $storage = $this->entityTypeManager()->getStorage('civicrm_event');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('is_active', 0)
      ->condition('is_template', 0)
      ->exists('field_parent_course')
      ->sort('id', 'ASC')
      ->range(0, 100)
      ->execute();
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
