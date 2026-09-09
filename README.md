# Instructor Companion Module

## Overview
The **Instructor Companion** module is a custom solution designed to streamline the lifecycle of an instructor at MakeHaven. It handles the specific needs of instructors which differ from standard members, including:
1.  **Specialized Onboarding:** Separate registration path that bypasses the "Member" role but captures necessary data.
2.  **Centralized Dashboard:** A single pane of glass for instructors to see their assigned classes, check rosters, and access resources.
3.  **Structured Feedback:** A dedicated feedback loop linked to specific class instances to capture logistics and supply data.

## Features & Architecture

### 1. Getting someone in: two doors

**Staff-sent invite (the normal way for someone staff have already met).**
`/admin/education/invite` — "Invite an Instructor" in the staff-tools Education
group. Staff enter name, email and an optional note; `InstructorInviteManager`
finds the account by email or creates one (no roles, no password), and emails a
personal link `/instructor/invite/{uid}/{timestamp}/{hash}`. The link signs the
person in and opens the agreement; it lasts **14 days** and is HMAC-signed with
the site salt and the account's password hash (so setting a password retires
any outstanding link, the same way core's one-time login links behave). Signing
does everything it does on the self-serve path — instructor profile, `instructor`
role, pending door badge for non-members, staff email, dashboard. Outstanding
invites are listed under **Invited — Awaiting Signature** on the Education
console (`/admin/education`, which also carries the door-access and role
queues, so staff work one screen) and on
`/admin/people/prospective-instructors`, with a Resend action; records live in
the `instructor_companion.invites` key/value collection. Email copy is
editable at the module settings page (`invite_subject` / `invite_body`,
placeholders `[invite:link]`, `[invite:sender]`, `[invite:note]`). The
dashboard warns an invite-created account that it has no password yet.

This is the only route by which someone who has not talked to staff can reach
the agreement — the ordering the 2026-08-13 rollback established.

**Self-registration (`/user/register?profile=instructor`).** Still exists for
links in the wild. The account is created with no roles, staff at
`notification_email` are told, the applicant gets the welcome email, and they
land on `/become-instructor`, which routes them by who they are (a member can
propose a session; anyone else is pointed at the interest form). It does **not**
lead to the agreement. The old `/register/instructor` redirect points at
`/become-instructor` since `update_9013`.

### 2. Instructor Dashboard
*   **Route:** `/instructor/dashboard` (Permission: `access content`, Role: `instructor`)
*   **Dynamic Class List:** 
    *   Queries `civicrm_event` entities where `field_civi_event_instructor` matches the current user.
    *   Filters for future events.
*   **Smart Actions:**
    *   **Roster:** Deep-links to the CiviCRM Participant listing (`/civicrm/event/participant?reset=1&id={ID}`).
    *   **Feedback:** Pre-populates a Webform with the class context (`?event_id={ID}`).

### 3. Class Feedback System
*   **Data Integrity:** Uses a Webform that accepts an `event_id` to link feedback to a specific historical record.
*   **Supply Management:** Introduces a "Class Supply" flag on Materials to filter the inventory list, making it easy for instructors to report usage of relevant items (kits, wood blanks) without sifting through the entire store catalog.

---

### 4. Class Checkout (instructor issues the badge)
*   **Route:** `/instructor/class-checkout/{event_id}` — linked from the dashboard ("Approve badges") and the post-event hub, for past events whose `field_civi_event_badges` is set.
*   **Model:** a class that lists a badge *includes* the badging session a facilitator would otherwise run, and the instructor is the badger. Their click is the checkout; no staff review and no training-documentation form are involved. (The badge page and `assign_badge_from_quiz` both treat a non-cancelled registration for such a class as satisfying the documentation gate.)
*   **Complete class & issue badge:** stamps `field_class_completed_date` on the student's `badge_request` (creating a pending one if needed), flips their CiviCRM participant row to *Attended*, then:
    *   quiz already passed at 100% (or badge has no quiz) → `field_badge_status` = `active` immediately;
    *   quiz not passed → stays pending, student gets the `class_checkout_quiz_reminder` email; `assign_badge_from_quiz` activates the request automatically when the 100% pass lands on a class-stamped request.
*   **Attended, did not pass:** rare. Marks *Attended*, clears any class stamp, records the pair in State (`instructor_companion.class_checkout_not_passed`, keyed `event:uid:badge`) so the row shows "Did not pass — must retake" and the post-event hub counts the student as handled, and sends the `class_checkout_not_passed` email telling them to retake the class. A later pass (same or another class) clears the record.
*   **Staff-managed statuses** (`suspended`, `expired`) are never touched by either action.
*   Decision logic is `ClassCheckoutController::resolveOutcome()` (pure, unit tested in `tests/src/Unit/ClassCheckoutOutcomeTest.php`).

## Deployment & Configuration Instructions

Since this is a custom module involving configuration that is not automatically synced, **the following steps must be performed manually on the LIVE environment** after enabling the module.

### Step 1: Enable the Module
```bash
lando drush en instructor_companion
```

### Step 2: Create the "Class Supply" Field
This field allows us to curate the list of materials instructors see in the feedback form.
1.  Go to: `/admin/structure/types/manage/material/fields/add-field`
2.  **Add a new field:**
    *   **Type:** Boolean
    *   **Label:** Class Supply
    *   **Machine Name:** `field_class_supply`
3.  **Settings:**
    *   **On Label:** Yes, used in classes
    *   **Off Label:** No
    *   **Default Value:** Unchecked (No)
4.  **Form Display:** Enable it in the "Form Display" tab so staff can check it.

### Step 3: Create the Entity Reference View
This view filters the "Materials" list to only show items marked as "Class Supplies".
1.  Go to: `/admin/structure/views/add`
2.  **View Name:** Materials - Class Supplies
3.  **Machine Name:** `materials_class_supplies`
4.  **Show:** Content of type Material
5.  **Add Display:** Click "+ Add" -> "Entity Reference".
6.  **Format:** Entity Reference list | Settings: Search fields: Title
7.  **Filter Criteria:**
    *   Add `Content: Class Supply` (the field you just made).
    *   Operator: Is equal to
    *   Value: True
8.  **Save the View.**

### Step 4: Create the Feedback Webform
1.  Go to: `/admin/structure/webform/add`
2.  **Title:** Instructor Class Feedback
3.  **Machine Name:** `instructor_feedback` (**Critical:** Must match exactly - the Dashboard controller links to `/form/instructor_feedback`).
4.  **Elements:**
    *   **Event ID** (Hidden)
        *   Key: `event_id`
        *   Default Value: Query Parameter -> `event_id`
    *   **Logistics Status** (Radios)
        *   Key: `logistics_status`
        *   Options: `smooth` (Smooth Sailing), `issues` (Had Issues)
    *   **Materials Used** (Entity Autocomplete)
        *   Key: `materials_used`
        *   Type: Entity autocomplete
        *   Target Type: Content (Node)
        *   **Selection Method:** Views: Filter by an entity reference view
        *   **View:** Materials - Class Supplies (created in Step 3)
        *   Allow multiple: Yes
    *   **Student / General Notes** (Textarea)
        *   Key: `student_notes`
5.  **Emails:** Add an Email handler to notify `education@makehaven.org` upon submission.

### Step 5: Verify Permissions
1.  Ensure the `instructor` role has the permission: `access content`.
2.  Ensure `instructor` role can view the Webform.

### Step 6: Test
1.  Login as a user with the `instructor` role.
2.  Assign yourself as the instructor to a future CiviCRM event.
3.  Go to `/instructor/dashboard`.
4.  Verify the class appears in the table.
5.  Click **Roster** -> Should open the CiviCRM participant list.
6.  Click **Submit Feedback** -> Should open the Webform with `?event_id=...` pre-filled.