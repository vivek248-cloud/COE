# V31 — COE Unit/Sub-Unit Blueprint Builder

- Removed manual individual-question selection from the COE blueprint builder.
- COE staff now select **Unit / Sub-Unit scopes** and drag them into Sections A, B, C and D.
- Each selected scope can be constrained by:
  - K-level(s)
  - CO-level(s)
  - Marks
  - Required question count
- The UI never displays question text or question numbers.
- Verified question-bank analysis shows only aggregate Unit/Sub-Unit information: total, eligible, previous-year blocked, K-levels, CO-levels and marks breakdown.
- Previous academic-year usage is checked from both generated-paper data and `qps_question_usage`.
- Previous-year exclusion is signature-aware, so a re-imported duplicate question cannot bypass the exclusion with a new database ID.
- Validation allows the remaining eligible questions. Example: if a scope has 3 matching questions and 1 was used in the previous year, 2 remain eligible; a requirement of 2 passes.
- Added distinct-question matching validation so overlapping Unit/Sub-Unit selections cannot reuse the same eligible question.
- `blueprints.php` redesigned for easier CRUD access and a clear two-step workflow: manage blueprint structure → build Unit/Sub-Unit selection.
