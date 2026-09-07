# Model Layer

The `models/` directory is the database/data-access layer for the Question Paper System.

## Included

- `Model.php` — shared PDO CRUD foundation
- `QuestionBank.php` — question-bank data access
- `Question.php` — question data access
- `Blueprint.php` — blueprint data access
- `GeneratedPaper.php` — generated-paper metadata access
- `AuditLog.php` — history/audit access
- `bootstrap.php` — loads all models

## Phase boundary

These classes are an architecture scaffold. The physical Phase 2 database schema will be finalized before the models are used by the live Question Bank pages. Authentication is intentionally not included.

Example:

```php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/bootstrap.php';

$questionBank = new QuestionBank($pdo);
$bank = $questionBank->find(1);
```
