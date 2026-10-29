# COE Upload / Blueprint Fix – 2026-10-06

## Fixed

### 1. COE blueprint SQL schema mismatch
The `questions` table in this project uses:
- `unit_no`
- `sub_unit`
- `k_level`
- `co_level`
- `section_type`
- `marks`
- `question_text`

The COE blueprint previously referenced non-existent compatibility columns such as `q.co`, `q.subunit`, `q.unit`, and `q.question_type`. Those references are removed. `question_type` is returned as an empty compatibility field because it is not a physical column in the current schema.

### 2. Excel upload
Staff upload now accepts:
- `.xlsx`
- `.xls`
- `.csv`
- `.docx`
- `.pdf`

Legacy `.xls` is read through PhpSpreadsheet.

### 3. Editable question preview image upload
Every question in the staff upload preview now has an **Upload Image** button. An attached image is previewed under that question and can be removed.

### 4. Standard Word template
The DOCX template is no longer a data-grid/table. It uses continuous question numbers and individual question blocks:

- `Q.NO: 1`
- right-aligned `UNIT`
- right-aligned `SUB-UNIT`
- right-aligned `SECTION`
- right-aligned `K-LEVEL`
- right-aligned `CO`
- right-aligned `MARKS`
- right-aligned `QUESTION TYPE`
- `QUESTION:` text
- MCQ options where applicable
- optional image/diagram placeholder

Staff can directly edit the Word document and continue question numbering.

### 5. HOD Question Bank Blueprint pool visibility
After **Sync Uploaded Units**, the page now shows an **Uploaded Pool Inventory** containing every verified uploaded sub-unit and its question count. The editable blueprint rows remain separate, so syncing 25 sub-units does not incorrectly create 25 blueprint rows.

## Install

1. Back up your current project.
2. Replace the files from this ZIP into the project root.
3. Run:

```bat
cd C:\xampp\htdocs\Question-Paper-System-new
composer install --no-dev --optimize-autoloader
```

4. Restart Apache.
5. Clear browser cache and test:
   - `/modules/coe/blueprint.php`
   - `/modules/teaching/upload.php`
   - `/modules/teaching/download_template.php?format=docx`
   - `/modules/teaching/question_bank_blueprint.php?create=1`
