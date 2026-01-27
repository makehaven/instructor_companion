# Instructor Companion Module

## Overview
The **Instructor Companion** module is a custom solution designed to streamline the lifecycle of an instructor at MakeHaven. It handles the specific needs of instructors which differ from standard members, including:
1.  **Specialized Onboarding:** Separate registration path that bypasses the "Member" role but captures necessary data.
2.  **Centralized Dashboard:** A single pane of glass for instructors to see their assigned classes, check rosters, and access resources.
3.  **Structured Feedback:** A dedicated feedback loop linked to specific class instances to capture logistics and supply data.

## Features & Architecture

### 1. Registration Logic
*   **Trigger:** Uses the existing user registration form with a query parameter: `/user/register?profile=instructor`.
*   **Security:** 
    *   Intercepts the `profile_registration` logic to ensuring the `member` role is **NOT** assigned.
    *   Does **NOT** automatically assign the `instructor` role (requires staff vetting).
    *   Redirects to the specific "Instructor Profile" form (`/user/{uid}/instructor`) instead of the Main profile.
*   **Notifications:** Automatically emails `education@makehaven.org` when a new instructor application is submitted.

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