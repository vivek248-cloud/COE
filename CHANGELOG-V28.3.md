# V28.3 — QBB independent from uploads + COE final-30 from uploaded pool

- HOD Question Bank Blueprint no longer requires an uploaded question bank to create or publish.
- Removed the HOD-page uploaded-pool sync dependency.
- HOD QBB remains the validation rule used by staff when Use Question Bank Blueprint is ON.
- Staff upload Check Blueprint compares every configured Unit/Sub-Unit requirement against the uploaded questions and blocks submission on shortage.
- COE 30-question blueprint no longer requires a published/ON HOD QBB.
- COE Sync now reads the submitted/verified uploaded question bank directly and loads Unit, Sub-Unit, Section, Question Type, K-Level, CO, Marks and available counts.
- COE saves only after exactly 30 final question numbers are selected.
- COE server validation verifies each selected question number exists in the uploaded master pool and matches its selected row metadata.
