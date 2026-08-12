<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;

/**
 * Displays a structured proposal review page for staff.
 *
 * Route: /admin/structure/proposals/{event_id}/review
 */
class ProposalReviewController extends ControllerBase {

  /**
   * Builds the proposal review page.
   */
  public function build(int $event_id): array {
    $event = $this->entityTypeManager()->getStorage('civicrm_event')->load($event_id);

    if (!$event) {
      return ['#markup' => '<p>' . $this->t('Proposal not found.') . '</p>'];
    }

    // If already active, it's been approved — redirect indicator.
    if ($event->get('is_active')->value) {
      return [
        '#markup' => '<div class="messages messages--status"><p>'
          . $this->t('This session has already been approved and is live.')
          . ' <a href="/admin/structure/proposals">' . $this->t('Back to proposals') . '</a></p></div>',
      ];
    }

    // Gather proposal details.
    $instructor_entities = $event->get('field_civi_event_instructor')->referencedEntities();
    $instructor = !empty($instructor_entities) ? reset($instructor_entities) : NULL;
    $instructor_name = $instructor ? $instructor->getDisplayName() : $this->t('(no instructor assigned)');
    $instructor_email = $instructor ? $instructor->getEmail() : '';

    $course_entities = $event->get('field_parent_course')->referencedEntities();
    $course = !empty($course_entities) ? reset($course_entities) : NULL;
    $course_title = $course ? $course->label() : $this->t('(no course linked)');
    $course_link = $course ? $course->toUrl()->toString() : '';

    $start_date = $event->get('start_date')->value;
    $end_date   = $event->get('end_date')->value;
    $formatted_start = $start_date ? date('D, F j, Y \a\t g:ia', strtotime($start_date)) : '—';
    $formatted_end   = $end_date   ? date('g:ia', strtotime($end_date)) : '';

    $pay_type   = $course ? $course->get('field_payment_type')->value : NULL;
    $pay_amount = $course ? $course->get('field_payment_amount')->value : NULL;
    $pay_label  = $pay_type && $pay_amount
      ? ('$' . $pay_amount . ($pay_type === 'hourly' ? '/hr' : ' fixed'))
      : $this->t('See course record');

    $max_participants = $event->get('max_participants')->value ?: '—';
    $description = $event->get('description')->value;

    // CiviCRM contact ID for instructor (for Discuss link).
    $cid = NULL;
    if ($instructor) {
      try {
        $cid = (int) \Drupal::database()
          ->select('civicrm_uf_match', 'ufm')
          ->fields('ufm', ['contact_id'])
          ->condition('ufm.uf_id', $instructor->id())
          ->execute()
          ->fetchField();
      }
      catch (\Exception $e) {
        // CiviCRM not available.
      }
    }

    $build = [];

    // Back link.
    $build['back'] = [
      '#markup' => '<p><a href="/admin/structure/proposals">← ' . $this->t('Back to Proposals') . '</a></p>',
    ];

    // Onboarding state: shown to staff so they know whether approving will
    // publish immediately or hold until the instructor finishes onboarding.
    $onboarding_html = '—';
    if ($instructor) {
      /** @var \Drupal\instructor_companion\Service\InstructorApprovalGate $gate */
      $gate = \Drupal::service('instructor_companion.approval_gate');
      $uid = (int) $instructor->id();
      $agreement_date = $gate->agreementDate($uid);
      $parts = [];
      $parts[] = $gate->hasOrientationBadge($uid)
        ? '✓ ' . $this->t('Orientation quiz passed')
        : '✗ ' . $this->t('Orientation quiz not passed');
      $parts[] = $agreement_date
        ? '✓ ' . $this->t('Agreement signed @date', ['@date' => date('M j, Y', strtotime($agreement_date))])
        : '✗ ' . $this->t('Agreement not signed');
      $onboarding_html = implode(' &nbsp;·&nbsp; ', $parts);
      if (!$gate->isOnboarded($uid)) {
        $onboarding_html .= '<br><em>' . $this->t('Approving now will hold publication until onboarding completes — the instructor is emailed the remaining steps and the session goes live automatically once they finish.') . '</em>';
      }
    }

    // Proposal details card.
    $details_rows = [
      [$this->t('Course'), $course_link ? '<a href="' . $course_link . '">' . htmlspecialchars($course_title) . '</a>' : htmlspecialchars($course_title)],
      [$this->t('Instructor'), htmlspecialchars($instructor_name) . ($instructor_email ? ' &lt;' . htmlspecialchars($instructor_email) . '&gt;' : '')],
      [$this->t('Onboarding'), $onboarding_html],
      [$this->t('Proposed Date'), $formatted_start . ($formatted_end ? ' – ' . $formatted_end : '')],
      [$this->t('Capacity'), $max_participants],
      [$this->t('Compensation'), $pay_label],
    ];

    $details_html = '<table class="proposal-review-details" style="width:100%;margin-bottom:1.5em;border-collapse:collapse;">';
    foreach ($details_rows as [$label, $value]) {
      $details_html .= '<tr>'
        . '<th style="text-align:left;padding:.4em .8em;width:160px;background:#f5f5f5;border-bottom:1px solid #ddd">' . $label . '</th>'
        . '<td style="padding:.4em .8em;border-bottom:1px solid #ddd">' . $value . '</td>'
        . '</tr>';
    }
    $details_html .= '</table>';

    $build['details'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['proposal-review-card']],
      'heading' => ['#markup' => '<h2>' . $this->t('Proposal: @title', ['@title' => $event->label()]) . '</h2>'],
      'table'   => ['#markup' => $details_html],
    ];

    if ($description) {
      // The description is proposer-supplied and rendered to staff. Filter it
      // through a minimal allowlist that keeps basic formatting but STRIPS
      // links (a spammer's <a href> becomes inert text) — narrower than the
      // admin-grade Xss::filterAdmin that plain #markup would apply. Wrap the
      // controller chrome separately so only the untrusted value is filtered.
      $safe_description = Xss::filter($description, ['p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li']);
      $build['description'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['proposal-review-card']],
        'heading' => ['#markup' => '<h3>' . $this->t('Session Description') . '</h3>'],
        'body' => [
          '#prefix' => '<div style="background:#fafafa;padding:1em;border:1px solid #eee;margin-bottom:1.5em">',
          '#markup' => Markup::create($safe_description),
          '#suffix' => '</div>',
        ],
      ];
    }

    // Action buttons.
    $approve_url = Url::fromRoute('instructor_companion.proposal_approve', ['event_id' => $event_id])->toString();
    $deny_url    = Url::fromRoute('instructor_companion.proposal_deny',    ['event_id' => $event_id])->toString();
    $edit_url    = Url::fromRoute('entity.civicrm_event.edit_form',       ['civicrm_event' => $event_id])->toString();

    $discuss_html = '';
    if ($cid) {
      $discuss_url = Url::fromUri('internal:/civicrm/activity', [
        'query' => ['action' => 'add', 'reset' => 1, 'atype' => 3, 'cid' => $cid],
      ])->toString();
      $discuss_html = '<a href="' . $discuss_url . '" class="button" style="background:#f39c12;border-color:#e67e22;color:#fff">'
        . $this->t('Discuss via CiviCRM Email') . '</a> ';
    }
    elseif ($instructor_email) {
      $discuss_html = '<a href="mailto:' . htmlspecialchars($instructor_email) . '" class="button" style="background:#f39c12;border-color:#e67e22;color:#fff">'
        . $this->t('Email Instructor') . '</a> ';
    }

    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['proposal-review-actions'], 'style' => 'display:flex;gap:1em;align-items:center;flex-wrap:wrap;margin-bottom:2em'],
      '#markup' =>
        '<a href="' . $approve_url . '" class="button button--primary" style="background:#27ae60;border-color:#219a52">'
          . $this->t('✓ Approve') . '</a> '
        . '<a href="' . $deny_url . '" class="button" style="background:#e74c3c;border-color:#c0392b;color:#fff">'
          . $this->t('✗ Deny') . '</a> '
        . $discuss_html
        . '<a href="' . $edit_url . '" class="button button--secondary">'
          . $this->t('Edit Full Record') . '</a>',
    ];

    return $build;
  }

  /**
   * Approves a proposal.
   *
   * If the instructor has completed onboarding the event goes live
   * immediately. Otherwise the approval is recorded as a hold: the instructor
   * is emailed the outstanding onboarding steps and ProposalHoldManager
   * publishes the event automatically once they finish.
   */
  public function approve(int $event_id): \Symfony\Component\HttpFoundation\RedirectResponse {
    $event = $this->entityTypeManager()->getStorage('civicrm_event')->load($event_id);

    if (!$event) {
      $this->messenger()->addError($this->t('Proposal not found.'));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(Url::fromUserInput('/admin/structure/proposals')->toString());
    }

    $instructor_entities = $event->get('field_civi_event_instructor')->referencedEntities();
    $instructor = !empty($instructor_entities) ? reset($instructor_entities) : NULL;

    /** @var \Drupal\instructor_companion\Service\InstructorApprovalGate $gate */
    $gate = \Drupal::service('instructor_companion.approval_gate');

    // Hold path: approved, but the instructor still has onboarding to finish.
    // No instructor assigned is treated as onboarded — nothing to hold on.
    if ($instructor && !$gate->isOnboarded((int) $instructor->id())) {
      \Drupal::service('instructor_companion.proposal_hold_manager')
        ->hold($event_id, (int) $instructor->id());

      $outstanding = [];
      if (!$gate->hasOrientationBadge((int) $instructor->id())) {
        $outstanding[] = t('Watch the orientation video and pass the short quiz: @url', [
          '@url' => Url::fromUserInput('/video-instructor', ['absolute' => TRUE])->toString(),
        ]);
      }
      if (!$gate->hasSignedAgreement((int) $instructor->id())) {
        $outstanding[] = t('Sign the master instructor agreement: @url', [
          '@url' => Url::fromUserInput('/form/webform-5220', ['absolute' => TRUE])->toString(),
        ]);
      }

      \Drupal::service('plugin.manager.mail')->mail(
        'instructor_companion',
        'proposal_approved_pending_onboarding',
        $instructor->getEmail(),
        $instructor->getPreferredLangcode(),
        [
          'user_name'   => $instructor->getDisplayName(),
          'event_title' => $event->label(),
          'start_date'  => $event->get('start_date')->value
            ? date('D, F j, Y \a\t g:ia', strtotime($event->get('start_date')->value))
            : '',
          'outstanding' => $outstanding,
        ],
        NULL,
        TRUE
      );

      $this->messenger()->addStatus($this->t('Session "@title" approved — publication is held until @name finishes onboarding (they were emailed the remaining steps, and the session will go live automatically).', [
        '@title' => $event->label(),
        '@name' => $instructor->getDisplayName(),
      ]));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(Url::fromUserInput('/admin/structure/proposals')->toString());
    }

    $event->set('is_active', 1);
    $event->save();

    // Notify instructor.
    if ($instructor) {
      $params = [
        'user_name'   => $instructor->getDisplayName(),
        'event_title' => $event->label(),
        'event_link'  => $event->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'start_date'  => $event->get('start_date')->value
          ? date('D, F j, Y \a\t g:ia', strtotime($event->get('start_date')->value))
          : '',
      ];
      \Drupal::service('plugin.manager.mail')->mail(
        'instructor_companion',
        'proposal_approved',
        $instructor->getEmail(),
        $instructor->getPreferredLangcode(),
        $params,
        NULL,
        TRUE
      );
    }

    $this->messenger()->addStatus($this->t('Session "@title" approved and is now live.', ['@title' => $event->label()]));
    return new \Symfony\Component\HttpFoundation\RedirectResponse(Url::fromUserInput('/admin/structure/proposals')->toString());
  }

}
