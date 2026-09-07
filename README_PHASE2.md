# Phase 2 — COE Question Bank

This package adds the first working Question Bank module. Authentication is intentionally deferred.

1. Ensure the database is `question_paper_system_new`.
2. Run `database/schema_phase2.sql` in phpMyAdmin.
3. Copy the package contents into `C:\xampp\htdocs\Question-Paper-System-new\`.
4. Confirm `config/database.php` uses `question_paper_system_new`.
5. Open `/Question-Paper-System-new/` and click Question Bank.

CSV required headers:
`question_number,question_text,question_format,bloom_level,marks,unit,topic,option_a,option_b,option_c,option_d,correct_answer`
