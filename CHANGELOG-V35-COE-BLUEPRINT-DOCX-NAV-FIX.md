# V35 — COE Blueprint Selection, DOCX/CSV Import & Role Navigation Fixes

## Fixed
- Fixed the PHP parse error in `modules/coe/blueprints.php` caused by the malformed `data-search` attribute.
- Added an explicit Blueprint Context selector to `modules/coe/blueprint.php`. The matrix no longer silently opens against the first blueprint.
- Added a clear `No Blueprint Selected` state until a valid blueprint is chosen.
- Blueprint, paper, semester and academic-year context are retained when changing matrix filters.
- Invalid blueprint IDs now fall back safely to the selection screen.
- DOCX import now automatically falls back to the bundled `includes/docx_extractor.py` when XAMPP/PHP is missing ZipArchive or DOM/XML.
- CSV remains supported through the existing PHP importer. Both DOCX and CSV are explicitly presented as first-class question-bank formats.
- COE Admin Settings navigation now shows only `Settings & Translation`; ERP/Super Admin continues to see the full admin navigation.
- COE-only admin view no longer shows ERP backup/shuffler header actions.

## Validation
- PHP lint passed for the modified COE blueprint, blueprint manager, admin, and question importer files.
- Real `U23HI5CCT09_HCC_Staff_Simple_Question_Bank(1).docx` parsed successfully through the Python fallback: 331 logical questions.
- Real `U23HI5CCT09_Standard_Question_Bank(1).csv` parsed successfully: 251 questions.
