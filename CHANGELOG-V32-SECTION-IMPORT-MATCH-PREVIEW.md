# V32 — Section Import and Match Question Preview Fix

- Normalizes section labels such as `A`, `B`, `Section B`, and `SECTION-B`.
- Infers omitted marks from explicit paragraph/essay question types (5M/10M).
- Applies the same normalization at commit time so section data is stored consistently.
- Adds a Column A / Column B table and answer-key display in the COE bank-question inspector when matching-question data is stored structurally in `options_json`.
- Includes a targeted SQL repair script for already-imported `I25BI1CCT01` rows. Back up first and review results before committing.
- PHP syntax and browser/live database behavior must be validated in XAMPP.
