<?php

namespace Drupal\instructor_companion\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\InstructorApprovalGate;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The instructor roster.
 *
 * Who may teach, who actually does, and whose public page is incomplete.
 *
 * The Education Console is built around queues — proposals, interest,
 * invites, close-outs — and had no view of the people already on the roster.
 * The public /instructors directory and the profile pages now lead with each
 * person's photo, bio and record (2026-09-14), which makes gaps visible to
 * visitors before staff; and `field_instructor_status`, the approval gate,
 * was only reachable by opening each profile's edit form. This page puts the
 * whole roster on one screen with the two actions staff take: approve /
 * deactivate, and fix the profile.
 */
class InstructorRosterController extends ControllerBase {

  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
    protected InstructorApprovalGate $gate,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('instructor_companion.approval_gate'),
    );
  }

  /**
   * Roster page.
   */
  public function build(Request $request): array {
    $filter = $request->query->get('show', 'all');
    $rows = $this->rosterRows();

    $counts = [
      'active' => 0,
      'taught_12mo' => 0,
      'active_never' => 0,
      'incomplete' => 0,
    ];
    foreach ($rows as $r) {
      if ($r['status'] === InstructorApprovalGate::STATUS_ACTIVE) {
        $counts['active']++;
        if ($r['sessions'] === 0) {
          $counts['active_never']++;
        }
        if (!$r['has_photo'] || !$r['has_bio']) {
          $counts['incomplete']++;
        }
      }
      if ($r['sessions_12mo'] > 0) {
        $counts['taught_12mo']++;
      }
    }

    $shown = array_filter($rows, static function ($r) use ($filter) {
      return match ($filter) {
        'active' => $r['status'] === InstructorApprovalGate::STATUS_ACTIVE,
        'inactive' => $r['status'] !== InstructorApprovalGate::STATUS_ACTIVE,
        'incomplete' => $r['status'] === InstructorApprovalGate::STATUS_ACTIVE && (!$r['has_photo'] || !$r['has_bio']),
        'never' => $r['status'] === InstructorApprovalGate::STATUS_ACTIVE && $r['sessions'] === 0,
        default => TRUE,
      };
    });

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console', 'education-roster']],
      '#attached' => ['library' => ['instructor_companion/education_console']],
      '#cache' => ['max-age' => 0],
    ];
    $build['intro'] = [
      '#markup' => '<p class="education-console__intro">' . $this->t(
        '<strong>Active</strong> means staff-approved to propose and teach; it is
         what the public <a href=":dir">/instructors</a> directory shows and what
         the approval gate checks. Nothing sets it automatically — you do, here.
         Photo and bio are now the first thing a visitor sees on an instructor\'s
         page, so the "incomplete" filter is worth a pass before a term starts.',
        [':dir' => Url::fromRoute('view.instructors.page_1')->toString()]
      ) . '</p>',
    ];

    $tile = function ($label, int $count, $sub, string $show, bool $good) {
      $classes = ['education-console__tile'];
      if ($good ? $count > 0 : $count === 0) {
        $classes[] = 'education-console__tile--clear';
      }
      return [
        '#type' => 'link',
        '#url' => Url::fromRoute('instructor_companion.roster', [], ['query' => ['show' => $show]]),
        '#attributes' => ['class' => $classes],
        '#title' => [
          '#markup' => '<span class="education-console__tile-count">' . $count . '</span>'
          . '<span class="education-console__tile-label">' . $label . '</span>'
          . '<span class="education-console__tile-sub">' . $sub . '</span>',
        ],
      ];
    };
    $build['tiles'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console__tiles']],
      'active' => $tile($this->t('Active instructors'), $counts['active'], $this->t('approved to teach; listed publicly'), 'active', TRUE),
      'taught' => $tile($this->t('Taught in the last 12 months'), $counts['taught_12mo'], $this->t('any status'), 'all', TRUE),
      'never' => $tile($this->t('Active, never taught'), $counts['active_never'], $this->t('approved but no session yet'), 'never', FALSE),
      'incomplete' => $tile($this->t('Active, incomplete profile'), $counts['incomplete'], $this->t('missing photo or bio'), 'incomplete', FALSE),
    ];

    $build['filters'] = [
      '#theme' => 'item_list',
      '#attributes' => ['class' => ['education-roster__filters']],
      '#items' => array_map(function ($pair) use ($filter) {
        [$key, $label] = $pair;
        return [
          '#type' => 'link',
          '#title' => $label,
          '#url' => Url::fromRoute('instructor_companion.roster', [], ['query' => ['show' => $key]]),
          '#attributes' => ['class' => $key === $filter ? ['is-active'] : []],
        ];
      }, [
        ['all', $this->t('All')],
        ['active', $this->t('Active')],
        ['inactive', $this->t('Inactive')],
        ['incomplete', $this->t('Incomplete profile')],
        ['never', $this->t('Never taught')],
      ]),
    ];

    $header = [
      $this->t('Instructor'),
      $this->t('Status'),
      $this->t('Profile'),
      $this->t('Topics'),
      $this->t('Sessions'),
      $this->t('Last taught'),
      $this->t('Upcoming'),
      $this->t('Agreement'),
      $this->t('Actions'),
    ];
    $table_rows = [];
    foreach ($shown as $r) {
      $is_active = $r['status'] === InstructorApprovalGate::STATUS_ACTIVE;
      $toggle = Url::fromRoute('instructor_companion.roster_status', [
        'profile' => $r['profile_id'],
        'status' => $is_active ? InstructorApprovalGate::STATUS_INACTIVE : InstructorApprovalGate::STATUS_ACTIVE,
      ], ['query' => ['show' => $filter]]);
      $status_cell = [
        'data' => [
          'badge' => [
            '#markup' => '<span class="ec-chip ' . ($is_active ? 'ec-chip--active' : 'ec-chip--inactive') . '">'
            . ($is_active ? $this->t('Active') : $this->t('Inactive')) . '</span> ',
          ],
          'toggle' => [
            '#type' => 'link',
            '#title' => $is_active ? $this->t('Deactivate') : $this->t('Approve'),
            '#url' => $toggle,
            '#attributes' => ['class' => ['education-roster__toggle']],
          ],
        ],
      ];
      $profile_bits = [];
      $profile_bits[] = $r['has_photo'] ? '✓ ' . $this->t('photo') : '<span class="education-roster__missing">✗ ' . $this->t('photo') . '</span>';
      $profile_bits[] = $r['has_bio'] ? '✓ ' . $this->t('bio') : '<span class="education-roster__missing">✗ ' . $this->t('bio') . '</span>';
      if ($r['private']) {
        $profile_bits[] = $this->t('private sessions');
      }
      $table_rows[] = [
        'data' => [
          [
            'data' => [
              '#type' => 'link',
              '#title' => $r['name'],
              '#url' => Url::fromRoute('entity.profile.canonical', ['profile' => $r['profile_id']]),
            ],
          ],
          $status_cell,
          ['data' => ['#markup' => implode(' · ', $profile_bits)]],
          implode(', ', $r['topics']),
          [
            'data' => [
              '#markup' => $r['sessions'] . ($r['sessions_12mo']
                ? ' <small>(' . $this->t('@n in 12 mo', ['@n' => $r['sessions_12mo']]) . ')</small>'
                : ''),
            ],
            'class' => ['education-roster__num'],
          ],
          $r['last_taught'] ? $this->dateFormatter->format($r['last_taught'], 'custom', 'M j, Y') : '—',
          ['data' => $r['upcoming'] ?: '—', 'class' => ['education-roster__num']],
          $r['agreement'] ?: '—',
          [
            'data' => [
              '#type' => 'operations',
              '#links' => [
                'edit' => [
                  'title' => $this->t('Edit profile'),
                  'url' => Url::fromRoute('entity.profile.edit_form', ['profile' => $r['profile_id']], [
                    'query' => ['destination' => '/admin/education/roster?show=' . $filter],
                  ]),
                ],
                'view' => [
                  'title' => $this->t('Public page'),
                  'url' => Url::fromRoute('entity.profile.canonical', ['profile' => $r['profile_id']]),
                ],
                'user' => [
                  'title' => $this->t('Account'),
                  'url' => Url::fromRoute('entity.user.canonical', ['user' => $r['uid']]),
                ],
              ],
            ],
          ],
        ],
        'class' => $is_active ? [] : ['education-roster__row--inactive'],
      ];
    }
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $table_rows,
      '#empty' => $this->t('No instructors match this filter.'),
      '#attributes' => ['class' => ['education-console__table', 'education-roster__table']],
    ];
    $build['back'] = [
      '#type' => 'link',
      '#title' => $this->t('← Education Console'),
      '#url' => Url::fromRoute('instructor_companion.education_console'),
      '#attributes' => ['class' => ['button']],
    ];
    return $build;
  }

  /**
   * Approve / deactivate: sets field_instructor_status and returns.
   */
  public function setStatus(ProfileInterface $profile, string $status, Request $request): RedirectResponse {
    $allowed = [InstructorApprovalGate::STATUS_ACTIVE, InstructorApprovalGate::STATUS_INACTIVE];
    if ($profile->bundle() !== 'instructor' || !in_array($status, $allowed, TRUE)) {
      throw new NotFoundHttpException();
    }
    $profile->set('field_instructor_status', $status);
    $profile->setNewRevision(TRUE);
    if (method_exists($profile, 'setRevisionLogMessage')) {
      $profile->setRevisionLogMessage('Instructor status set to ' . $status . ' from the roster by ' . $this->currentUser()->getDisplayName());
    }
    $profile->save();
    $name = $profile->getOwner() ? $profile->getOwner()->getDisplayName() : $profile->label();
    $this->messenger()->addStatus($status === InstructorApprovalGate::STATUS_ACTIVE
      ? $this->t('@name is now an active instructor: they can propose and teach, and appear on /instructors.', ['@name' => $name])
      : $this->t('@name is now inactive: no proposing or teaching, and hidden from /instructors.', ['@name' => $name]));
    return new RedirectResponse(Url::fromRoute('instructor_companion.roster', [], ['query' => ['show' => $request->query->get('show', 'all')]])->toString());
  }

  /**
   * One row per instructor profile, with teaching stats from CiviCRM events.
   *
   * @return array[]
   *   Sorted: active first, then most recently taught.
   */
  protected function rosterRows(): array {
    $storage = $this->entityTypeManager()->getStorage('profile');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'instructor')
      ->condition('status', 1)
      ->execute();
    $profiles = $ids ? $storage->loadMultiple($ids) : [];
    $uids = [];
    foreach ($profiles as $p) {
      if ($p->getOwnerId()) {
        $uids[] = (int) $p->getOwnerId();
      }
    }

    $stats = [];
    if ($uids && $this->database->schema()->tableExists('civicrm_event__field_civi_event_instructor')) {
      $now = date('Y-m-d H:i:s');
      $year_ago = date('Y-m-d H:i:s', strtotime('-12 months'));
      $q = $this->database->select('civicrm_event__field_civi_event_instructor', 'i');
      $q->join('civicrm_event', 'e', 'e.id = i.entity_id');
      $q->addField('i', 'field_civi_event_instructor_target_id', 'uid');
      $q->addExpression("SUM(e.start_date <= '$now')", 'sessions');
      $q->addExpression("SUM(e.start_date <= '$now' AND e.start_date >= '$year_ago')", 'sessions_12mo');
      $q->addExpression("SUM(e.start_date > '$now' AND e.is_active = 1)", 'upcoming');
      $q->addExpression("MAX(CASE WHEN e.start_date <= '$now' THEN e.start_date END)", 'last_taught');
      $q->condition('i.field_civi_event_instructor_target_id', $uids, 'IN')
        ->condition('e.is_template', 0)
        ->groupBy('i.field_civi_event_instructor_target_id');
      foreach ($q->execute() as $s) {
        $stats[(int) $s->uid] = $s;
      }
    }

    $rows = [];
    foreach ($profiles as $p) {
      $owner = $p->getOwner();
      if (!$owner) {
        continue;
      }
      $uid = (int) $owner->id();
      $s = $stats[$uid] ?? NULL;
      $first = $owner->hasField('field_first_name') ? trim((string) $owner->get('field_first_name')->value) : '';
      $last = $owner->hasField('field_last_name') ? trim((string) $owner->get('field_last_name')->value) : '';
      $name = trim($first . ' ' . $last) ?: $owner->getDisplayName();
      $topics = [];
      if ($p->hasField('field_instructor_topics')) {
        foreach ($p->get('field_instructor_topics')->referencedEntities() as $term) {
          $topics[] = $term->label();
        }
      }
      $bio = $p->hasField('field_instructor_bio') ? html_entity_decode(strip_tags((string) $p->get('field_instructor_bio')->value), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
      $avail = $p->hasField('field_instructor_availability') ? strtolower((string) $p->get('field_instructor_availability')->value) : '';
      $agreement = '';
      if ($p->hasField('field_instructor_agreement_date') && !$p->get('field_instructor_agreement_date')->isEmpty()) {
        $agreement = substr((string) $p->get('field_instructor_agreement_date')->value, 0, 10);
      }
      $rows[] = [
        'profile_id' => (int) $p->id(),
        'uid' => $uid,
        'name' => $name,
        'status' => $this->gate->status($p),
        'has_photo' => $p->hasField('field_instructor_photo') && !$p->get('field_instructor_photo')->isEmpty(),
        'has_bio' => trim(str_replace("\xC2\xA0", ' ', $bio)) !== '',
        'private' => $avail !== '' && !in_array($avail, ['no', 'none', '0', 'not_available'], TRUE),
        'topics' => $topics,
        'sessions' => (int) ($s->sessions ?? 0),
        'sessions_12mo' => (int) ($s->sessions_12mo ?? 0),
        'upcoming' => (int) ($s->upcoming ?? 0),
        'last_taught' => !empty($s->last_taught) ? strtotime($s->last_taught) : 0,
        'agreement' => $agreement,
      ];
    }
    usort($rows, static function ($a, $b) {
      $aa = $a['status'] === InstructorApprovalGate::STATUS_ACTIVE ? 0 : 1;
      $bb = $b['status'] === InstructorApprovalGate::STATUS_ACTIVE ? 0 : 1;
      return [$aa, -$a['last_taught'], $a['name']] <=> [$bb, -$b['last_taught'], $b['name']];
    });
    return $rows;
  }

}
