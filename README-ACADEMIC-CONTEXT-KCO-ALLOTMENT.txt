ACADEMIC CONTEXT + DEAN K/CO FINAL ALLOTMENT UPDATE
==================================================

Scope
-----
This update carries the Teaching Staff selections (Academic Year, Year of Programme,
Semester, Total Marks and Question Language) through the draft header, each draft
question, approved question-bank header, master questions and final blueprint.
COE final allotment resolves each slot's K-Level/CO-Level from question_rules and
only offers approved questions that match that exact pair and academic context.

Database
--------
1. Back up database hccweb.
2. Select hccweb in phpMyAdmin.
3. Import database/migrate_question_context_alignment.sql.
   The migration is designed to be re-runnable and adds/backfills the context columns.
   It also rebuilds the question_rules unique/lookup indexes using programme_year
   and total_marks. Review the migration output before proceeding.

Files
-----
Extract the patch ZIP into C:\xampp\htdocs\ and preserve the Question-Paper-System-new
folder structure. Replace the files in the patch. Do not replace config/config.php,
config/db.php or your local uploads/storage folders from another project copy.

After installation
------------------
1. Sign in as Teaching Staff and open Question Entry for an allocated course.
2. Select Academic Year, Year of Programme, Semester, Total Marks and Language.
3. Confirm the Dean rule status resolves before adding questions.
4. Add a question and verify its context in qps_question_bank_draft_questions.
5. Submit through Staff -> HOD -> Dean -> COE.
6. After COE approval, verify the context in question_banks and questions.
7. Open COE Final Question Slot Allocation. Q1 must show the K/CO pair saved for
   question_rules.question_number=1. Only matching context + K/CO questions should
   be offered; if none exist, the page displays an empty state.

Useful verification SQL
-----------------------
SELECT id, semester, academic_year, programme_year, course_type,
       question_number, co_level, k_level, programme_level, total_marks
FROM question_rules
ORDER BY academic_year DESC, total_marks DESC, programme_year, question_number;

SELECT id, paper_code, semester, academic_year, programme_year, total_marks, language
FROM qps_question_bank_drafts
ORDER BY id DESC LIMIT 20;

SELECT id, draft_id, paper_code, semester, academic_year, programme_year,
       total_marks, language, q_number, unit_no, sub_unit, k_level, co_level
FROM qps_question_bank_draft_questions
ORDER BY id DESC LIMIT 30;

SELECT id, bank_id, course_code, semester, academic_year, programme_year,
       total_marks, language, q_number, unit_no, sub_unit, k_level, co_level
FROM questions
ORDER BY id DESC LIMIT 30;

Assumptions / limitations
-------------------------
- question_rules retains the existing programme_level compatibility column; the
  selected Year of Programme is stored in programme_year and mirrored to
  programme_level for older code paths.
- course_type remains '*' in new Dean rule rows and is not part of rule lookup.
- Live MySQL/browser workflow tests were not possible in this environment. PHP
  syntax checks were run; run the verification SQL above after installing.
