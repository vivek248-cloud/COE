<?php
/**
 * Database Connection Module
 * Connects to MySQL/MariaDB database `hcc` / `hccweb`,
 * with seamless SQLite fallback for local development & evaluation.
 */

require_once __DIR__ . '/config.php';

function getDBConnection() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+05:30'"
        ]);
        return $pdo;
    } catch (Throwable $e) {
        http_response_code(500);
        die("<h3>Database Connection Error</h3><p>Could not connect to the configured MySQL/MariaDB database <strong>" .
            htmlspecialchars(DB_NAME) . "</strong>.</p><p>Error: " . htmlspecialchars($e->getMessage()) .
            "</p><p>This production build intentionally does not fall back to SQLite or another database. " .
            "Please start MySQL/MariaDB and verify config/config.php.</p>");
    }
}
