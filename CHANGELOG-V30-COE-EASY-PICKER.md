# V30 — COE Easy Question Picker

## COE 30-question blueprint UI
- Replaced the dense MCQ/VSA/Essay matrix picker with a responsive requirement table.
- Requirements are grouped by Section and Marks, making Section A 1-mark questions, Section C, Section D, etc. easy to scan.
- Each row clearly shows Unit, Sub-Unit, Question Type, K-Level, CO, Marks, Pool, Required, Selected and a single **Select Questions** action.
- The question picker uses large checkboxes and highlighted selected rows.
- Existing duplicate protection and required-count validation remain active.
- The final 30-question total remains enforced server-side.

## Selection workflow
1. Sync the verified question pool.
2. Find the required Section/Marks group.
3. Choose the Unit/Sub-Unit row.
4. Click **Select Questions**.
5. Tick the required questions using the checkbox table.
6. Apply the selection.
7. Save & Lock the blueprint only when the total reaches 30.
