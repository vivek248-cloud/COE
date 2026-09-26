<?php
/**
 * System helpers and schema verification for Holy Cross College QPS
 */

/**
 * Standard Exam Types for Holy Cross College
 */
function hcc_exam_types(): array {
    return [
        'Internal 1' => 'Internal 1 (CIA-I)',
        'Internal 2' => 'Internal 2 (CIA-II)',
        'Odd Semester End Examination' => 'Odd Semester End Examination (Nov/Dec)',
        'Even Semester End Examination' => 'Even Semester End Examination (Apr/May)',
        'Year' => 'Year (Annual / Supplementary)'
    ];
}

function hcc_sem_num(string $sem): int {
    if (preg_match('/(?:sem(?:ester)?[\s\-_]*)?([1-8])/i', $sem, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/SEMESTER[\s\-_]*VIII/i', $sem)) return 8;
    if (preg_match('/SEMESTER[\s\-_]*VII/i', $sem)) return 7;
    if (preg_match('/SEMESTER[\s\-_]*VI/i', $sem)) return 6;
    if (preg_match('/SEMESTER[\s\-_]*V/i', $sem)) return 5;
    if (preg_match('/SEMESTER[\s\-_]*IV/i', $sem)) return 4;
    if (preg_match('/SEMESTER[\s\-_]*III/i', $sem)) return 3;
    if (preg_match('/SEMESTER[\s\-_]*II/i', $sem)) return 2;
    if (preg_match('/SEMESTER[\s\-_]*I/i', $sem)) return 1;
    return 1;
}

function hcc_roman_num(int $n): string {
    $map = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII'];
    return $map[$n] ?? 'I';
}

function hcc_school_name(string $deptCode, string $deptName = ''): string {
    $d = strtoupper($deptCode . ' ' . $deptName);
    if (preg_match('/(TAMIL|ENGLISH|FRENCH|HINDI|HISTORY|ECONOMICS|HUMANITIES|LIT)/i', $d)) {
        return 'SCHOOL OF HUMANITIES';
    }
    if (preg_match('/(MATH|PHYSIC|CHEM|ELECTRONIC|PHYSICAL)/i', $d)) {
        return 'SCHOOL OF PHYSICAL SCIENCES';
    }
    if (preg_match('/(COMP|CS|AI|DATA|BCA|IT|COMPUTATIONAL|INFORMATIC)/i', $d)) {
        return 'SCHOOL OF COMPUTATIONAL SCIENCES';
    }
    if (preg_match('/(BOT|ZOO|BIO|REHAB|LIFE)/i', $d)) {
        return 'SCHOOL OF LIFE SCIENCES';
    }
    if (preg_match('/(COMMERCE|BBA|MANAGEMENT|BUSINESS|FINANCE|ACCOUNTING)/i', $d)) {
        return 'SCHOOL OF MANAGEMENT STUDIES';
    }
    return !empty($deptName) ? 'DEPARTMENT OF ' . strtoupper($deptName) : 'SCHOOL OF HUMANITIES';
}

function hcc_degree_exam_line(string $degreeLevel, string $sem, string $examSession = 'NOVEMBER 2026'): string {
    $semNum = hcc_sem_num($sem);
    $semRoman = hcc_roman_num($semNum);
    $isPG = preg_match('/PG|M\.SC|M\.A|M\.COM|M\.B\.A|M\.C\.A|MASTER/i', $degreeLevel);
    
    // Calculate year: Sem 1,2 = I; Sem 3,4 = II; Sem 5,6 = III; Sem 7,8 = IV
    $yearNum = (int)ceil($semNum / 2);
    $yearRoman = hcc_roman_num($yearNum);
    
    $degLabel = $isPG ? "{$yearRoman} P.G. DEGREE EXAMINATION" : "{$yearRoman} U.G. DEGREE EXAMINATION";
    $session = strtoupper(trim($examSession ?: 'NOVEMBER 2026'));
    
    return "{$degLabel}, SEMESTER-{$semRoman}, {$session}";
}

function hcc_course_part_line(string $deptCode, string $paperCode, string $courseTitle): string {
    $codeUpper = strtoupper($paperCode);
    $deptUpper = strtoupper($deptCode);
    $titleUpper = strtoupper(trim($courseTitle));

    // Part I Languages (Tamil, French, Hindi)
    if (strpos($codeUpper, 'TL') !== false || strpos($deptUpper, 'TAMIL') !== false || strpos($titleUpper, 'TAMIL') !== false) {
        return "PART I - TAMIL: {$titleUpper}";
    }
    if (strpos($codeUpper, 'FR') !== false || strpos($deptUpper, 'FRENCH') !== false || strpos($titleUpper, 'FRENCH') !== false) {
        return "PART I - FRENCH: {$titleUpper}";
    }
    if (strpos($codeUpper, 'HN') !== false || strpos($deptUpper, 'HINDI') !== false || strpos($titleUpper, 'HINDI') !== false) {
        return "PART I - HINDI: {$titleUpper}";
    }

    // Part II English
    if (strpos($codeUpper, 'EL') !== false || strpos($deptUpper, 'ENGLISH') !== false || strpos($titleUpper, 'GENERAL ENGLISH') !== false) {
        return "PART II - ENGLISH: {$titleUpper}";
    }

    // Part IV Skill / Non-Major
    if (strpos($codeUpper, 'SBE') !== false || strpos($codeUpper, 'NME') !== false || strpos($titleUpper, 'SKILL') !== false) {
        return "PART IV - SKILL BASED: {$titleUpper}";
    }

    // Part III Major / Core / Allied (e.g. Mathematics, Computer Science, Commerce, Physics, etc.)
    $discipline = !empty($deptUpper) ? $deptUpper : 'MAJOR';
    $discipline = preg_replace('/^DEPARTMENT OF /i', '', $discipline);
    return "PART III - {$discipline}: {$titleUpper}";
}

/**
 * Intelligently determines the End-Semester Examination maximum marks and course category.
 * In Holy Cross College ERP, the `courses.maxmark` column often records the 50M Internal component.
 * Theory End-Semester written examination question papers are set for 75 Marks (standard OBE) or 100 Marks.
 * Practical Lab examination question papers are set for 50 Marks.
 */
function hcc_course_exam_info(array $c): array {
    $title = $c['coursetitle'] ?? '';
    $pattern = strtoupper($c['qpattern'] ?? '');
    $type = strtoupper((string)($c['type'] ?? ($c['course_type'] ?? '')));
    $dbMax = (int)($c['maxmark'] ?? 0);

    $isPractical = (stripos($title, 'Lab') !== false || stripos($title, 'Practical') !== false || strpos($pattern, 'PRACTICAL') !== false || strpos($pattern, 'LAB') !== false);
    $isInternship = (stripos($title, 'Internship') !== false || stripos($title, 'Project') !== false || strpos($pattern, 'INTERNSHIP') !== false);
    $isExplicitNonObe = (strpos($type, 'NON') !== false);

    if ($isPractical) {
        return [
            'type_label' => 'Practical Lab (50M)',
            'exam_marks' => 50,
            'marks_label' => '50M',
            'is_practical' => true,
            'is_theory' => false,
            'is_obe' => false,
            'badge_class' => 'bg-emerald-50 text-emerald-800 border-emerald-200'
        ];
    }
    if ($isInternship) {
        return [
            'type_label' => 'Project / Viva (50M)',
            'exam_marks' => 50,
            'marks_label' => '50M',
            'is_practical' => false,
            'is_theory' => false,
            'is_obe' => false,
            'badge_class' => 'bg-purple-50 text-purple-800 border-purple-200'
        ];
    }
    if ($isExplicitNonObe) {
        return [
            'type_label' => 'NON-OBE (50M)',
            'exam_marks' => 50,
            'marks_label' => '50M',
            'is_practical' => false,
            'is_theory' => true,
            'is_obe' => false,
            'badge_class' => 'bg-amber-50 text-amber-800 border-amber-300'
        ];
    }

    // Theory pattern is driven by the ERP `type`/`qpattern` value. OBE defaults
    // to 75M; a course explicitly marked OBE100 may use 100M. NON-OBE is always 50M.
    if (preg_match('/(?:NON[-_ ]?OBE|NONOBE)/i', $type . ' ' . $pattern)) {
        return [
            'type_label' => 'NON-OBE (50M)', 'exam_marks' => 50, 'marks_label' => '50M',
            'is_practical' => false, 'is_theory' => true, 'is_obe' => false,
            'badge_class' => 'bg-amber-50 text-amber-800 border-amber-300'
        ];
    }
    $examMarks = preg_match('/(?:OBE\s*100|100M|100\s*MARKS)/i', $type . ' ' . $pattern) ? 100 : 75;
    $label = $examMarks . 'M';

    return [
        'type_label' => ($examMarks === 100) ? 'OBE Theory (100M)' : 'OBE Theory (75M)',
        'exam_marks' => $examMarks,
        'marks_label' => $label,
        'is_practical' => false,
        'is_theory' => true,
        'is_obe' => true,
        'badge_class' => 'bg-indigo-50 text-indigo-700 border-indigo-200'
    ];
}

function qps_table_exists(PDO $pdo, string $tableName): bool {
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
            $st->execute([$tableName]);
            return (bool)$st->fetchColumn();
        } else {
            $dbname = defined('DB_NAME') ? DB_NAME : '';
            if (!empty($dbname)) {
                $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
                $st->execute([$dbname, $tableName]);
            } else {
                $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?");
                $st->execute([$tableName]);
            }
            return (bool)$st->fetchColumn();
        }
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if a column exists in a given table
 */
function qps_column_exists(PDO $pdo, string $tableName, string $colName): bool {
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
            $cols = $pdo->query("PRAGMA table_info({$cleanTable})")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $r) {
                if (strcasecmp($r['name'] ?? '', $colName) === 0) return true;
            }
            return false;
        } else {
            $dbname = defined('DB_NAME') ? DB_NAME : '';
            if (!empty($dbname)) {
                $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $st->execute([$dbname, $tableName, $colName]);
            } else {
                $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?");
                $st->execute([$tableName, $colName]);
            }
            return (bool)$st->fetchColumn();
        }
    } catch (Throwable $e) {
        return false;
    }
}

function qps_ensure_aux_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        if ($driver === 'sqlite') {
            $cols = [];
            $res = $pdo->query("PRAGMA table_info(question_banks)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($res as $r) { $cols[strtolower($r['name'])] = true; }

            // Ensure blueprints table has matrix_config
            try {
                $bpCols = [];
                $resBp = $pdo->query("PRAGMA table_info(blueprints)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($resBp as $r) { $bpCols[strtolower($r['name'])] = true; }
                if (!isset($bpCols['matrix_config'])) {
                    $pdo->exec("ALTER TABLE blueprints ADD COLUMN matrix_config TEXT DEFAULT NULL");
                }
            } catch (Exception $e) {}

            // Ensure questions table has sub_unit, options_json, answer_key, language
            try {
                $qCols = [];
                $resQ = $pdo->query("PRAGMA table_info(questions)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($resQ as $r) { $qCols[strtolower($r['name'])] = true; }
                if (!isset($qCols['sub_unit'])) $pdo->exec("ALTER TABLE questions ADD COLUMN sub_unit TEXT DEFAULT '1.1'");
                if (!isset($qCols['options_json'])) $pdo->exec("ALTER TABLE questions ADD COLUMN options_json TEXT DEFAULT NULL");
                if (!isset($qCols['answer_key'])) $pdo->exec("ALTER TABLE questions ADD COLUMN answer_key TEXT DEFAULT NULL");
                if (!isset($qCols['language'])) $pdo->exec("ALTER TABLE questions ADD COLUMN language TEXT DEFAULT 'en'");
            } catch (Exception $e) {}

            $needed = [
                'root_bank_id' => 'INTEGER DEFAULT NULL',
                'version_no' => 'INTEGER DEFAULT 1',
                'source_format' => 'VARCHAR(50) DEFAULT NULL',
                'source_file_name' => 'VARCHAR(255) DEFAULT NULL',
                'source_path' => 'VARCHAR(500) DEFAULT NULL',
                'archive_path' => 'VARCHAR(500) DEFAULT NULL',
                'language' => 'VARCHAR(20) DEFAULT "en"',
                'hod_reviewed_by' => 'VARCHAR(100) DEFAULT NULL',
                'hod_reviewed_at' => 'VARCHAR(50) DEFAULT NULL',
                'hod_status' => 'VARCHAR(50) DEFAULT "pending"',
                'ocr_language' => 'VARCHAR(50) DEFAULT NULL',
                'ocr_used' => 'VARCHAR(10) DEFAULT 0',
                'content_hash' => 'VARCHAR(64) DEFAULT NULL',
                'locked_at' => 'VARCHAR(50) DEFAULT NULL',
                'reviewed_by' => 'VARCHAR(100) DEFAULT NULL',
                'reviewed_at' => 'VARCHAR(50) DEFAULT NULL',
                'blueprint_id' => 'INTEGER DEFAULT NULL',
                'school_name' => 'VARCHAR(255) DEFAULT NULL',
                'part_type' => 'VARCHAR(50) DEFAULT NULL'
            ];

            foreach ($needed as $c => $defn) {
                if (!isset($cols[$c])) {
                    $pdo->exec("ALTER TABLE question_banks ADD COLUMN $c $defn");
                }
            }

            // Create answer_keys table
            $pdo->exec("CREATE TABLE IF NOT EXISTS answer_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_code TEXT NOT NULL,
                question_id INTEGER DEFAULT NULL,
                bank_id INTEGER DEFAULT NULL,
                q_number INTEGER DEFAULT NULL,
                unit_no INTEGER DEFAULT 1,
                sub_unit TEXT DEFAULT '1.1',
                section_type TEXT DEFAULT 'SECTION-A',
                k_level TEXT DEFAULT 'K1',
                co_level TEXT DEFAULT 'CO1',
                answer_key TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            // Create question_usage_history table
            $pdo->exec("CREATE TABLE IF NOT EXISTS question_usage_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                paper_code TEXT NOT NULL,
                question_id INTEGER NOT NULL,
                paper_id INTEGER DEFAULT NULL,
                unit_no INTEGER DEFAULT 1,
                sub_unit TEXT DEFAULT '1.1',
                k_level TEXT DEFAULT 'K1',
                academic_year TEXT DEFAULT NULL,
                exam_session TEXT DEFAULT NULL,
                picked_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS qps_bank_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bank_id INTEGER NOT NULL,
                version_no INTEGER NOT NULL DEFAULT 1,
                questions_json TEXT NOT NULL,
                source_file_name VARCHAR(255) DEFAULT NULL,
                source_format VARCHAR(30) DEFAULT NULL,
                source_path VARCHAR(500) DEFAULT NULL,
                ocr_language VARCHAR(100) DEFAULT NULL,
                ocr_used INTEGER NOT NULL DEFAULT 0,
                content_hash VARCHAR(64) DEFAULT NULL,
                created_by VARCHAR(100) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS qps_upload_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bank_id INTEGER DEFAULT NULL,
                staff_code VARCHAR(100) DEFAULT NULL,
                action VARCHAR(50) NOT NULL,
                source_file_name VARCHAR(255) DEFAULT NULL,
                source_format VARCHAR(30) DEFAULT NULL,
                source_path VARCHAR(500) DEFAULT NULL,
                question_count INTEGER NOT NULL DEFAULT 0,
                ocr_used INTEGER NOT NULL DEFAULT 0,
                message TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS qps_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_by VARCHAR(100)
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS qps_audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor VARCHAR(100),
                role VARCHAR(50),
                action VARCHAR(100),
                entity_type VARCHAR(50),
                entity_id VARCHAR(100),
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            // MySQL / MariaDB
            $dbname = defined('DB_NAME') ? DB_NAME : '';
            $query = !empty($dbname) 
                ? "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$dbname}' AND TABLE_NAME = 'question_banks'"
                : "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'question_banks'";
            $res = $pdo->query($query)->fetchAll(PDO::FETCH_COLUMN);
            $cols = array_map('strtolower', $res);

            // Ensure blueprints table has matrix_config
            try {
                $queryBp = !empty($dbname) 
                    ? "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$dbname}' AND TABLE_NAME = 'blueprints'"
                    : "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'blueprints'";
                $resBp = $pdo->query($queryBp)->fetchAll(PDO::FETCH_COLUMN);
                $bpCols = array_map('strtolower', $resBp);
                if (!in_array('matrix_config', $bpCols, true)) {
                    $pdo->exec("ALTER TABLE blueprints ADD COLUMN `matrix_config` LONGTEXT NULL");
                }
            } catch (Exception $e) {}

            // Ensure questions table has sub_unit, options_json, answer_key, language
            try {
                $queryQ = !empty($dbname) 
                    ? "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$dbname}' AND TABLE_NAME = 'questions'"
                    : "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'questions'";
                $resQ = $pdo->query($queryQ)->fetchAll(PDO::FETCH_COLUMN);
                $qCols = array_map('strtolower', $resQ);
                if (!in_array('sub_unit', $qCols, true)) $pdo->exec("ALTER TABLE `questions` ADD COLUMN `sub_unit` VARCHAR(50) DEFAULT '1.1'");
                if (!in_array('options_json', $qCols, true)) $pdo->exec("ALTER TABLE `questions` ADD COLUMN `options_json` LONGTEXT NULL");
                if (!in_array('answer_key', $qCols, true)) $pdo->exec("ALTER TABLE `questions` ADD COLUMN `answer_key` TEXT NULL");
                if (!in_array('language', $qCols, true)) $pdo->exec("ALTER TABLE `questions` ADD COLUMN `language` VARCHAR(20) DEFAULT 'en'");
            } catch (Exception $e) {}

            $needed = [
                'root_bank_id' => 'INT NULL',
                'version_no' => 'INT NOT NULL DEFAULT 1',
                'source_format' => 'VARCHAR(50) NULL',
                'source_file_name' => 'VARCHAR(255) NULL',
                'source_path' => 'VARCHAR(500) NULL',
                'archive_path' => 'VARCHAR(500) NULL',
                'language' => "VARCHAR(20) DEFAULT 'en'",
                'hod_reviewed_by' => 'VARCHAR(100) NULL',
                'hod_reviewed_at' => 'DATETIME NULL',
                'hod_status' => "VARCHAR(50) DEFAULT 'pending'",
                'ocr_language' => 'VARCHAR(50) NULL',
                'ocr_used' => "VARCHAR(10) DEFAULT '0'",
                'content_hash' => 'VARCHAR(64) NULL',
                'locked_at' => 'DATETIME NULL',
                'reviewed_by' => 'VARCHAR(100) NULL',
                'reviewed_at' => 'DATETIME NULL',
                'blueprint_id' => 'INT NULL',
                'school_name' => 'VARCHAR(255) NULL',
                'part_type' => 'VARCHAR(50) NULL'
            ];

            foreach ($needed as $c => $defn) {
                if (!in_array(strtolower($c), $cols, true)) {
                    $pdo->exec("ALTER TABLE question_banks ADD COLUMN `{$c}` {$defn}");
                }
            }

            // Create answer_keys table
            $pdo->exec("CREATE TABLE IF NOT EXISTS `answer_keys` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `course_code` varchar(100) NOT NULL,
                `question_id` int DEFAULT NULL,
                `bank_id` int DEFAULT NULL,
                `q_number` int DEFAULT NULL,
                `unit_no` int DEFAULT 1,
                `sub_unit` varchar(50) DEFAULT '1.1',
                `section_type` varchar(50) DEFAULT 'SECTION-A',
                `k_level` varchar(20) DEFAULT 'K1',
                `co_level` varchar(20) DEFAULT 'CO1',
                `answer_key` text NOT NULL,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ak_course` (`course_code`),
                KEY `idx_ak_qid` (`question_id`),
                KEY `idx_ak_bank` (`bank_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Create question_usage_history table
            $pdo->exec("CREATE TABLE IF NOT EXISTS `question_usage_history` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `paper_code` varchar(100) NOT NULL,
                `question_id` int NOT NULL,
                `paper_id` int DEFAULT NULL,
                `unit_no` int DEFAULT 1,
                `sub_unit` varchar(50) DEFAULT '1.1',
                `k_level` varchar(20) DEFAULT 'K1',
                `academic_year` varchar(50) DEFAULT NULL,
                `exam_session` varchar(100) DEFAULT NULL,
                `picked_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_quh_paper` (`paper_code`),
                KEY `idx_quh_qid` (`question_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `qps_bank_versions` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `bank_id` int NOT NULL,
              `version_no` int NOT NULL DEFAULT '1',
              `questions_json` longtext NOT NULL,
              `source_file_name` varchar(255) DEFAULT NULL,
              `source_format` varchar(30) DEFAULT NULL,
              `source_path` varchar(500) DEFAULT NULL,
              `ocr_language` varchar(100) DEFAULT NULL,
              `ocr_used` tinyint(1) NOT NULL DEFAULT '0',
              `content_hash` char(64) DEFAULT NULL,
              `created_by` varchar(100) DEFAULT NULL,
              `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_qps_bv_bank` (`bank_id`),
              KEY `idx_qps_bv_hash` (`content_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `qps_upload_history` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `bank_id` int DEFAULT NULL,
              `staff_code` varchar(100) DEFAULT NULL,
              `action` varchar(50) NOT NULL,
              `source_file_name` varchar(255) DEFAULT NULL,
              `source_format` varchar(30) DEFAULT NULL,
              `source_path` varchar(500) DEFAULT NULL,
              `question_count` int NOT NULL DEFAULT '0',
              `ocr_used` tinyint(1) NOT NULL DEFAULT '0',
              `message` text,
              `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_qps_uh_bank` (`bank_id`),
              KEY `idx_qps_uh_staff` (`staff_code`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `qps_settings` (
                `setting_key` varchar(100) NOT NULL,
                `setting_value` text,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `updated_by` varchar(100) DEFAULT NULL,
                PRIMARY KEY (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `qps_audit_log` (
                `id` int NOT NULL AUTO_INCREMENT,
                `actor` varchar(100) DEFAULT NULL,
                `role` varchar(50) DEFAULT NULL,
                `action` varchar(100) DEFAULT NULL,
                `entity_type` varchar(50) DEFAULT NULL,
                `entity_id` varchar(100) DEFAULT NULL,
                `details` text,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

    } catch (Throwable $e) {
        $done = false;
        throw new RuntimeException('QPS database migration failed: ' . $e->getMessage(), 0, $e);
    }
    $done = true;
}

function qps_setting(PDO $pdo, string $key, $default = null) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM qps_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function qps_audit(PDO $pdo, string $action, string $entityType = '', string $entityId = '', array $details = []): void {
    $u = $_SESSION['user'] ?? [];
    try {
        $st = $pdo->prepare("INSERT INTO qps_audit_log(actor, role, action, entity_type, entity_id, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $st->execute([
            $u['staff_code'] ?? ($u['name'] ?? 'SYSTEM'),
            $u['role'] ?? 'SYSTEM',
            $action,
            $entityType,
            $entityId,
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            date('Y-m-d H:i:s')
        ]);
    } catch (Throwable $e) {}
}

/**
 * Universal Cascade Delete for Question Bank and all attached child dependencies
 */
function qps_delete_bank_cascade(PDO $pdo, int $bankId): bool {
    if ($bankId <= 0) return false;
    
    $inTx = $pdo->inTransaction();
    if (!$inTx) $pdo->beginTransaction();
    try {
        // 1. Delete question usage history
        try {
            $pdo->prepare("DELETE FROM question_usage_history WHERE paper_id IN (SELECT id FROM generated_papers WHERE bank_id = ?) OR question_id IN (SELECT id FROM questions WHERE bank_id = ?)")->execute([$bankId, $bankId]);
        } catch (Throwable $e) {}

        // 2. Delete generated papers tied to this bank
        try {
            $pdo->prepare("DELETE FROM generated_papers WHERE bank_id = ?")->execute([$bankId]);
        } catch (Throwable $e) {}

        // 3. Delete answer keys
        try {
            $pdo->prepare("DELETE FROM answer_keys WHERE bank_id = ?")->execute([$bankId]);
        } catch (Throwable $e) {}

        // 4. Delete questions
        try {
            $pdo->prepare("DELETE FROM questions WHERE bank_id = ?")->execute([$bankId]);
        } catch (Throwable $e) {}

        // 5. Delete bank versions and upload history
        try {
            $pdo->prepare("DELETE FROM qps_bank_versions WHERE bank_id = ?")->execute([$bankId]);
            $pdo->prepare("DELETE FROM qps_upload_history WHERE bank_id = ?")->execute([$bankId]);
        } catch (Throwable $e) {}

        // 6. Delete question bank itself
        $pdo->prepare("DELETE FROM question_banks WHERE id = ?")->execute([$bankId]);

        if (!$inTx) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if (!$inTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Universal Cascade Delete for Generated Examination Paper
 */
function qps_delete_paper_cascade(PDO $pdo, int $paperId): bool {
    if ($paperId <= 0) return false;
    $inTx = $pdo->inTransaction();
    if (!$inTx) $pdo->beginTransaction();
    try {
        try {
            $pdo->prepare("DELETE FROM question_usage_history WHERE paper_id = ?")->execute([$paperId]);
        } catch (Throwable $e) {}
        
        $pdo->prepare("DELETE FROM generated_papers WHERE id = ?")->execute([$paperId]);
        if (!$inTx) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if (!$inTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Cross-Database Safe Settings Setter (SQLite and MySQL)
 */
function qps_set_setting(PDO $pdo, string $key, string $value, string $actor = 'SUPER_ADMIN'): void {
    $now = date('Y-m-d H:i:s');
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $st = $pdo->prepare("INSERT INTO qps_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value, updated_by=excluded.updated_by, updated_at=excluded.updated_at");
        $st->execute([$key, $value, $actor, $now]);
    } else {
        $st = $pdo->prepare("INSERT INTO qps_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
        $st->execute([$key, $value, $actor, $now]);
    }
}

/**
 * Get dynamic section names, subtitles, and instructions for examination papers
 */
function qps_get_section_headers(PDO $pdo, string $lang = 'en'): array {
    $lang = strtolower($lang);
    if ($lang === 'ta' || $lang === 'tam' || $lang === 'tamil') {
        return [
            'sec_a_title' => qps_setting($pdo, 'ta_sec_a_title', 'பகுதி – அ'),
            'sec_a_sub'   => qps_setting($pdo, 'ta_sec_a_sub', 'பகுதி I (வினா 1-10): சரியான விடையைத் தேர்ந்தெடுத்து எழுதுக & பகுதி II (வினா 11-20): மிகக் குறுகிய விடையளி (20 x 1 = 20 மதிப்பெண்கள்)'),
            'sec_a_marks' => qps_setting($pdo, 'ta_sec_a_marks', '20 x 1 = 20 மதிப்பெண்கள்'),
            
            'sec_b_title' => qps_setting($pdo, 'ta_sec_b_title', 'பகுதி – ஆ'),
            'sec_b_sub'   => qps_setting($pdo, 'ta_sec_b_sub', 'அனைத்து வினாக்களுக்கும் விடையளி (எவையேனும் ஒன்று / அல்லது வகை: வினா 21 முதல் 25 வரை) (5 x 5 = 25 மதிப்பெண்கள்)'),
            'sec_b_marks' => qps_setting($pdo, 'ta_sec_b_marks', '5 x 5 = 25 மதிப்பெண்கள்'),
            
            'sec_c_title' => qps_setting($pdo, 'ta_sec_c_title', 'பகுதி – இ'),
            'sec_c_sub'   => qps_setting($pdo, 'ta_sec_c_sub', 'எவையேனும் இரண்டு வினாக்களுக்கு மட்டும் விடையளி (வினா 26 முதல் 28 வரை) (2 x 10 = 20 மதிப்பெண்கள்)'),
            'sec_c_marks' => qps_setting($pdo, 'ta_sec_c_marks', '2 x 10 = 20 மதிப்பெண்கள்'),
            
            'sec_d_title' => qps_setting($pdo, 'ta_sec_d_title', 'பகுதி – ஈ'),
            'sec_d_sub'   => qps_setting($pdo, 'ta_sec_d_sub', 'கட்டாய வினா (வினா 29) (1 x 10 = 10 மதிப்பெண்கள்)'),
            'sec_d_marks' => qps_setting($pdo, 'ta_sec_d_marks', '1 x 10 = 10 மதிப்பெண்கள்'),
        ];
    } elseif ($lang === 'fr' || $lang === 'fra' || $lang === 'french') {
        return [
            'sec_a_title' => qps_setting($pdo, 'fr_sec_a_title', 'SECTION – A'),
            'sec_a_sub'   => qps_setting($pdo, 'fr_sec_a_sub', 'Partie I (Q.1 à 10): Choix multiples & Partie II (Q.11 à 20): Réponse très courte (20 x 1 = 20 Points)'),
            'sec_a_marks' => qps_setting($pdo, 'fr_sec_a_marks', '20 x 1 = 20 Points'),
            
            'sec_b_title' => qps_setting($pdo, 'fr_sec_b_title', 'SECTION – B'),
            'sec_b_sub'   => qps_setting($pdo, 'fr_sec_b_sub', 'Répondez à TOUTES les questions (Type Soit/Ou: Q.21 à Q.25) (5 x 5 = 25 Points)'),
            'sec_b_marks' => qps_setting($pdo, 'fr_sec_b_marks', '5 x 5 = 25 Points'),
            
            'sec_c_title' => qps_setting($pdo, 'fr_sec_c_title', 'SECTION – C'),
            'sec_c_sub'   => qps_setting($pdo, 'fr_sec_c_sub', 'Répondez à DEUX questions au choix (Q.26 à Q.28) (2 x 10 = 20 Points)'),
            'sec_c_marks' => qps_setting($pdo, 'fr_sec_c_marks', '2 x 10 = 20 Points'),
            
            'sec_d_title' => qps_setting($pdo, 'fr_sec_d_title', 'SECTION – D'),
            'sec_d_sub'   => qps_setting($pdo, 'fr_sec_d_sub', 'Question obligatoire (Q.29) (1 x 10 = 10 Points)'),
            'sec_d_marks' => qps_setting($pdo, 'fr_sec_d_marks', '1 x 10 = 10 Points'),
        ];
    }
    
    // Default English
    return [
        'sec_a_title' => qps_setting($pdo, 'en_sec_a_title', 'SECTION – A'),
        'sec_a_sub'   => qps_setting($pdo, 'en_sec_a_sub', 'Part I (Q.1 to 10): Multiple Choice & Part II (Q.11 to 20): Very Short Answer (20 x 1 = 20 Marks)'),
        'sec_a_marks' => qps_setting($pdo, 'en_sec_a_marks', '20 x 1 = 20 Marks'),
        
        'sec_b_title' => qps_setting($pdo, 'en_sec_b_title', 'SECTION – B'),
        'sec_b_sub'   => qps_setting($pdo, 'en_sec_b_sub', 'Answer ALL Questions (Either/Or Type: Q.21 to Q.25) (5 x 5 = 25 Marks)'),
        'sec_b_marks' => qps_setting($pdo, 'en_sec_b_marks', '5 x 5 = 25 Marks'),
        
        'sec_c_title' => qps_setting($pdo, 'en_sec_c_title', 'SECTION – C'),
        'sec_c_sub'   => qps_setting($pdo, 'en_sec_c_sub', 'Answer any TWO Questions (Q.26 to Q.28) (2 x 10 = 20 Marks)'),
        'sec_c_marks' => qps_setting($pdo, 'en_sec_c_marks', '2 x 10 = 20 Marks'),
        
        'sec_d_title' => qps_setting($pdo, 'en_sec_d_title', 'SECTION – D'),
        'sec_d_sub'   => qps_setting($pdo, 'en_sec_d_sub', 'Answer the following Question (Compulsory: Q.29) (1 x 10 = 10 Marks)'),
        'sec_d_marks' => qps_setting($pdo, 'en_sec_d_marks', '1 x 10 = 10 Marks'),
    ];
}

?>
