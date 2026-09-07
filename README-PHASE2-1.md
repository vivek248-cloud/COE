# Question Paper System — Phase 2.1

Run `database/schema_phase2_1.sql` after Phase 1. It only creates/extends Phase 2 tables and does not drop Phase 1 data.

Then run `database/test_data_phase2_1.sql`.

Development data:
- Department: CSE
- Program: BCA
- Course: BCA101 — Programming in C
- Academic Year: 2026-27
- Semester: 1
- Exam: Internal 1
- Existing teacher: teacher01

The test SQL never changes the teacher password.

Expected: teacher01 has an active assignment to BCA101 for 2026-27, and one Internal 1 question bank exists.
