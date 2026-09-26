<?php
/**
 * Database SQL Dump & Backup Exporter
 * Holy Cross College (Autonomous) QPS
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireCOE();
$pdo = getDBConnection();

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="hcc_backup_' . date('Y_m_d_His') . '.sql"');

echo "-- ==========================================================\n";
echo "-- Holy Cross College (Autonomous) Examination Management System\n";
echo "-- Database Dump: " . DB_NAME . "\n";
echo "-- Generated At: " . date('Y-m-d H:i:s') . "\n";
echo "-- ==========================================================\n\n";
echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

$tables = [];
$stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $tables[] = $row[0];
}

foreach ($tables as $table) {
    echo "-- --------------------------------------------------------\n";
    echo "-- Table structure for table `$table`\n";
    echo "-- --------------------------------------------------------\n";
    echo "DROP TABLE IF EXISTS `$table`;\n";
    $createStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
    echo $createStmt[1] . ";\n\n";

    echo "-- Dumping data for table `$table`\n";
    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        $cols = array_keys($rows[0]);
        $escapedCols = array_map(function($c) { return "`$c`"; }, $cols);
        $colList = implode(', ', $escapedCols);

        $chunkSize = 100;
        $chunks = array_chunk($rows, $chunkSize);
        foreach ($chunks as $chunk) {
            $valRows = [];
            foreach ($chunk as $r) {
                $vals = [];
                foreach ($r as $val) {
                    if ($val === null) {
                        $vals[] = "NULL";
                    } else {
                        $vals[] = $pdo->quote($val);
                    }
                }
                $valRows[] = "(" . implode(', ', $vals) . ")";
            }
            echo "INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $valRows) . ";\n";
        }
        echo "\n";
    }
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";
echo "-- Dump completed on " . date('Y-m-d H:i:s') . "\n";
exit;
