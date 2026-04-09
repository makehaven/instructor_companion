<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

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
