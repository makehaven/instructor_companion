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
- [ ] Create Webform with machine name `instructor_feedback`.
- [ ] **Elements:**
    - [ ] `event_id` (Hidden): Pre-populated from URL.
    - [ ] `logistics_status` (Radios): Smooth / Issues.
    - [ ] `materials_used` (Entity Autocomplete): Uses `materials_class_supplies` view.
    - [ ] **NEW:** `low_supplies` (Checkboxes/Table):
        - Load materials from the Event (`field_event_materials`).
        - Allow instructors to mark specific items as "Low" or "Missing".
    - [ ] `student_notes` (Textarea).
- [ ] **Email Handler:** Notify `education@makehaven.org`.
- [ ] **Slack Integration:** Post to `#shop-updates` or similar when a "Low Supply" flag is submitted.

## 5. Dashboard Enhancements
- [ ] Update `InstructorDashboardController.php` to detect if the class has pre-assigned materials.
- [ ] Add a visual indicator or separate "Restock Request" button if materials are missing.
