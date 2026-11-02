# V33 — COE Final Question Allotment

## Added
- COE-only Final Question Allotment page at `modules/coe/final_allotment.php`.
- COE navigation shortcut.
- API `api/coe_final_allotment.php` to list active final blueprints, load slots and approved course question pool, and save slot assignments.
- Additive table `qps_coe_question_allotments` with unique constraints preventing a question being assigned twice in the same blueprint.
- Slot compatibility validation for unit, marks, K-level and CO-level.
- Re-checks course ownership and approved bank status at save time.
- Prior-use validation through the existing `qps_check_question_prior_usage()` helper when available.

## Workflow
1. Teaching/HOD create and activate a final blueprint.
2. COE opens Final Allotment and selects an active blueprint.
3. COE selects each slot and assigns an approved question from the same course.
4. Save persists assignments independently; it does not modify the blueprint definition or source question bank.

## Database
Run `database/migrate_coe_final_question_allotment.sql` against the application's configured database. The API also creates the table on first use for MySQL/MariaDB.

## Notes / validation boundary
- The allotment page reads slot definitions from `qps_final_blueprints.slots_config`.
- Existing `qps_final_blueprints` rows must have a valid JSON array (or an object containing a `slots` array) in `slots_config`.
- Slots with zero marks/unit or blank K/CO are treated as unspecified for matching.
- The feature does not generate/export a final DOCX paper yet; it persists the official slot-to-question allotment for subsequent paper generation.
