# V32 — paper_code compatibility and simple final slot picker

- Added a safe additive `paper_code` column migration for legacy `question_banks` schemas.
- Backfills the new alias from `course_code` or `papercode` where present; no ERP master table is changed.
- Simplified final question slot allocation into numbered question circles with section-specific colors.
- Unit and subunit dropdowns now derive only from questions already entered in the selected course draft.
- Selecting a unit changes the subunit list to only subunits present in saved draft questions.
- Empty draft question pool now shows a clear instruction rather than offering made-up Unit I–V / 1.1–5.4 values.
