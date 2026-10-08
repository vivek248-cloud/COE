# V28.5 — QBB Gate + COE Final-30 Matrix

## Staff Upload
- Added a visible, HOD-controlled **Use Question Bank Blueprint ON/OFF** indicator on the upload page.
- Staff cannot override the HOD setting.
- When ON, the staff upload is gated by an exact QBB validation.
- Validation compares Unit, Sub-Unit, Section, Question Type, K-Level, CO-Level and Marks.
- Match table now shows required count, exact uploaded matches, matched question numbers and MATCH/SHORTAGE result.

## HOD Question Bank Blueprint
- Added CO-Level to each QBB row.
- Improved Add Blueprint Row button and made it explicitly global.
- QBB remains independent of the uploaded question pool.

## COE Final 30
- Removed the dependency on HOD QBB publication for the COE final-30 selection workflow.
- COE selects only from the verified uploaded question pool.
- Reworked the final-30 display into a matrix matching the supplied reference structure:
  Unit/Sub-Unit, MCQ K1/K2/K3, AR K2, MATCH K1, VSA K1/K2/K3/K4, PARA, ESSAY, COMP and TOTAL.
- Picked question numbers are highlighted inside matrix cells.
- Matrix cells open the responsive question picker.
- Fixed the unit/sub-unit cumulative count validation bug caused by treating API arrays as associative maps.
- Server-side save validation uses normalized Unit/Sub-Unit pool maps.

## Validation
- PHP syntax checked for all changed PHP files.
