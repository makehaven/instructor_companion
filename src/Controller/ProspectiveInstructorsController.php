<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\InstructorInviteManager;
use Drupal\user\UserInterface;

/**
 * Staff view of members who indicated willingness to teach but are not yet instructors.
 *
 * Route: /admin/people/prospective-instructors
 * Permission: administer users
 */
class ProspectiveInstructorsController extends ControllerBase {

  /**
   * Builds the prospective instructors staff page.
   */
  public function build(): array {
    $build = [];
    $build['invited'] = $this->buildInvitedSection();
    $build['awaiting_door'] = $this->buildAwaitingDoorAccessSection();
    $build['awaiting_role'] = $this->buildAwaitingRoleSection();
    $build['interest_section'] = $this->buildTeachingInterestSection();
    return $build;
  }

  /**
   * The three "onboarding in progress" sections, for embedding elsewhere.
   *
   * The Education console renders these so staff have one screen; the action
   * links carry ?destination so they land back where they clicked.
   *
   * @param string|null $destination
   *   Internal path to return to after an action, or NULL for this page.
   */
  public function onboardingSections(?string $destination = NULL): array {
    return [
      'invited' => $this->buildInvitedSection($destination),
      'awaiting_door' => $this->buildAwaitingDoorAccessSection($destination),
      'awaiting_role' => $this->buildAwaitingRoleSection($destination),
    ];
  }

  /**
   * Drops action links the current user has no access to.
   *
   * The Education console renders these sections for anyone with "access
   * education console", but granting a role or door access still needs
   * "Administer users" — so an event manager sees the queue without dead
   * buttons that would 403.
   */
  protected function usableLinks(array $links): array {
    return array_filter($links, static function (array $link): bool {
      return !isset($link['url']) || $link['url']->access();
    });
  }

  /**
   * Query for a CSRF-protected action link, plus the return path when given.
   */
  protected function actionQuery(Url $url, ?string $destination): array {
    $query = ['token' => \Drupal::csrfToken()->get($url->getInternalPath())];
    if ($destination) {
      $query['destination'] = $destination;
    }
    return $query;
  }

  /**
   * Section: people staff invited to sign the agreement who have not yet.
   *
   * Fed by the Invite an Instructor form. A row disappears the moment the
   * person signs (they then show under door access / role as usual).
   */
  protected function buildInvitedSection(?string $destination = NULL): array {
    /** @var \Drupal\instructor_companion\Service\InstructorInviteManager $invites */
    $invites = \Drupal::service('instructor_companion.invite');
    $pending = $invites->pending();
    $invite_link = [
      '#type' => 'link',
      '#title' => $this->t('Invite an instructor'),
      '#url' => Url::fromRoute('instructor_companion.invite_form', [], $destination ? ['query' => ['destination' => $destination]] : []),
      '#attributes' => ['class' => ['button', 'button--primary', 'button--small']],
    ];
    $heading = ['#markup' => '<h2>' . $this->t('Invited — Awaiting Signature') . '</h2>'];

    if (!$pending) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['invited-instructors-section']],
        'heading' => $heading,
        'empty' => ['#markup' => '<p><em>' . $this->t('Nobody is waiting on an invite right now.') . '</em></p>'],
        'invite' => $invite_link,
      ];
    }

    $user_storage = $this->entityTypeManager()->getStorage('user');
    $rows = [];
    foreach ($pending as $uid => $record) {
      /** @var \Drupal\user\UserInterface $user */
      $user = $record['user'];
      $by = $user_storage->load($record['last_sent_by'] ?? $record['invited_by'] ?? 0);
      $sent = (int) ($record['last_sent'] ?? 0);
      $now = \Drupal::time()->getRequestTime();
      $started = (int) ($record['invited_at'] ?? $sent);
      $expires = $sent + InstructorInviteManager::TTL;
      $expired = !InstructorInviteManager::isWithinTtl($sent, $now);

      $resend_url = Url::fromRoute('instructor_companion.invite_resend', ['user' => $uid]);
      $resend_url->setOption('query', $this->actionQuery($resend_url, $destination));

      $status = $expired
        ? $this->t('Link expired')
        : (!empty($record['accepted_at']) ? $this->t('Opened the link, not signed yet') : $this->t('Sent, not opened'));

      $rows[] = [
        'name' => [
          'data' => [
            '#type' => 'link',
            '#title' => $record['name'] ?: $user->getDisplayName(),
            '#url' => Url::fromRoute('entity.user.canonical', ['user' => $uid]),
          ],
        ],
        'email' => $user->getEmail(),
        'sent' => $this->t('@when by @who@again', [
          '@when' => $sent ? date('M j', $sent) : '—',
          '@who' => $by ? $by->getDisplayName() : $this->t('staff'),
          '@again' => ($record['count'] ?? 1) > 1 ? ' (×' . $record['count'] . ')' : '',
        ]),
        'status' => $status,
        'waiting' => \Drupal::service('date.formatter')->formatInterval(max(0, $now - $started), 1),
        'expires' => \Drupal::service('date.formatter')->format($expires, 'custom', 'M j, Y'),
        'follow_up' => $this->t('@who: @action', [
          '@who' => $by ? $by->getDisplayName() : $this->t('Education team'),
          '@action' => $expired
            ? $this->t('resend the expired link and contact the instructor')
            : $this->t('contact the instructor if they need help signing'),
        ]),
        'actions' => [
          'data' => [
            '#type' => 'dropbutton',
            '#links' => $this->usableLinks([
              'resend' => ['title' => $this->t('Resend invite'), 'url' => $resend_url],
              'profile' => ['title' => $this->t('Open account'), 'url' => Url::fromRoute('entity.user.canonical', ['user' => $uid])],
            ]),
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['invited-instructors-section']],
      'heading' => $heading,
      'summary' => [
        '#markup' => '<p>' . $this->t(
          '<strong>@count</strong> invited to sign the instructor agreement and not signed yet. Links last 14 days; resend if one has gone stale.',
          ['@count' => count($rows)]
        ) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'name' => $this->t('Name'),
          'email' => $this->t('Email'),
          'sent' => $this->t('Invite sent'),
          'status' => $this->t('Status'),
          'waiting' => $this->t('Waiting since first invite'),
          'expires' => $this->t('Link expires'),
          'follow_up' => $this->t('Follow-up owner / next step'),
          'actions' => $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['invited-instructors-table']],
      ],
      'invite' => $invite_link,
    ];
  }

  /**
   * Section: instructors with a pending door badge awaiting staff approval.
   *
   * This is the deliberate gate between "signed the agreement" and "can let
   * themselves into the building". Approving flips the badge_request to
   * active, which unifi_access_sync immediately pushes to UniFi Access.
   */
  protected function buildAwaitingDoorAccessSection(?string $destination = NULL): array {
    /** @var \Drupal\instructor_companion\Service\InstructorDoorAccess $door */
    $door = \Drupal::service('instructor_companion.door_access');
    $door_tid = $door->getDoorTermId();

    $heading = ['#markup' => '<h2>' . $this->t('Awaiting Door Access Approval') . '</h2>'];

    if (!$door_tid) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['awaiting-door-access-section']],
        'heading' => $heading,
        'warning' => ['#markup' => '<p><em>' . $this->t('Door badge term is not configured in unifi_access_sync settings — cannot list pending requests.') . '</em></p>'],
      ];
    }

    $entity_type_manager = $this->entityTypeManager();
    $node_storage = $entity_type_manager->getStorage('node');

    $pending_nids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_badge_requested.target_id', $door_tid)
      ->condition('field_badge_status.value', 'pending')
      ->sort('created', 'ASC')
      ->execute();

    if (empty($pending_nids)) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['awaiting-door-access-section']],
        'heading' => $heading,
        'empty' => ['#markup' => '<p><em>' . $this->t('No instructors are waiting for door access approval.') . '</em></p>'],
      ];
    }

    $rows = [];
    foreach ($node_storage->loadMultiple($pending_nids) as $request) {
      $uid = (int) ($request->get('field_member_to_badge')->target_id ?? 0);
      if (!$uid) {
        continue;
      }
      $user = $entity_type_manager->getStorage('user')->load($uid);
      if (!$user) {
        continue;
      }

      $signed_date = '—';
      $profiles = $entity_type_manager->getStorage('profile')->loadByProperties([
        'uid' => $uid,
        'type' => 'instructor',
      ]);
      if (!empty($profiles)) {
        $profile = reset($profiles);
        if ($profile->hasField('field_instructor_agreement_date') && !$profile->get('field_instructor_agreement_date')->isEmpty()) {
          $signed_date = date('M j, Y', strtotime($profile->get('field_instructor_agreement_date')->value));
        }
      }

      $grant_url = Url::fromRoute('instructor_companion.grant_door_access', ['user' => $uid]);
      $grant_url->setOption('query', $this->actionQuery($grant_url, $destination));

      $rows[] = [
        'name' => [
          'data' => [
            '#type' => 'link',
            '#title' => $user->getDisplayName(),
            '#url' => Url::fromRoute('entity.user.canonical', ['user' => $uid]),
          ],
        ],
        'email' => $user->getEmail(),
        'signed' => $signed_date,
        'requested' => date('M j, Y', (int) $request->getCreatedTime()),
        'has_role' => $user->hasRole('instructor') ? $this->t('Yes') : $this->t('No — grant role too'),
        'actions' => [
          'data' => [
            '#type' => 'dropbutton',
            '#links' => $this->usableLinks([
              'grant' => [
                'title' => $this->t('Grant Door Access'),
                'url' => $grant_url,
              ],
              'profile' => [
                'title' => $this->t('Open Profile'),
                'url' => Url::fromRoute('entity.user.canonical', ['user' => $uid]),
              ],
            ]),
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['awaiting-door-access-section']],
      'heading' => $heading,
      'summary' => [
        '#markup' => '<p>' . $this->t(
          '<strong>@count instructor(s)</strong> have a pending door badge. Approving grants building access immediately (synced to UniFi). Review their background before approving. If you cannot see the approval action, ask a site manager with permission to administer users to review and approve access.',
          ['@count' => count($rows)]
        ) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'name' => $this->t('Name'),
          'email' => $this->t('Email'),
          'signed' => $this->t('Agreement signed'),
          'requested' => $this->t('Requested'),
          'has_role' => $this->t('Instructor role'),
          'actions' => $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['awaiting-door-access-table']],
      ],
    ];
  }

  /**
   * Approves a pending door badge: flips it to active.
   *
   * When this saves, the unifi_access_sync entity-update hook syncs the user
   * to UniFi Access immediately, so building access takes effect at once.
   */
  public function grantDoorAccess(UserInterface $user) {
    /** @var \Drupal\instructor_companion\Service\InstructorDoorAccess $door */
    $door = \Drupal::service('instructor_companion.door_access');

    if ($door->hasActiveDoorBadge((int) $user->id())) {
      $this->messenger()->addWarning($this->t('@name already has active door access.', ['@name' => $user->getDisplayName()]));
      return $this->redirect('instructor_companion.prospective_instructors');
    }

    $request = $door->loadDoorBadgeRequest((int) $user->id());
    if (!$request || strcasecmp((string) $request->get('field_badge_status')->value, 'pending') !== 0) {
      $this->messenger()->addError($this->t('No pending door badge request found for @name.', ['@name' => $user->getDisplayName()]));
      return $this->redirect('instructor_companion.prospective_instructors');
    }

    $request->set('field_badge_status', 'active');
    if ($request->getEntityType()->isRevisionable()) {
      $request->setNewRevision(TRUE);
      $request->setRevisionUserId((int) $this->currentUser()->id());
      $request->setRevisionLogMessage((string) $this->t('Door access approved via prospective-instructors queue.'));
    }
    $request->save();

    $this->messenger()->addStatus($this->t('Granted door access to @name. They are being synced to UniFi now.', ['@name' => $user->getDisplayName()]));
    \Drupal::logger('instructor_companion')->notice('Door access approved for @name (uid @uid), badge_request @nid set active via prospective-instructors queue by uid @by.', [
      '@name' => $user->getDisplayName(),
      '@uid' => $user->id(),
      '@nid' => $request->id(),
      '@by' => $this->currentUser()->id(),
    ]);

    return $this->redirect('instructor_companion.prospective_instructors');
  }

  /**
   * Section: signed agreement, no instructor role yet — staff action needed.
   */
  protected function buildAwaitingRoleSection(?string $destination = NULL): array {
    $db = \Drupal::database();
    $entity_type_manager = $this->entityTypeManager();

    // Find all instructor profiles with a signed agreement date.
    $signed_query = $db->select('profile', 'p');
    $signed_query->join('profile__field_instructor_agreement_date', 'd', 'd.entity_id = p.profile_id AND d.deleted = 0');
    $signed_query->fields('p', ['uid']);
    $signed_query->condition('p.type', 'instructor');
    $signed_query->condition('p.status', 1);
    $signed_query->isNotNull('d.field_instructor_agreement_date_value');
    $signed_query->distinct();
    $signed_uids = $signed_query->execute()->fetchCol();

    if (empty($signed_uids)) {
      return [];
    }

    // Filter out users who already have the instructor role.
    $users = $entity_type_manager->getStorage('user')->loadMultiple($signed_uids);
    $awaiting_uids = [];
    foreach ($users as $uid => $user) {
      if (!$user->hasRole('instructor')) {
        $awaiting_uids[] = (int) $uid;
      }
    }

    if (empty($awaiting_uids)) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['awaiting-instructor-role-section']],
        'heading' => ['#markup' => '<h2>' . $this->t('Awaiting Role Assignment') . '</h2>'],
        'empty' => ['#markup' => '<p><em>' . $this->t('No applicants are waiting for the Instructor role to be granted.') . '</em></p>'],
      ];
    }

    // Build rows.
    $rows = [];
    foreach ($awaiting_uids as $uid) {
      $user = $users[$uid] ?? NULL;
      if (!$user) {
        continue;
      }

      $signed_date = NULL;
      $profiles = $entity_type_manager->getStorage('profile')->loadByProperties([
        'uid' => $uid,
        'type' => 'instructor',
      ]);
      if (!empty($profiles)) {
        $profile = reset($profiles);
        if (!$profile->get('field_instructor_agreement_date')->isEmpty()) {
          $signed_date = date('M j, Y', strtotime($profile->get('field_instructor_agreement_date')->value));
        }
      }

      $grant_url = Url::fromRoute('instructor_companion.grant_instructor_role', ['user' => $uid]);
      $grant_url->setOption('query', $this->actionQuery($grant_url, $destination));

      $rows[] = [
        'name' => [
          'data' => [
            '#type' => 'link',
            '#title' => $user->getDisplayName(),
            '#url' => Url::fromRoute('entity.user.canonical', ['user' => $uid]),
          ],
        ],
        'email' => $user->getEmail(),
        'signed' => $signed_date ?? '—',
        'actions' => [
          'data' => [
            '#type' => 'dropbutton',
            '#links' => $this->usableLinks([
              'grant' => [
                'title' => $this->t('Grant Instructor Role'),
                'url' => $grant_url,
              ],
              'profile' => [
                'title' => $this->t('Open Profile'),
                'url' => Url::fromRoute('entity.user.canonical', ['user' => $uid]),
              ],
            ]),
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['awaiting-instructor-role-section']],
      'heading' => ['#markup' => '<h2>' . $this->t('Awaiting Role Assignment') . '</h2>'],
      'summary' => [
        '#markup' => '<p>' . $this->t(
          '<strong>@count applicants</strong> have signed the instructor agreement and are waiting for staff to grant the Instructor role.',
          ['@count' => count($rows)]
        ) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'name' => $this->t('Name'),
          'email' => $this->t('Email'),
          'signed' => $this->t('Agreement signed'),
          'actions' => $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['awaiting-instructor-role-table']],
      ],
    ];
  }

  /**
   * Grants the instructor role to a user.
   */
  public function grantRole(UserInterface $user) {
    if ($user->hasRole('instructor')) {
      $this->messenger()->addWarning($this->t('@name already has the Instructor role.', ['@name' => $user->getDisplayName()]));
    }
    else {
      $user->addRole('instructor');
      $user->save();
      $this->messenger()->addStatus($this->t('Granted Instructor role to @name.', ['@name' => $user->getDisplayName()]));
      \Drupal::logger('instructor_companion')->notice('Instructor role granted to @name (uid @uid) via prospective-instructors queue.', [
        '@name' => $user->getDisplayName(),
        '@uid' => $user->id(),
      ]);
    }
    return $this->redirect('instructor_companion.prospective_instructors');
  }

  /**
   * Section: members who indicated teaching interest but have no instructor profile.
   */
  protected function buildTeachingInterestSection(): array {
    $db = \Drupal::database();
    $entity_type_manager = $this->entityTypeManager();

    // 1. Find all main-profile users who checked teach_volunteer or teach_paid.
    $avail_query = $db->select('profile', 'p');
    $avail_query->join('profile__field_member_availability', 'avail', 'avail.entity_id = p.profile_id AND avail.deleted = 0');
    $avail_query->fields('p', ['uid']);
    $avail_query->condition('p.type', 'main');
    $avail_query->condition('p.status', 1);
    $avail_query->condition('avail.field_member_availability_value', ['teach_volunteer', 'teach_paid'], 'IN');
    $avail_query->distinct();
    $teaching_uids = $avail_query->execute()->fetchCol();

    if (empty($teaching_uids)) {
      return ['#markup' => '<p>' . $this->t('No members have indicated teaching availability.') . '</p>'];
    }

    // 2. Find which of those UIDs already have an instructor profile.
    $instructor_query = $db->select('profile', 'ip');
    $instructor_query->fields('ip', ['uid']);
    $instructor_query->condition('ip.type', 'instructor');
    $instructor_query->condition('ip.uid', $teaching_uids, 'IN');
    $already_instructor_uids = $instructor_query->execute()->fetchCol();

    $prospective_uids = array_values(array_diff($teaching_uids, $already_instructor_uids));

    if (empty($prospective_uids)) {
      return ['#markup' => '<p>' . $this->t('All members who indicated teaching interest already have instructor profiles.') . '</p>'];
    }

    // 3. Load CiviCRM contact IDs for these users.
    $cid_map = [];
    try {
      $cid_rows = $db->select('civicrm_uf_match', 'ufm')
        ->fields('ufm', ['uf_id', 'contact_id'])
        ->condition('ufm.uf_id', $prospective_uids, 'IN')
        ->execute()
        ->fetchAllKeyed();
      $cid_map = array_map('intval', $cid_rows);
    }
    catch (\Exception $e) {
      // CiviCRM may not be initialized — carry on without CiviCRM links.
    }

    // 4. Load availability labels per UID.
    $avail_label_map = [
      'teach_volunteer' => $this->t('Volunteer Teaching'),
      'teach_paid' => $this->t('Paid Teaching'),
    ];
    $avail_result = $db->select('profile', 'p');
    $avail_result->join('profile__field_member_availability', 'avail', 'avail.entity_id = p.profile_id AND avail.deleted = 0');
    $avail_result->fields('p', ['uid']);
    $avail_result->addField('avail', 'field_member_availability_value', 'avail_value');
    $avail_result->condition('p.type', 'main');
    $avail_result->condition('p.uid', $prospective_uids, 'IN');
    $avail_result->condition('avail.field_member_availability_value', ['teach_volunteer', 'teach_paid'], 'IN');
    $avail_per_uid = [];
    foreach ($avail_result->execute() as $row) {
      $avail_per_uid[(int) $row->uid][] = (string) ($avail_label_map[$row->avail_value] ?? $row->avail_value);
    }

    // 5. Load member interests per UID.
    $interests_per_uid = [];
    try {
      $int_query = $db->select('profile', 'p');
      $int_query->join('profile__field_member_interests', 'mi', 'mi.entity_id = p.profile_id AND mi.deleted = 0');
      $int_query->join('taxonomy_term_field_data', 'td', 'td.tid = mi.field_member_interests_target_id');
      $int_query->fields('p', ['uid']);
      $int_query->addField('td', 'name', 'term_name');
      $int_query->condition('p.type', 'main');
      $int_query->condition('p.uid', $prospective_uids, 'IN');
      foreach ($int_query->execute() as $row) {
        $interests_per_uid[(int) $row->uid][] = $row->term_name;
      }
    }
    catch (\Exception $e) {
      // Field may not exist in all environments.
    }

    // 6. Load users and build rows.
    $users = $entity_type_manager->getStorage('user')->loadMultiple($prospective_uids);
    $all_emails = [];
    $rows = [];

    foreach ($prospective_uids as $uid) {
      $user = $users[$uid] ?? NULL;
      if (!$user) {
        continue;
      }

      $email = $user->getEmail();
      $all_emails[] = $email;
      $created = date('M j, Y', $user->getCreatedTime());
      $display_name = $user->getDisplayName();

      $avail_labels = implode(', ', $avail_per_uid[$uid] ?? []);
      $interest_tags = implode(', ', $interests_per_uid[$uid] ?? []);

      // Build action links.
      $links = [
        'profile' => [
          'title' => $this->t('Drupal Profile'),
          'url' => Url::fromRoute('entity.user.canonical', ['user' => $uid]),
        ],
        'invite' => [
          'title' => $this->t('Invite to Apply'),
          'url' => Url::fromRoute('instructor_companion.become_instructor', [], ['absolute' => TRUE]),
        ],
      ];

      $cid = $cid_map[$uid] ?? NULL;
      if ($cid) {
        $links['civicrm'] = [
          'title' => $this->t('CiviCRM Contact'),
          'url' => Url::fromUri('internal:/civicrm/contact/view', ['query' => ['reset' => 1, 'cid' => $cid]]),
        ];
        $links['email_civi'] = [
          'title' => $this->t('Email via CiviCRM'),
          'url' => Url::fromUri('internal:/civicrm/activity', [
            'query' => ['action' => 'add', 'reset' => 1, 'atype' => 3, 'cid' => $cid],
          ]),
        ];
      }

      $rows[] = [
        'name' => [
          'data' => [
            '#type' => 'link',
            '#title' => $display_name,
            '#url' => Url::fromRoute('entity.user.canonical', ['user' => $uid]),
          ],
        ],
        'email' => $email,
        'availability' => $avail_labels,
        'interests' => $interest_tags ?: '—',
        'member_since' => $created,
        'actions' => [
          'data' => [
            '#type' => 'dropbutton',
            '#links' => $links,
          ],
        ],
      ];
    }

    $build = [];
    $build['heading'] = [
      '#markup' => '<h2>' . $this->t('Members Who Indicated Teaching Interest') . '</h2>',
    ];

    // Summary bar.
    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['prospective-instructors-summary']],
      '#markup' => '<p>' . $this->t(
        '<strong>@count members</strong> indicated teaching interest but do not yet have an instructor profile.',
        ['@count' => count($rows)]
      ) . '</p>',
    ];

    // Bulk email helper.
    $build['bulk_email'] = [
      '#type' => 'details',
      '#title' => $this->t('Bulk Email Addresses (copy for CiviCRM Mailing)'),
      '#open' => FALSE,
      'emails' => [
        '#markup' => '<textarea rows="4" style="width:100%;font-family:monospace;" onclick="this.select()">' .
          htmlspecialchars(implode(', ', $all_emails)) . '</textarea>',
      ],
      'help' => [
        '#markup' => '<p><em>' . $this->t(
          'Paste these into a CiviCRM Mailing\'s recipient field, or use CiviCRM\'s "Add by Email" to create a group.'
        ) . '</em></p>',
      ],
    ];

    // Main table.
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'name' => $this->t('Name'),
        'email' => $this->t('Email'),
        'availability' => $this->t('Availability'),
        'interests' => $this->t('Interests'),
        'member_since' => $this->t('Member Since'),
        'actions' => $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No prospective instructors found.'),
      '#attributes' => ['class' => ['prospective-instructors-table']],
    ];

    return $build;
  }

}
