# Blueprint slot editor and draft question numbering update

## Updated files
- `modules/teaching/upload.php`: replaced the all-slots card grid with a left-side section-colored question-circle navigator and a right-side editor for only the selected slot. Existing slot values, default template data, update handlers, and `saveFinalBlueprintSlots()` payload are preserved.
- `api/save_draft_question.php`: compacts draft `q_number` values after deletion and before adding a new question. The next number is count + 1. Stable `fixed_q_no` values are deliberately not rewritten because they act as paper-wide identifiers and may be referenced by usage/history records.

## Assumptions
- The editable “Final Question Slot Allocation (Master Blueprint Specification)” is the Teaching/HOD tab inside `modules/teaching/upload.php` (`slotsGridContainer`).
- `modules/coe/final_allotment.php` is the COE assignment screen. Its blueprint requirements remain read-only; COE selects approved questions for the saved slots.
- Draft question numbering is represented by `qps_question_bank_draft_questions.q_number`; `fixed_q_no` remains a stable identity.
- MySQL/MariaDB is the primary runtime. The delete/add renumber operations use transactions and row locking.

No database schema change is required.
