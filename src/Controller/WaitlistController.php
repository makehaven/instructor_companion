<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\EventHygieneService;
use Drupal\instructor_companion\Service\WaitlistManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Waitlists: the staff view per event, staff removal, and the leave link.
 */
class WaitlistController extends ControllerBase {

  public function __construct(
    protected WaitlistManager $waitlist,
    protected EventHygieneService $hygiene,
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('instructor_companion.waitlist'),
      $container->get('instructor_companion.event_hygiene'),
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Title callback for the staff page.
   */
  public function eventTitle(int $event_id): string {
    $title = $this->database->select('civicrm_event', 'e')->fields('e', ['title'])->condition('e.id', $event_id)->execute()->fetchField();
    return (string) $this->t('Waitlist: @title', ['@title' => $title ?: $event_id]);
  }

  /**
   * Staff: everyone who has been on this event's waitlist, with Remove.
   */
  public function eventList(int $event_id): array {
    $event = $this->database->select('civicrm_event', 'e')
      ->fields('e', ['id', 'title', 'start_date', 'max_participants', 'has_waitlist', 'expiration_time'])
      ->condition('e.id', $event_id)->execute()->fetchAssoc();
    if (!$event) {
      throw new NotFoundHttpException();
    }
    $entries = $this->waitlist->entriesForEvent($event_id);
    $course = $this->waitlist->courseOf($event_id);
    $sold = (int) $this->database->query(
      'SELECT COUNT(*) FROM {civicrm_participant} p WHERE p.event_id = :e AND p.is_test = 0 AND p.status_id IN (1, 2, 5, 14, 15)',
      [':e' => $event_id]
    )->fetchField();

    $rows = [];
    foreach ($entries as $e) {
      $live = in_array($e['status_id'], [WaitlistManager::STATUS_WAITLIST, WaitlistManager::STATUS_OFFERED], TRUE);
      $state = $e['status'];
      if ($e['offered']) {
        $state = $this->t('Offered a seat, not yet paid');
      }
      $remove = $live ? [
        'data' => [
          '#type' => 'link',
          '#title' => $this->t('Remove'),
          '#url' => Url::fromRoute('instructor_companion.waitlist_remove', [
            'event_id' => $event_id,
            'participant' => $e['participant_id'],
          ]),
          '#attributes' => ['class' => ['button', 'button--small', 'button--danger']],
        ],
      ] : '';
      $rows[] = [
        'data' => [
          'who' => ['data' => ['#markup' => '<a href="/civicrm/contact/view?reset=1&cid=' . $e['contact_id'] . '">' . htmlspecialchars($e['name']) . '</a><br><span class="education-console__age">' . htmlspecialchars($e['email']) . '</span>']],
          'when' => $e['registered'] ? $this->dateFormatter->format($e['registered'], 'custom', 'j M Y, g:i a') : '—',
          'state' => $state,
          'action' => $remove,
        ],
        'class' => $live ? [] : ['education-console__row--muted'],
      ];
    }

    $window = (int) $event['expiration_time'];
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console']],
      '#attached' => ['library' => ['instructor_companion/education_console']],
      '#cache' => ['max-age' => 0],
      'crumb' => ['#markup' => '<p class="education-console__crumb"><a href="' . Url::fromRoute('instructor_companion.workshops_console')->toString() . '">← Workshops</a></p>'],
      'summary' => [
        '#markup' => '<p class="education-console__intro">' . $this->t(
          '@title, @when · @sold of @cap seats sold · @n on the list. @window',
          [
            '@title' => $event['title'],
            '@when' => $this->dateFormatter->format(strtotime($event['start_date']) ?: 0, 'custom', 'D j M Y, g:i a'),
            '@sold' => $sold,
            '@cap' => (int) $event['max_participants'] ?: '∞',
            '@n' => count(array_filter($entries, fn($e) => in_array($e['status_id'], [7, 9], TRUE))),
            '@window' => $window
              ? $this->t('An unpaid offer expires after @h hours and the seat goes to the next person.', ['@h' => $window])
              : $this->t('No offer window on this event: an unpaid offer holds the seat until someone acts (the nightly cron will set @h h).', ['@h' => $this->hygiene->waitlistOfferHours()]),
          ]
        ) . '</p>',
      ],
      'how' => $this->howItWorks($course),
      'table' => [
        '#type' => 'table',
        '#header' => [
          'who' => $this->t('Person'),
          'when' => $this->t('Joined the list'),
          'state' => $this->t('Now'),
          'action' => '',
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['education-console__table']],
        '#empty' => $this->t('Nobody has joined this waitlist.'),
      ],
    ];
    return $build;
  }

  /**
   * The explainer staff asked for (2026-09-22): what happens, and when.
   */
  public function howItWorks(?int $course_nid = NULL): array {
    $items = [
      $this->t('<strong>Joining.</strong> When a class is full, CiviCRM offers "join the waitlist" on the registration page and emails a confirmation. The list is per class date, not per course.'),
      $this->t('<strong>A seat frees.</strong> CiviCRM\'s "Update Participant Statuses" job (every cron) moves the first person in line to <em>Pending from waitlist</em> and emails them a link to pay. Nobody else is told.'),
      $this->t('<strong>They do not pay.</strong> With an offer window on the event, CiviCRM expires the offer after that many hours, emails them that it expired, and offers the seat to the next person. Without a window the seat stays held.'),
      $this->t('<strong>They take the class another day.</strong> When someone on a waitlist gets a counted seat on any run of the same course, their other waitlist entries for that course are cancelled automatically (no email).'),
      $this->t('<strong>A new run is scheduled.</strong> Everyone waitlisted, offered-and-unpaid or expired on earlier runs of the course, who never got a later seat, gets one "another run is open" email with a register link and a one-click "leave the waiting list" link.'),
      $this->t('<strong>Staff removal.</strong> The Remove button below cancels the entry with no email. Use it when someone tells you they are no longer interested.'),
    ];
    return [
      '#type' => 'details',
      '#title' => $this->t('How waitlists work'),
      '#open' => FALSE,
      'list' => ['#theme' => 'item_list', '#items' => $items],
    ];
  }

  /**
   * Staff: cancel one entry.
   */
  public function remove(int $event_id, int $participant, Request $request): RedirectResponse {
    $entries = array_filter(
      $this->waitlist->entriesForEvent($event_id),
      fn($e) => $e['participant_id'] === $participant && in_array($e['status_id'], [7, 9], TRUE)
    );
    if (!$entries) {
      $this->messenger()->addWarning($this->t('That entry is no longer on the waitlist.'));
    }
    else {
      $entry = reset($entries);
      $n = $this->waitlist->cancelParticipants([$participant], 'removed by ' . $this->currentUser()->getAccountName() . ' on /admin/education/workshops');
      if ($n) {
        $this->messenger()->addStatus($this->t('@name removed from the waitlist. No email was sent.', ['@name' => $entry['name']]));
      }
      else {
        $this->messenger()->addError($this->t('Could not update the entry; see the log.'));
      }
    }
    return new RedirectResponse(Url::fromRoute('instructor_companion.waitlist_event', ['event_id' => $event_id])->toString());
  }

  /**
   * Public: the signed leave link from the "another run is open" email.
   */
  public function leave(int $contact, int $course, string $hash): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mh-page', 'container', 'py-5']],
      '#cache' => ['max-age' => 0],
    ];
    if (!$this->waitlist->leaveHashValid($contact, $course, $hash)) {
      $build['msg'] = [
        '#markup' => '<h1>' . $this->t('That link is not valid') . '</h1><p>'
        . $this->t('It may have been copied incompletely. If you want to leave a waiting list, reply to the email you received and we will do it for you.')
        . '</p>',
      ];
      return $build;
    }
    $title = $this->database->select('node_field_data', 'n')->fields('n', ['title'])->condition('n.nid', $course)->execute()->fetchField() ?: $this->t('this course');
    $n = $this->waitlist->leave($contact, $course);
    $build['msg'] = [
      '#markup' => '<h1>' . $this->t('You are off the waiting list') . '</h1><p>'
      . ($n
        ? $this->t('We removed you from @n waiting list(s) for @course. You will not hear about it again unless you sign up for a future run yourself.', [
          '@n' => $n,
          '@course' => $title,
        ])
        : $this->t('You were not on a waiting list for @course any more, so nothing changed.', ['@course' => $title]))
      . '</p><p><a href="' . Url::fromUri('internal:/programs')->toString() . '">' . $this->t('See what else is coming up') . '</a></p>',
    ];
    return $build;
  }

}
