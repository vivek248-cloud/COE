# Question Paper System — Phase 1

Fresh foundation for the new Question Paper Management and Generation System.

## Phase 1 scope

- PHP application shell
- PDO database connection
- Central configuration
- Shared helpers
- COE portal visual shell
- Responsive sidebar
- Navbar and footer
- Secure output escaping
- Storage directories
- Baseline database configuration

## Authentication

Authentication is intentionally deferred. Do not add login/session authorization until the final authentication phase.

## Requirements

- PHP 8.1+
- MySQL/MariaDB
- Apache with PHP enabled
- PDO MySQL extension

## Setup

1. Copy this folder into your web root.
2. Create the database using `database/schema_phase1.sql`.
3. Edit `config/database.php` with your database credentials.
4. If the project folder name changes, update `BASE_URL` in `config/config.php`.
5. Open `http://localhost/question-paper-system/`.

## Next phase

Phase 2 will build the COE Question Bank module and database tables.
