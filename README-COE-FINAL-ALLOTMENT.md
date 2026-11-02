# COE Final Question Allotment — Installation & Use

## Files added/updated
- `modules/coe/final_allotment.php` — COE-only assignment screen.
- `api/coe_final_allotment.php` — blueprint/pool loading and assignment save API.
- `api/save_final_blueprint_slots.php` — saves Teaching/HOD slot definitions into `qps_final_blueprints.slots_config` (the schema used by the current application).
- `database/migrate_coe_final_question_allotment.sql` — additive allocation table migration.
- COE sidebar quick-action link.

## Installation
1. Back up your project folder and database.
2. Extract this ZIP over your existing project folder, preserving `config/config.php`, uploads and storage.
3. In phpMyAdmin, select the same application database and run `database/migrate_coe_final_question_allotment.sql`.
   - The API also attempts to create the table on first request for MySQL/MariaDB.
4. Log in as COE and open **Final Allotment** from the COE quick-action menu.

## Workflow
1. Teaching staff/HOD save an active final blueprint using `api/save_final_blueprint_slots.php`.
2. COE opens **Final Allotment**, chooses the active course/semester/year blueprint.
3. COE clicks a slot, then assigns one approved question from that course's approved question bank.
4. Click **Save final allotment**.

## Safeguards
- Only COE can open/save the allotment page.
- Teaching/HOD can define blueprint slots; COE cannot alter blueprint structure through the slot-save endpoint.
- Only approved question banks for the same paper code are offered.
- Server validates unit, subunit, question type, marks, K-level and CO-level when specified by a slot.
- A question cannot be assigned twice within one blueprint.
- Existing prior-use helper is called to reject questions used in an earlier academic year/semester.
- Allotments are stored separately from source questions and blueprint definitions.

## Important compatibility note
The COE screen reads `slots_config` from `qps_final_blueprints`. If a course has no active blueprint, create/save its slot definition from the Teaching/HOD blueprint workflow first. Existing rows with a different legacy JSON shape may need their slot JSON normalized before they appear.
