# V27.1 - Use Question Bank Blueprint ON/OFF

- Added `qbb_enabled` to `question_bank_blueprints`.
- HOD can turn Use Question Bank Blueprint ON/OFF from the QBB list.
- ON is allowed only after the QBB is PUBLISHED.
- Only one published QBB per course/context can be active at a time; enabling one disables older active QBBs for that context.
- Teaching staff only see published QBBs whose toggle is ON.
- COE QBB API only returns published + enabled QBBs.
- COE 30-question blueprint save is blocked with a clear message when Use QBB is OFF.
