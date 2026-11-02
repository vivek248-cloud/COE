# COE v4 PHP Importer — Windows/XAMPP Setup

## Production requirement

The production question-bank importer is **PHP-only**.

Python is not required for:
- DOCX extraction
- XLSX extraction
- CSV extraction
- JSON import
- structured v4 validation
- duplicate fingerprinting
- question-bank JSON versioning

The project keeps the older Python extractor only as a development/regression reference. It is not invoked by the production upload path.

## PHP packages

Run from the project root:

```powershell
composer install --no-dev --optimize-autoloader
```

The project uses:
- PHPWord for Office Open XML/DOCX support
- PhpSpreadsheet for spreadsheet processing

The importer also contains a deterministic native OOXML fallback so the structured v4 upload remains available when Composer packages are temporarily unavailable.

PHPWord requires PHP plus DOM, JSON, XML Parser and XMLWriter extensions. See the official PHPWord installation documentation for the supported installation methods. urlPHPWord installation documentationhttps://phpoffice.github.io/PHPWord/install.html

## Required PHP extensions

Enable in XAMPP `php.ini`:

- extension=zip
- extension=dom
- extension=xml
- extension=xmlwriter
- extension=mbstring
- extension=fileinfo
- extension=gd (for image workflows)

Restart Apache after changing `php.ini`.

## Database hardening

Run:

```sql
database/migrate_qps_v4_hardening.sql
```

The application also provisions the additive v4 objects through `qps_ensure_aux_schema()`.

## Data architecture

Relational tables are the query source:
- question_banks
- questions
- answer_keys
- blueprints / blueprint matrix tables
- generated_papers / generated_paper_questions
- question usage/history

JSON is the immutable interchange and reproducibility layer:
- `question_banks.questions_json` = current snapshot
- `qps_bank_versions.questions_json` = historical snapshots
- parser/import diagnostics are stored separately

Never use a JSON blob as the only searchable database source.

## v4 staff template

Canonical fields:

1. Q.No
2. Unit
3. Sub-Unit
4. K-Level
5. CO
6. Section
7. Marks
8. Question

Explicit staff values are authoritative. The importer does not derive CO from K-Level for v4 documents.

## Failure behavior

A malformed v4 row is not silently repaired. The preview receives diagnostics and the commit endpoint rejects missing required v4 fields.

This is intentional: preserving bad source data is safer than silently changing academic classification.
