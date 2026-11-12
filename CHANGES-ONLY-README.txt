UPDATED FILES ONLY

Replace these files in your existing Question-Paper-System-new folder:
- modules/teaching/upload.php
- modules/teaching/final_slot_allocation.php (NEW)
- includes/sidebar.php
- api/teaching_dean_templates.php
- api/teaching_slot_eligibility.php (NEW)
- api/save_draft_question.php

Changes:
1. Final Question Slot Allocation moved to its own Teaching Staff page.
2. Added sidebar navigation to the new page.
3. New page loads published Dean templates by course, semester, academic year, programme year and total marks.
4. Slots display Dean K-Level / CO-Level requirements and filter Units/Sub-units using academic context, language, K-Level and CO-Level.
5. Question Entry no longer requires the paper-level Total Marks; legacy DB field is retained as 0 for compatibility.
6. Existing approval workflow files are otherwise untouched.

PHP syntax check passed for all six changed PHP files.
