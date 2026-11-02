# COE Final Allotment UI and Draft Question Numbering

## COE Final Allotment
- Replaced the long list of slot cards with section-grouped numbered circles on the left.
- Added a right-side selected-slot panel showing the read-only blueprint requirements and current assignment.
- The approved question picker now appears in the right panel and filters by the selected slot's unit, subunit, type, marks, K-level and CO-level.
- A question already assigned to another slot is excluded from the available picker.
- Added a remove-assignment action for the selected slot.
- Kept the existing save API and database allotment model; blueprint structure remains read-only.

## Teaching question numbering
- The API continues assigning new draft `q_number` values as `MAX(q_number) + 1` within the draft.
- Deleting a question does not renumber remaining questions; a deleted middle number remains a gap.
- The entry form now displays the next available question number based on the highest existing draft question number.
- The save response includes `next_question_number` for clients that need to refresh the displayed counter.

## Validation
- PHP lint: 58 PHP files checked, zero syntax errors.
- COE page JavaScript syntax checked with Node.js.
- No database migration is required for these UI and numbering changes.
- End-to-end testing against the user's local XAMPP database remains to be performed.
