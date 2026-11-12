V36.2 targeted patch — exam type compatibility

Fix:
- Older/manual question-bank drafts may not contain exam_type.
- During staff submission, the selected active final blueprint is now authoritative for exam_type when the draft has no saved value.
- If no blueprint ID is supplied, legacy drafts resolve an active blueprint using the other context fields and inherit its exam_type.
- Explicitly saved draft exam_type values are still strictly validated.

Install:
1. Back up your current project.
2. Extract this ZIP into C:\xampp\htdocs\Question-Paper-System-new\ and overwrite the matching file.
3. The included file path is api/workflow.php.
4. Retry submission from Teaching Staff.

Note:
The two Dean cards in the supplied screenshot are different courses (U23HI5CCT11 and U25HI1ECT01), not duplicate cards. No Dean rules code was changed.
