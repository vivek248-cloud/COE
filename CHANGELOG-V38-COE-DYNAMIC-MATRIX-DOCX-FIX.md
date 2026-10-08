# V38 — COE Dynamic Question-Bank Matrix + Staff Simple DOCX Fix

## Fixed

- HCC Staff Simple DOCX files are now forced through the bundled staff-simple Python extractor first.
- If the native PHP DOCX parser returns only one question for a multi-question DOCX, the importer automatically retries with Python.
- `U23HI5CCT09_HCC_Staff_Simple_Question_Bank` extracts all 251 questions instead of only 1.
- Existing CSV extraction is unchanged and verified at 251 questions for the supplied standard CSV.

## COE Blueprint Matrix

- Matrix Unit/Sub-Unit rows are generated from the loaded question bank.
- All loaded sub-units are displayed, including Unit 5.4 and Unit 5.5 when present.
- Matrix cells always display their required marks.
- Assigned cells show the blueprint slot plus the actual selected Question Bank question and marks.
- Slot tracker circles show the assigned blueprint slot, selected bank question number and marks.
- Eligible Question Bank questions are filtered by exact Unit/Sub-Unit, Section, K-Level, question type and marks.
- A bank question must be selected before a populated matrix cell can be applied when a bank is loaded.
- Previous academic-year questions are excluded from the eligible list and blocked during client/server validation.
- Server-side save validation repeats the Unit/Sub-Unit, K-Level, section, type, marks and previous-year checks.
