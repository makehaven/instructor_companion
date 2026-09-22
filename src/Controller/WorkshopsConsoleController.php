<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\AbandonedRegistrationService;
use Drupal\instructor_companion\Service\EventHygieneService;
use Drupal\instructor_companion\Service\WorkshopInsightsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The schedule as a worklist: /admin/education/workshops.
 *
 * The first sub-page of the education hub. It answers "which classes need a
 * decision this week" (behind pace, empty, waitlisted, offers unpaid, stalled
 * registrations, missing data) and "which courses should run again". It does
 * not replace /admin/workshops or the event audit — those stay up until this
 * page has proven itself.
 */
class WorkshopsConsoleController extends ControllerBase {

  public function __construct(
    protected WorkshopInsightsService $insights,
    protected AbandonedRegistrationService $abandoned,
    protected EventHygieneService $hygiene,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('instructor_companion.workshop_insights'),
      $container->get('instructor_companion.abandoned_registration'),
      $container->get('instructor_companion.event_hygiene'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the page.
   */
  public function build(): array {
    $now = \Drupal::time()->getRequestTime();
    $runs = $this->insights->upcomingRuns(60);
    $demand = $this->insights->demandTurnedAway(12);
    $sent = $this->insights->followupsSent(7);
    $abandoned_open = $this->abandoned->openCount($now);
    $missing_expiry = $this->hygiene->eventsMissingWaitlistExpiry();

    $behind = array_filter($runs, fn($r) => in_array($r['pace'], ['behind', 'empty'], TRUE));
    $waiting = array_sum(array_column($runs, 'waiting'));
    $offered = array_sum(array_column($runs, 'offered'));
    $sold = array_sum(array_column($runs, 'sold'));
    $cap = array_sum(array_column($runs, 'cap'));
    $gaps = array_filter($runs, fn($r) => $r['gaps']);
    // One count per event: a run listed under "waitlist offers never expire"
    // is also in the missing-expiry list, and must not count twice.
    $gap_ids = array_unique(array_merge(array_keys($gaps), array_column($missing_expiry, 'id')));

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console', 'education-console--workshops']],
      '#attached' => ['library' => ['instructor_companion/education_console']],
      '#cache' => ['max-age' => 0],
    ];
    $build['crumb'] = [
      '#markup' => '<p class="education-console__crumb">' . $this->link($this->t('← Education console'), Url::fromRoute('instructor_companion.education_console'))['#markup'] . '</p>',
    ];
    $build['intro'] = [
      '#markup' => '<p class="education-console__intro">' . $this->t(
        'The next 60 days of workshops as a worklist: which classes need a push or a decision, who is waiting for a seat, and what the automatic follow-ups did this week. Revenue and fill history live on the KPI dashboard.'
      ) . '</p>',
    ];

    $civi_events = Url::fromUri('internal:/civicrm/event/manage?reset=1');
    $build['tiles'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__tiles']],
      'runs' => $this->tile(
        $this->t('Upcoming classes'),
        count($runs),
        $this->t('next 60 days · @sold of @cap seats sold', ['@sold' => $sold, '@cap' => $cap]),
        $civi_events,
        TRUE
      ),
      'behind' => $this->tile($this->t('Need a push or a call'), count($behind), $this->t('under half full a week out, or empty two weeks out'), Url::fromRoute('instructor_companion.workshops_console', [], ['fragment' => 'runs'])),
      'waiting' => $this->tile($this->t('Waiting for a seat'), $waiting, $this->t('on upcoming classes · @o offered and unpaid', ['@o' => $offered]), Url::fromRoute('instructor_companion.workshops_console', [], ['fragment' => 'demand'])),
      'abandoned' => $this->tile($this->t('Stalled at payment'), $abandoned_open, $this->t('started registering, never paid, class still ahead'), Url::fromUri('internal:/report/events/pendingincomplete')),
      'gaps' => $this->tile($this->t('Missing data'), count($gap_ids), $this->t('no course, no instructor, or a waitlist that never expires'), Url::fromRoute('instructor_companion.workshops_console', [], ['fragment' => 'runs'])),
    ];

    $build['runs'] = $this->runsTable($runs, $now);
    $build['demand'] = $this->demandTable($demand);
    $build['waitlists'] = WaitlistController::create(\Drupal::getContainer())->howItWorks();
    $build['followups'] = $this->followupsBlock($sent, $missing_expiry);

    $build['links'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('The pages this one sits beside'),
      '#attributes' => ['class' => ['education-console__links']],
      '#items' => [
        $this->link($this->t('Workshops dashboard (legacy)'), Url::fromUri('internal:/admin/workshops')),
        $this->link($this->t('Event audit — upcoming (legacy)'), Url::fromUri('internal:/admin/event-audit/upcoming')),
        $this->link($this->t('Events pending incomplete (legacy)'), Url::fromUri('internal:/report/events/pendingincomplete')),
        $this->link($this->t('Manage events in CiviCRM'), $civi_events),
        $this->link($this->t('KPI dashboard — Education'), Url::fromUri('internal:/makerspace-dashboard/education')),
        $this->link($this->t('Follow-up settings (delay, texts, waitlist window)'), Url::fromRoute('instructor_companion.settings')),
      ],
    ];
    return $build;
  }

  /**
   * The upcoming-runs table.
   */
  protected function runsTable(array $runs, int $now): array {
    $labels = [
      'waitlist' => $this->t('Waitlist'),
      'full' => $this->t('Full'),
      'behind' => $this->t('Behind'),
      'watch' => $this->t('Watch'),
      'empty' => $this->t('Empty'),
      'on-track' => $this->t('On track'),
    ];
    $rows = [];
    foreach ($runs as $r) {
      $event_url = Url::fromUri('internal:/civicrm/event/manage/settings?reset=1&action=update&id=' . $r['id']);
      $seats = $r['cap'] > 0 ? $r['sold'] . ' / ' . $r['cap'] : (string) $r['sold'];
      $extras = [];
      $wl_url = Url::fromRoute('instructor_companion.waitlist_event', ['event_id' => $r['id']])->toString();
      if ($r['waiting']) {
        $extras[] = '<a href="' . $wl_url . '">' . $this->formatPlural($r['waiting'], '1 waiting', '@count waiting') . '</a>';
      }
      if ($r['offered']) {
        $extras[] = '<a href="' . $wl_url . '">' . $this->formatPlural($r['offered'], '1 offered, unpaid', '@count offered, unpaid') . '</a>';
      }
      if ($r['abandoned']) {
        $extras[] = $this->formatPlural($r['abandoned'], '1 stalled at payment', '@count stalled at payment');
      }
      if ($r['pay_later']) {
        $extras[] = $this->formatPlural($r['pay_later'], '1 pay later', '@count pay later');
      }
      $rows[] = [
        'data' => [
          'when' => $this->dateFormatter->format($r['start'], 'custom', 'D j M, g:i a') . ' <span class="education-console__age">' . $this->formatPlural(max($r['days_out'], 0), 'in 1 day', 'in @count days') . '</span>',
          'what' => ['data' => ['#markup' => $this->link($r['title'], $event_url)['#markup'] . ($r['sessions'] > 1 ? ' <span class="education-console__age">' . $this->formatPlural($r['sessions'], '1 session', '@count sessions') . '</span>' : '')]],
          'who' => $r['instructor'] ?? '—',
          'course' => $r['course'] !== '' ? $r['course'] : '—',
          'seats' => ['data' => ['#markup' => $seats . ($extras ? '<br><span class="education-console__age">' . implode(' · ', array_map('strval', $extras)) . '</span>' : '')]],
          'pace' => ['data' => ['#markup' => '<span class="education-console__pace education-console__pace--' . $r['pace'] . '">' . $labels[$r['pace']] . '</span>']],
          'gaps' => $r['gaps'] ? implode(', ', $r['gaps']) : '',
        ],
        'class' => $r['gaps'] ? ['education-console__row--gap'] : [],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'runs'],
      'heading' => ['#markup' => '<h3>' . $this->t('Upcoming classes') . '</h3>'],
      'note' => [
        '#markup' => '<p class="education-console__goals-note">' . $this->t(
          '<em>Behind</em> is under half full a week out; <em>Watch</em> is under a third full two weeks out. Registrations arrive early here — 60% come two or more weeks ahead and only 28% in the final week — so a class that is behind a week out rarely recovers on its own. Push it in the digest, ask the instructor, or cancel early enough to say so gracefully.'
        ) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'when' => $this->t('When'),
          'what' => $this->t('Class'),
          'who' => $this->t('Instructor'),
          'course' => $this->t('Course'),
          'seats' => $this->t('Seats'),
          'pace' => $this->t('Pace'),
          'gaps' => $this->t('Missing'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['education-console__table']],
        '#empty' => $this->t('No workshops scheduled in the next 60 days.'),
      ],
    ];
  }

  /**
   * Demand turned away, by course.
   */
  protected function demandTable(array $demand): array {
    $rows = [];
    foreach ($demand as $d) {
      $course = $d['course_nid']
        ? $this->link($d['course'], Url::fromUri('internal:/node/' . $d['course_nid']))['#markup']
        : $d['course'] . ' <span class="education-console__age">' . $this->t('(not linked to a course)') . '</span>';
      $rows[] = [
        'course' => ['data' => ['#markup' => $course]],
        'waiting' => $d['waiting'],
        'reseated' => $d['reseated'],
        'runs' => $d['runs'],
        'latest' => $d['latest'] ? $this->dateFormatter->format($d['latest'], 'custom', 'j M Y') : '—',
        'upcoming' => $d['upcoming'] ? $this->formatPlural($d['upcoming'], '1 scheduled', '@count scheduled') : ['data' => ['#markup' => '<span class="education-console__pace education-console__pace--behind">' . $this->t('none scheduled') . '</span>']],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'demand'],
      'heading' => ['#markup' => '<h3>' . $this->t('Demand turned away, last 12 months') . '</h3>'],
      'note' => [
        '#markup' => '<p class="education-console__goals-note">' . $this->t(
          'Distinct people who were waitlisted, offered a seat they did not take, or expired, per course — and how many of them later got a seat on another run. A course with many waiting, few re-seated and nothing scheduled is the cheapest revenue available. When a run of a course is published, everyone waitlisted on its earlier runs is emailed automatically.'
        ) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'course' => $this->t('Course'),
          'waiting' => $this->t('Turned away'),
          'reseated' => $this->t('Later got a seat'),
          'runs' => $this->t('Runs'),
          'latest' => $this->t('Most recent run'),
          'upcoming' => $this->t('Upcoming'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['education-console__table']],
        '#empty' => $this->t('Nobody has been turned away in the last year.'),
      ],
    ];
  }

  /**
   * What the crons did, and what still needs a one-time backfill.
   */
  protected function followupsBlock(array $sent, array $missing_expiry): array {
    $items = [
      $this->formatPlural($sent['join'], '1 join / tour offer to a non-member attendee', '@count join / tour offers to non-member attendees'),
      $this->formatPlural($sent['badge'], '1 "your badge is waiting" nudge to a member', '@count "your badge is waiting" nudges to members'),
      $this->formatPlural($sent['abandoned'], '1 "your seat is still open" email', '@count "your seat is still open" emails'),
      $this->formatPlural($sent['classes'], 'across 1 class that reached its follow-up day', 'across @count classes that reached their follow-up day'),
    ];
    if (!$sent['seeded']) {
      $items[] = $this->t('Not yet seeded: the first cron run records what is already past due without sending anything.');
    }
    $block = [
      '#type' => 'container',
      'heading' => ['#markup' => '<h3>' . $this->t('Automatic follow-ups, last 7 days') . '</h3>'],
      'list' => ['#theme' => 'item_list', '#items' => $items],
    ];
    if ($missing_expiry) {
      $names = array_map(fn($e) => $e['title'] . ($e['is_template'] ? ' (template)' : ''), array_slice($missing_expiry, 0, 8));
      $block['expiry'] = [
        '#markup' => '<p class="education-console__goals-note">' . $this->t(
          '@n waitlisted event(s) still have no offer window, so an unpaid waitlist offer holds the seat forever: @names@more. The nightly cron applies the @h-hour window; <code>drush ic-waitlist-expiry --apply</code> does it now.',
          [
            '@n' => count($missing_expiry),
            '@names' => implode(', ', $names),
            '@more' => count($missing_expiry) > 8 ? ' …' : '',
            '@h' => $this->hygiene->waitlistOfferHours(),
          ]
        ) . '</p>',
      ];
    }
    return $block;
  }

  /**
   * A count tile, same look as the console's.
   */
  protected function tile($label, int $count, $sublabel, Url $url, bool $count_is_good = FALSE): array {
    $classes = ['education-console__tile'];
    if ($count_is_good ? $count > 0 : $count === 0) {
      $classes[] = 'education-console__tile--clear';
    }
    return [
      '#type' => 'link',
      '#url' => $url,
      '#attributes' => ['class' => $classes],
      '#title' => [
        '#markup' => '<span class="education-console__tile-count">' . $count . '</span>'
        . '<span class="education-console__tile-label">' . $label . '</span>'
        . '<span class="education-console__tile-sub">' . $sublabel . '</span>',
      ],
    ];
  }

  /**
   * A rendered link as markup.
   */
  protected function link($title, Url $url): array {
    return ['#markup' => '<a href="' . $url->toString() . '">' . $title . '</a>'];
  }

  /**
   * Workshop registrations this year with no payment attached, one per row.
   */
  public function unpaid(Request $request): array {
    $now = \Drupal::time()->getRequestTime();
    $start = strtotime(date('Y-01-01 00:00:00', $now));
    $rows_data = $this->insights->seatsWithoutPayment($start, $now);
    $rows = [];
    foreach ($rows_data as $r) {
      $rows[] = [
        'when' => $this->dateFormatter->format($r['start'], 'custom', 'j M Y'),
        'what' => ['data' => ['#markup' => '<a href="/civicrm/event/manage/settings?reset=1&action=update&id=' . $r['event_id'] . '">' . htmlspecialchars($r['title']) . '</a>']],
        'who' => ['data' => ['#markup' => '<a href="/civicrm/contact/view?reset=1&cid=' . $r['contact_id'] . '">' . htmlspecialchars($r['name']) . '</a>']],
        'status' => $r['status'],
        'registered' => $r['registered'] ? $this->dateFormatter->format($r['registered'], 'custom', 'j M Y') : '—',
        'source' => $r['source'] !== '' ? $r['source'] : '—',
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console']],
      '#attached' => ['library' => ['instructor_companion/education_console']],
      '#cache' => ['max-age' => 0],
      'crumb' => ['#markup' => '<p class="education-console__crumb"><a href="' . Url::fromRoute('instructor_companion.education_console')->toString() . '">← Education console</a> · <a href="' . Url::fromRoute('instructor_companion.workshops_console')->toString() . '">Workshops</a></p>'],
      'intro' => [
        '#markup' => '<p class="education-console__intro">' . $this->t(
          'Counted Ticketed Workshop registrations since 1 January with no CiviCRM payment record attached. Each is one of three things: a seat that was comped or paid offline (fine, but it should be a known decision), a registration CiviCRM never linked to its contribution, or an attendee who owes. The "Source" column is what the registration form or staff wrote when creating it. Member-only Build & Badge classes are free and are not listed.'
        ) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'when' => $this->t('Class date'),
          'what' => $this->t('Class'),
          'who' => $this->t('Person'),
          'status' => $this->t('Status'),
          'registered' => $this->t('Registered'),
          'source' => $this->t('Source'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['education-console__table']],
        '#empty' => $this->t('Every workshop registration this year has a payment record.'),
      ],
    ];
  }

}
