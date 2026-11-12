Question Paper System — targeted Section B / Section C correction

Only these six existing files are changed:
- modules/teaching/upload.php
- includes/question_rules.php
- api/save_final_blueprint_slots.php
- api/coe_final_allotment.php
- modules/coe/final_allotment.php
- api/coe_paper_sets.php

Changes:
1. Section B Q21–Q25 now shows a/b either-or alternatives (Q21.a, Q21.b ... Q25.a, Q25.b). Each pair shares the same Dean rule question number and uses distinct slot keys for COE allotment.
2. Section C explicitly shows that 2 questions are to be answered from 4 offered questions.
3. Effective paper marks are calculated from section answer counts/section marks, not by adding every offered question. Standard OBE total: Section A 20 + Section B 25 + Section C 20 + Section D 10 = 75 marks.
4. Saving the final blueprint stores the effective 75-mark total instead of the raw offered-slot sum (95).
5. COE allotment and generated paper numbering preserve the a/b labels.

Install:
1. Back up the six existing files.
2. Extract this ZIP into C:\xampp\htdocs\Question-Paper-System-new, preserving the folder structure and replacing only these six files.
3. Open the teaching upload page, select the course, then open Final Question Slot Allocation.
4. Confirm Q21.a/Q21.b through Q25.a/Q25.b, Section C “2 to answer from 4”, and Effective Paper Marks 75.
5. Save the final blueprint. An older 30-slot saved blueprint is expanded in the page when loaded; save it to persist the a/b alternatives.

No SQL migration, table change, or unrelated module change is included.

Validation: PHP syntax checks passed for all six changed files. JavaScript syntax check passed for the teaching upload page. A local logic check produced 35 options (20 A + 10 B alternatives + 4 C + 1 D) and 75 effective marks. Live MySQL/COE generation has not been tested here.
