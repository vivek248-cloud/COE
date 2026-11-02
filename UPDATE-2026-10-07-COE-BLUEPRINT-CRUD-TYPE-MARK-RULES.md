# COE Blueprint CRUD + Question Type/Marks Rules

## New pages
- `modules/coe/blueprints.php` — COE blueprint CRUD manager.
- `modules/coe/blueprint.php` — slot-based question selector driven by the selected blueprint.

## Blueprint rule model
Each section stores ordered rule groups:
- count
- question type
- marks
- allowed K-levels

Rules are applied in order to section slots. This makes patterns such as:
- Section A slots 1–8: MCQ
- Section A slot 9: MATCH
- Section A slot 10: AR
- Section A slots 11–20: VSA
possible without relying on K-level alone.

Marks are validated per slot. Therefore a 2-mark question cannot be dropped into a 1-mark slot, even when its K-level is allowed.

## Uploaded-paper verification
The uploaded `U25HI1CCT01` paper was checked. Section A is `20 x 1 = 20`; questions 9 and 10 are Match and Assertion/Reason respectively, and questions 11–20 are short-answer questions. The default CRUD blueprint rule pattern reflects that structure.

Question type is inferred when the database question row does not have a dedicated `question_type` column:
- Match/Column A + Column B -> MATCH
- Assertion + Reason -> AR
- option-based questions -> MCQ
- 1/2 mark short-answer questions -> VSA
- 5 marks -> PARAGRAPH
- 10 marks -> ESSAY, or MAP when the question is a map question
