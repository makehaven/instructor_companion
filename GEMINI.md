# Instructor Companion Module Context

## Project Overview
The **Instructor Companion** is a custom Drupal module (part of the `Makerspace` package) designed for MakeHaven. Its primary purpose is to manage and enhance the lifecycle of instructors, providing them with specialized registration paths, a centralized dashboard, and structured feedback mechanisms.

### Key Technologies
- **Drupal 10/11** Core
- **CiviCRM Integration:** Specifically uses `civicrm_event` entities via the CiviCRM Entity module.
- **Webform:** For instructor feedback and logistics reporting.
- **Profile Module:** For specialized "Instructor Profile" entities.

## Architecture & Features

### 1. Specialized Registration
- **Mechanism:** Intercepts the standard user registration form when a `profile=instructor` query parameter is present.
- **Workflow:**
    - Bypasses automatic `member` role assignment.
    - Captures instructor-specific data via the `profile_registration` module.
    - Triggers an email notification (`instructor_companion_mail`) to `education@makehaven.org` upon submission.
- **Code:** `instructor_companion_form_user_register_form_alter()` and `instructor_companion_user_register_notify()`.

### 2. Instructor Dashboard
- **Route:** `/instructor/dashboard`
- **Controller:** `InstructorDashboardController`
- **Functionality:**
    - **Toolkit:** Displays a list of essential links (Emergency Procedures, Handbook, etc.) configured in module settings.
    - **Profile Check:** Alerts the user if their `instructor` profile is incomplete.
    - **Class List:** Queries `civicrm_event` entities where the current user is assigned as the instructor.
    - **Deep Links:** Provides buttons for "Roster" (pointing to CiviCRM) and "Submit Feedback" (pointing to a specific Webform).

### 3. Feedback & Supply Management
- **Webform:** Links class feedback to specific events using an `event_id` query parameter.
- **Materials Integration:** Utilizes a custom `field_class_supply` on the `material` content type to curate which items instructors can report using in their classes.

## Building and Running

### Installation
```bash
lando drush en instructor_companion
```

### Configuration
- **Settings Form:** Accessible at `/admin/config/makerspace/instructor-companion`.
- **Configurable Items:**
    - Notification email for new applicants.
    - Toolkit URLs (Reimbursement, Handbook, Emergency, Log Hours).

### Manual Setup (Mandatory)
This module depends on specific site configurations that are not bundled in code:
1.  **Webform:** Create a Webform with machine name `instructor_feedback` that accepts `event_id`.
2.  **Custom Field:** Add boolean `field_class_supply` to the `material` node type.
3.  **Entity Reference View:** Create `materials_class_supplies` to filter materials for the feedback form.
*See `README.md` for exact steps.*

## Development Conventions

- **Hooks:** Standard Drupal hooks are used for form alterations and mail handling (`.module` file).
- **Controllers/Forms:** Follow PSR-4 standards in `src/Controller` and `src/Form`.
- **CiviCRM Queries:** Entity queries against `civicrm_event` are used; beware of potential field name variations if CiviCRM schemas change.
- **Permissioning:** The module defines `access instructor dashboard` and `administer site configuration` (mapped in `instructor_companion.permissions.yml`).
