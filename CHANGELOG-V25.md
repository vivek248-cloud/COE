# V25 – Extraction / Blueprint / Language Update

- Rebuilt DOCX extraction around Word numbering XML and document context.
- Section, K-level, CO-level, unit/sub-unit and section marks are inherited from the real document structure.
- Word alphabetic option lists are recovered, including the hidden option A label.
- `Key:`, `Answer:`, `Réponse:`, `விடை:` and Hindi answer-key labels are extracted.
- Metadata such as `CODE... LEVEL: K2` is removed from question text.
- Unnumbered instruction paragraphs are no longer promoted to fake questions.
- Course blueprint section marks can be passed into DOCX extraction.
- English/Tamil/French/Hindi language detection and manual switching are supported. Language switching never translates uploaded question text.
- Added Hindi XLSX/DOCX/CSV/JSON templates.
- Course-specific blueprint selection now shows course code, course pattern, recent uploaded-bank count, semester, academic year and exam type.
- Blueprint matrix loads actual uploaded sub-units instead of invented Unit 1–5 rows.
- MySQL/MariaDB `hccweb` is the only production database; SQLite fallback was removed.
- Question-bank append/replace persistence preserves existing question IDs so usage history remains valid.
- Fixed duplicate `language` SQL assignment that caused HY093 parameter errors.
- K6 maps to CO6.
- 50M NON-OBE / 75M OBE course pattern is derived from ERP course type/pattern.

# V26 – Standard Extraction / Cumulative Blueprint Sync / Admin Route Hardening

- Added a standard V5 question-bank schema for CSV/XLSX/DOCX with question type, options, answer key, image URL and LaTeX formula support.
- Improved XLSX extraction: detects the real header row even when title/institution rows appear above it; supports sparse cell references, shared strings and inline strings.
- Improved DOCX extraction: supports one-line `[UNIT] [SUB] [SECTION] [MARKS] [K] [CO] [TYPE]` metadata blocks and preserves embedded question images.
- Generated question-paper DOCX now embeds question diagrams/figures from imported data instead of dropping `image_url`.
- Blueprint Sync now aggregates the cumulative verified/submitted question pool across multiple uploads instead of reading only the newest bank row.
- Blueprint Sync displays the number of source banks and builds Unit/Sub-Unit rows from actual uploaded questions.
- Added an explicit **Sync Uploaded Question Pool** button and automatic initial sync when a course is already selected.
- Hidden all `/modules/admin/index.php` links from normal COE users. Admin master pages now require Super Admin / ERP staff and direct COE access redirects to the COE dashboard.
- COE users no longer receive ERP master CRUD privileges through `canEditERPMasters()`.
