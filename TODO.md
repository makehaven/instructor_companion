# TODO: Instructor Companion - Feedback & Supply Reporting

This document tracks the remaining work for the instructor feedback loop and the "low supplies" reporting mechanism.

## 1. Module Foundation
- [ ] Enable the module: `lando drush en instructor_companion`.
- [ ] Verify the Instructor Dashboard route at `/instructor/dashboard`.

## 2. Data Model Updates (Fields)
- [ ] **Material Node Type:** Add `field_class_supply` (Boolean).
    - *Purpose:* Marks which shop items should appear in class feedback forms.
- [ ] **CiviCRM Event Entity:** Add `field_event_materials` (Entity Reference to Node: Material).
    - *Purpose:* Allows staff to pre-define specific supplies required for a class instance.

## 3. View Configuration
- [ ] Create Entity Reference View: `materials_class_supplies`.
    - Filter: `Content: Class Supply = True`.
    - Display: Entity Reference.

## 4. Webform Implementation (`instructor_feedback`)

> **Replaces `webform.webform.post_workshop_instructor_evaluat`** (Ashley's existing
> "Post-Workshop Instructor Evaluation", 3 submissions on live as of 2026-05-01). That
> form has free-text instructor name/email/date and no `event_id`, so submissions can't
> be tied to a specific CiviCRM event and can't gate downstream actions. Once
> `instructor_feedback` ships, retire `post_workshop_instructor_evaluat` and redirect
> `/form/post-workshop-instructor-evaluat` to the new per-event form.

### Architectural decisions (locked in 2026-05-01 spike)

- **Event linkage = hidden field + URL prefill, NOT `webform_civicrm`.** The
  Instructor Dashboard already builds links as
  `/form/instructor_feedback?event_id={NID}` in
  `InstructorDashboardController::buildEventRow()` (search for `$feedback_url`).
  README §76-77 documents the same contract. Honor it. The 5 webforms that *do*
  use `webform_civicrm` (`guest_return_checkin`, `guest_waiver`, `next_course_survey`,
  `join_makehaven_w_consider_wait`, `webform_2670`) all set
  `participant_reg_type: '0'` — there's no precedent in this codebase for using
  `webform_civicrm` to bind a submission to an existing event, and we'd gain nothing
  by introducing one.
- **Participant prefill (for `attendance_list_actual`) = direct DB query, NOT
  `webform_civicrm`.** Pattern to follow: `xero_bills_sync_get_payee_event_options()`
  in `xero_bills_sync.module:572` queries `civicrm_participant` directly via
  `\Drupal::database()`. Implement an equivalent helper and call it from
  `hook_webform_submission_form_alter` (or a webform handler) to prefill the textarea
  with one participant per line, joined to `civicrm_contact.display_name`.
- **Future cleanup (out of scope for this build, but enabled by it):** add the same
  hidden `event_id` field to `webform.evaluation` and switch
  `getCourseFeedbackSummary()` from title-matching to event_id lookup. Closes the
  Phase 3.3 open item "Surface feedback/rating on the public Course node page
  (requires `event_id` linkage)" in
  `conductor/tracks/event_management_ecosystem_20260313/plan.md`.

### Build checklist

- [ ] Create Webform with machine name `instructor_feedback`.
- [ ] **Elements:**
    - [ ] `event_id` (Hidden): Pre-populated from URL query string `?event_id=NID` (matches the contract already coded into `InstructorDashboardController::buildEventRow()`). Required. If missing, the form should display an error rather than render — the form is unusable without an event context.
    - [ ] `instructor_uid` (Hidden): Pre-populated from logged-in user; falls back to `field_civi_event_instructor` lookup if needed.
    - [ ] `logistics_status` (Radios): Smooth / Issues.
    - [ ] `attendance_list_actual` (Textarea): Pre-filled from CiviCRM participants for the event with an "edit if different" note. Captures walk-ins, no-shows, and substitutions that the participant table missed. (From Ashley's form — keep as a supplementary signal even though CiviCRM is authoritative.)
    - [ ] `materials_used` (Entity Autocomplete): Uses `materials_class_supplies` view.
    - [ ] `low_supplies` (Checkboxes/Table):
        - Load materials from the Event (`field_event_materials`).
        - Allow instructors to mark specific items as "Low" or "Missing".
    - [ ] `tools_needing_maintenance` (Entity Autocomplete to tool nodes, multi-value): Distinct from supplies — routes to `asset_status` workflow rather than restock. Include a per-tool "what's wrong" textarea via webform composite. (From Ashley's form, but structured instead of free-text.)
    - [ ] `student_notes` (Textarea).
    - [ ] `any_issues_items_of_note` (Textarea): Catch-all for things that don't fit the structured fields. (From Ashley's form.)
    - [ ] `upload_photos` (`webform_image_file`, multiple): Documents tool issues with evidence and provides marketing-usable post-class shots. (From Ashley's form.)
    - [ ] `upload_videos` (`webform_video_file`, multiple): Same purpose, video evidence for harder-to-photograph issues. (From Ashley's form.)
- [ ] **Email Handler:** Notify `education@makehaven.org`. Include event link, instructor name, and a deep link to any pending payment request for the event.
- [ ] **Slack Integration:**
    - [ ] Post to `#shop-updates` (or configured channel) when a "Low Supply" flag is submitted.
    - [ ] Post to `#shop-updates` when a tool is flagged for maintenance; also flip the tool's `asset_status` to a "needs review" state via the existing `asset_status.status_change_logger` pathway.

## 5. Dashboard Enhancements
- [ ] Update `InstructorDashboardController.php` to detect if the class has pre-assigned materials.
- [ ] Add a visual indicator or separate "Restock Request" button if materials are missing.
- [ ] The "Submit Feedback" link in `buildEventRow()` already targets `/form/instructor_feedback?event_id={NID}` — once the webform exists, that link will start working. No code change needed for the link itself; just confirm the label reads "Submit Post-Workshop Evaluation" (or similar) on past events to match Ashley's mental model.
- [ ] **Soft gate on Log Hours:** when a past event has no `instructor_feedback` submission for this instructor, show "Request Contractor Payment" with a warning badge ("Evaluation not submitted — please complete it first"). Do not block the action — staff explicitly want to flag, not delay payments. Add a hard gate later only if soft-gate compliance data is poor.
- [ ] Staff-side instructor roster (`report_instructor_contacts` view or a new staff dashboard tile): "Evaluations pending" column showing count of past events without an `instructor_feedback` submission per instructor.

---

## Course Proposal Evolution — WIP handoff (2026-04-14)

Context: evolving the existing `webform_497` ("Workshop Proposal") and `webform_14366` ("Instructor Interest") into a coherent course-proposal pipeline. Related track: `conductor/tracks/event_management_ecosystem_20260313` in the main Pantheon repo. No new conductor track created yet — filed against this module for now.

The three-surface funnel we're building toward:

```
Instructor Interest  →  becomes instructor (role + profile)
Course Proposal      →  approved → course node created → first session scheduled
Session Scheduling   →  approved instructor, existing course, new date (direct-to-Civi)
```

Escape hatch for one-off meetups / visiting instructors: staff creates a `civicrm_event` directly with no `field_parent_course`.

### Shipped in PR1 (uncommitted at time of writing)

All PHP-only so nothing depends on config import.

- [x] `ProposalNotifier` service posts Slack pings to `#workshop-proposals` on new submissions of `webform_497` and `webform_14366`. Silently returns if `slack_connector.settings.webhook_url` is empty. Wired via `hook_entity_insert` in `.module`. Handled webforms listed in `ProposalNotifier::HANDLED_WEBFORMS`.
- [x] `InstructorProfileResolver` service pulls name/email/phone/bio/rate/photo from a user + their `profile.instructor`.
- [x] `hook_form_alter` on `webform_submission_webform_497_*_add_form` (matches by prefix — the form renders via webform node 497, so the ID contains `_node_497_`). Prefills the "Instructor" fieldset for logged-in users and shows a "Using your saved profile" banner. Anonymous users see the form unchanged.
- [x] `WorkshopProposalQueueController` at `/admin/workshop-proposals` (permission: `administer webform submission`). Filters on `review_status_38`, defaults to "Unreviewed". Surfaces the 100+ backlog that was invisible behind email-only delivery.
- [x] `InstructorInterestQueueController` at `/admin/instructor-interest`. Lists all submissions by age; no status field on webform_14366 yet.
- [x] `SubmissionQueueControllerBase` — shared table/row rendering.
- [x] Unit tests: `ProposalNotifierMessageTest` (4 tests, covers title/submitter extraction and missing-field fallback). Full module suite: 54/54.
- [x] Browser-verified on local Lando: both queues render, prefill + banner work as uid=2.

### Also in this branch (earlier turns)

- [x] Timezone fix on `InstructorDashboardController::formatEventDate()` — CiviCRM stores `civicrm_event.start_date` in site-local time, was being parsed as UTC. Regression test in `FormatEventDateTest`.
- [x] `loadInstructorEvents()` upcoming/past filter now uses `date()` not `gmdate()` for the same reason.
- [x] Stat card width fix — `flex: 1 1 0` + `max-width: 260px`, rating label simplified to "Avg Satisfaction" with a smaller `.stat-detail` line underneath.

### Not shipped / deferred (needs config import or more design)

- [ ] Fix the permission bug on `webform_497`'s Administrative fieldset — `access_update_roles`/`access_view_roles` include `authenticated` and `anonymous`, should be admin/manager only. YAML-only.
- [ ] Drop Matthew's email handler (`matthew.mccluster@makehaven.org`) from `webform_497`. Replace "Ashley will be in touch" copy with "Ashley or Silas." YAML-only.
- [ ] Real state machine replacing `review_status_38` (states: `new → in_review → changes_requested → approved → scheduled → declined / deferred`). Likely custom field + event subscriber; core `workflows` is enabled but no workflows defined. Migrate the existing 16 approved / 1 reworking / 100 unreviewed values.
- [ ] Approval handler on state transition → approved: mint `course` node (if new) and draft `civicrm_event` pre-filled from the submission + parent course.
- [ ] "Existing course?" branch on `webform_497` — course picker that pre-fills title/description/duration/tools from the course node. YAML element additions + conditional logic.
- [ ] Replace free-text "machines/tools required" on `webform_497` with entity reference to tool nodes so `ReservationConflictChecker` (from `makerspace_reservations`) can be wired in.
- [ ] Prefill-aware hiding of fields on `webform_497` — today we prefill defaults but still show the fields. Hiding them for known instructors means the form collapses down to the session-specific questions.
- [ ] Retire the `?propose=1` gate on `entity.civicrm_event.add_form` form_alter and revoke `create civicrm_event entities` from the instructor role (currently added in `config/user.role.instructor.yml` in the main Pantheon repo as a temporary unblock).
- [ ] Instructor Interest queue — add a real status field (`new / contacted / meeting_scheduled / approved / declined / stale`) and a staff action to send an instructor-agreement link.
- [ ] AI-assisted screening via `makehaven_slack_bot`: agent reads new submissions, scores completeness/safety/duplication, posts an admin-only note on the submission. Additive, no auto-approval.
- [ ] Admin menu links to the two new queues — `links.menu.yml` is config, skipped until config import resumes.

### Open questions for next session

1. Anonymous intake on `webform_497`: confirmed **keep open** — traveling instructors from other makerspaces should still be able to pitch without an account. Prefill + hide only kicks in when logged in.
2. Course-node creation on approval — automatic or staff-owned? Auto is faster; staff-owned lets them normalize naming/taxonomy. **Undecided.**
3. One-off events escape hatch — staff creates `civicrm_event` directly without `field_parent_course`. **Before retiring the propose gate, grep the repo for code that assumes every event has a parent course** (instructor dashboard, stats collectors, form_alter, feedback flow).
4. `webform_submission_states` contrib: **not installed**. Plan is custom field + event subscriber. Don't re-evaluate unless it gets painful.

### How to test PR1 manually

1. `lando drush cr`
2. `/admin/workshop-proposals` — should list ~100 unreviewed submissions. Filter pills switch status.
3. `/admin/instructor-interest` — should list ~60 submissions by age.
4. Log in as a user with a filled-in instructor profile (e.g. uid=2 J.R. Logan locally), visit `/Propose-workshop` — Instructor fieldset should prefill with a "Using your saved profile" banner above it.
5. Slack ping: configure `slack_connector.settings.webhook_url`, submit a test proposal, watch `#workshop-proposals`.

### Suggested next session starting point

Easiest next chunk with the most leverage: **state machine + approval handler**.

1. Add a `review_state` base field (or computed field) on `webform_submission` for `webform_497` via `hook_entity_base_field_info` or a dedicated service-backed lookup.
2. Migrate existing `review_status_38` values into it.
3. Event subscriber on submission update that detects a transition to `approved` and calls a handler.
4. Handler creates (or loads) a `course` node pre-filled from submission fields, then creates a draft `civicrm_event` with `field_parent_course` pointing at it.
5. Update `WorkshopProposalQueueController` to read the new state field.
6. Functional test that walks submit → approve → course node exists → draft event exists.
