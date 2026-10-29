<?php

/**
 * Detailed, append-only user activity log. Logging is deliberately best-effort:
 * a logging outage must not corrupt a successful question workflow transaction.
 */
function qps_activity_log(PDO $pdo, string $action, string $entityType = '', string $entityId = '', array $details = []): void {
    try {
        static $ready = false;
        if (!$ready) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS qps_activity_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                staff_code VARCHAR(80) NOT NULL DEFAULT '',
                staff_name VARCHAR(190) NOT NULL DEFAULT '',
                staff_mobile VARCHAR(40) NOT NULL DEFAULT '',
                role VARCHAR(40) NOT NULL DEFAULT '',
                action VARCHAR(120) NOT NULL,
                entity_type VARCHAR(80) NOT NULL DEFAULT '',
                entity_id VARCHAR(100) NOT NULL DEFAULT '',
                details_json LONGTEXT NULL,
                ip_address VARCHAR(45) NOT NULL DEFAULT '',
                user_agent VARCHAR(500) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                KEY idx_activity_created (created_at),
                KEY idx_activity_staff (staff_code, created_at),
                KEY idx_activity_action (action, created_at),
                KEY idx_activity_entity (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ready = true;
        }
        $u = $_SESSION['user'] ?? [];
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        // Respect reverse-proxy deployments only when explicitly configured by the server.
        if (getenv('QPS_TRUST_PROXY_IP') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $candidate = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) $ip = $candidate;
        }
        $st = $pdo->prepare("INSERT INTO qps_activity_logs
            (staff_code,staff_name,staff_mobile,role,action,entity_type,entity_id,details_json,ip_address,user_agent,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            (string)($u['staff_code'] ?? ''),
            (string)($u['name'] ?? $u['full_name'] ?? ''),
            (string)($u['mobile'] ?? $u['mobile_no'] ?? $u['phone'] ?? ''),
            (string)($u['role'] ?? 'SYSTEM'),
            substr($action, 0, 120),
            substr($entityType, 0, 80),
            substr($entityId, 0, 100),
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            substr($ip, 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            date('Y-m-d H:i:s')
        ]);
    } catch (Throwable $e) {
        // Best-effort audit logging; do not leak log errors or break user actions.
    }
}

/**
 * System helpers and schema verification for Holy Cross College QPS
 */

/**
 * Standard Exam Types for Holy Cross College
 */
function hcc_exam_types(): array {
    $defaults = [
        'Internal 1' => 'Internal 1',
        'Internal 2' => 'Internal 2',
        'Odd Semester End Examination' => 'Odd Semester End Examination',
        'Even Semester End Examination' => 'Even Semester End Examination',
        'Year' => 'Year'
    ];
    try {
        if (function_exists('getDBConnection') && function_exists('qps_setting')) {
            $pdo = getDBConnection();
            $raw = qps_setting($pdo, 'exam_types_json', '');
            if ($raw) {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded) && $decoded) {
                    $out = [];
                    foreach ($decoded as $key => $label) {
                        if (is_int($key)) {
                            $key = trim((string)$label);
                            $label = $key;
                        }
                        $key = trim((string)$key);
                        $label = trim((string)$label);
                        if ($key !== '' && $label !== '') $out[$key] = $label;
                    }
                    if ($out) return $out;
                }
            }
        }
    } catch (Throwable $e) {}
    return $defaults;
}

function qps_allowed_marks(PDO $pdo = null): array {
    $defaults = [1, 2, 5, 10, 15, 20];
    try {
        if ($pdo === null && function_exists('getDBConnection')) $pdo = getDBConnection();
        if ($pdo && function_exists('qps_setting')) {
            $raw = qps_setting($pdo, 'allowed_marks_json', '');
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                $out = [];
                foreach ($decoded as $m) {
                    $m = (int)$m;
                    if ($m > 0 && $m <= 100) $out[$m] = $m;
                }
                if ($out) { ksort($out, SORT_NUMERIC); return array_values($out); }
            }
        }
    } catch (Throwable $e) {}
    return $defaults;
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

/**
 * Create the minimal canonical application schema before running additive migrations.
 * This is deliberately separate from the college-owned identity/course tables.
 * Existing college tables (staff master, departments, courses, timetablefaculty) are never modified.
 */
function qps_ensure_base_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        $done = true;
        return;
    }
    $tables = [
        "CREATE TABLE IF NOT EXISTS question_banks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            staff_code VARCHAR(100) NOT NULL DEFAULT '', dept_code VARCHAR(50) NULL, dept_name VARCHAR(255) NULL,
            paper_code VARCHAR(100) NOT NULL, course_title VARCHAR(255) NULL, semester VARCHAR(50) NOT NULL DEFAULT '',
            academic_year VARCHAR(20) NOT NULL DEFAULT '', exam_type VARCHAR(120) NOT NULL DEFAULT '',
            regulation VARCHAR(80) NULL, degree_level VARCHAR(30) NULL, max_marks INT NOT NULL DEFAULT 75,
            total_questions INT NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
            review_comments TEXT NULL, questions_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, submitted_at DATETIME NULL,
            root_bank_id BIGINT UNSIGNED NULL, version_no INT NOT NULL DEFAULT 1, source_format VARCHAR(50) NULL,
            source_file_name VARCHAR(255) NULL, source_path VARCHAR(700) NULL, archive_path VARCHAR(700) NULL,
            language VARCHAR(20) DEFAULT 'en', hod_reviewed_by VARCHAR(100) NULL, hod_reviewed_at DATETIME NULL,
            hod_status VARCHAR(50) DEFAULT 'pending', ocr_language VARCHAR(50) NULL, ocr_used TINYINT(1) NOT NULL DEFAULT 0,
            content_hash VARCHAR(64) NULL, locked_at DATETIME NULL, reviewed_by VARCHAR(100) NULL, reviewed_at DATETIME NULL,
            blueprint_id BIGINT UNSIGNED NULL, school_name VARCHAR(255) NULL, part_type VARCHAR(50) NULL,
            schema_version VARCHAR(20) DEFAULT '4.0', current_version_id BIGINT NULL, last_import_id BIGINT NULL,
            matrix_config LONGTEXT NULL, PRIMARY KEY(id), KEY idx_qb_paper(paper_code), KEY idx_qb_staff(staff_code),
            KEY idx_qb_status(status), KEY idx_qb_dept(dept_code), KEY idx_qb_hash(content_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS questions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, bank_id BIGINT UNSIGNED NOT NULL, course_code VARCHAR(100) NULL,
            q_number INT NOT NULL DEFAULT 0, unit_no INT NOT NULL DEFAULT 1, sub_unit VARCHAR(50) DEFAULT '1.1',
            section_type VARCHAR(50) DEFAULT 'SECTION-A', question_type VARCHAR(50) DEFAULT 'VSA', question_text LONGTEXT NOT NULL,
            marks INT NOT NULL DEFAULT 1, k_level VARCHAR(20) DEFAULT 'K1', co_level VARCHAR(20) DEFAULT 'CO1',
            has_formula TINYINT(1) DEFAULT 0, formula_latex TEXT NULL, image_url LONGTEXT NULL, choice_group VARCHAR(50) NULL,
            sub_questions LONGTEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, options_json LONGTEXT NULL,
            answer_key LONGTEXT NULL, match_column_a LONGTEXT NULL, match_column_b LONGTEXT NULL, language VARCHAR(20) DEFAULT 'en',
            question_json LONGTEXT NULL, normalized_hash CHAR(64) NULL, source_q_number INT NULL, question_no INT DEFAULT 1,
            section VARCHAR(50) DEFAULT 'Section A', unit VARCHAR(20) DEFAULT '1', co_code VARCHAR(20) DEFAULT 'CO1',
            option_a LONGTEXT NULL, option_b LONGTEXT NULL, option_c LONGTEXT NULL, option_d LONGTEXT NULL,
            correct_answer LONGTEXT NULL, answer_text LONGTEXT NULL, assertion LONGTEXT NULL, reason LONGTEXT NULL,
            match_left LONGTEXT NULL, match_right LONGTEXT NULL, passage_text LONGTEXT NULL, either_or_group VARCHAR(50) NULL,
            compulsory VARCHAR(20) DEFAULT 'Yes', source_ref VARCHAR(255) NULL, notes LONGTEXT NULL, confidence DOUBLE DEFAULT 1,
            metadata_json LONGTEXT NULL, source_question_no INT NULL, import_schema VARCHAR(50) DEFAULT 'legacy',
            parser_version VARCHAR(50) NULL, parser_confidence DECIMAL(5,4) DEFAULT 1.0000,
            validation_status VARCHAR(30) DEFAULT 'VALID', normalized_text LONGTEXT NULL, question_hash CHAR(64) NULL,
            PRIMARY KEY(id), KEY idx_questions_bank(bank_id), KEY idx_questions_unit(unit_no), KEY idx_questions_k(k_level),
            KEY idx_questions_hash(question_hash), CONSTRAINT fk_questions_bank_base FOREIGN KEY(bank_id) REFERENCES question_banks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS blueprints (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(255) NOT NULL, dept_code VARCHAR(50) NULL,
            paper_code VARCHAR(100) NULL, course_title VARCHAR(255) NULL, semester VARCHAR(50) NULL,
            academic_year VARCHAR(20) NULL, exam_type VARCHAR(120) NULL, total_marks INT DEFAULT 75,
            duration_hours VARCHAR(50) DEFAULT '3 Hours', sections_config LONGTEXT NULL, instructions LONGTEXT NULL,
            created_by VARCHAR(100) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, matrix_config LONGTEXT NULL,
            PRIMARY KEY(id), KEY idx_blueprint_paper(paper_code), KEY idx_blueprint_dept(dept_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS coe_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, username VARCHAR(100) NOT NULL, password VARCHAR(255) NOT NULL,
            name VARCHAR(255) NULL, role VARCHAR(50) NULL, department VARCHAR(255) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id), UNIQUE KEY uq_coe_username(username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($tables as $sql) $pdo->exec($sql);
    $done = true;
}

function qps_ensure_aux_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    qps_ensure_base_schema($pdo);
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
                $qNeeded = [
                    'source_question_no' => 'INTEGER DEFAULT NULL',
                    'import_schema' => "TEXT DEFAULT 'legacy'",
                    'parser_version' => 'TEXT DEFAULT NULL',
                    'parser_confidence' => 'REAL DEFAULT 1.0',
                    'validation_status' => "TEXT DEFAULT 'VALID'",
                    'normalized_text' => 'TEXT DEFAULT NULL',
                    'question_hash' => 'TEXT DEFAULT NULL'
                ];
                foreach ($qNeeded as $qc => $qd) {
                    if (!isset($qCols[$qc])) $pdo->exec("ALTER TABLE questions ADD COLUMN $qc $qd");
                }
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
                'part_type' => 'VARCHAR(50) DEFAULT NULL',
                'schema_version' => 'VARCHAR(20) DEFAULT "4.0"',
                'current_version_id' => 'INTEGER DEFAULT NULL',
                'last_import_id' => 'INTEGER DEFAULT NULL'
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

            $pdo->exec("CREATE TABLE IF NOT EXISTS qps_imports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bank_id INTEGER DEFAULT NULL,
                token_hash TEXT DEFAULT NULL,
                source_file_name TEXT NOT NULL,
                source_format TEXT NOT NULL,
                file_size_bytes INTEGER DEFAULT 0,
                parser_version TEXT NOT NULL,
                schema_version TEXT DEFAULT '4.0',
                detected_language TEXT,
                ocr_used INTEGER DEFAULT 0,
                total_questions INTEGER DEFAULT 0,
                duplicate_questions INTEGER DEFAULT 0,
                warning_count INTEGER DEFAULT 0,
                low_confidence_count INTEGER DEFAULT 0,
                status TEXT DEFAULT 'UPLOADED',
                diagnostics_json TEXT,
                created_by TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME DEFAULT NULL
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
                $qNeeded = [
                    'source_question_no' => 'INT NULL',
                    'import_schema' => "VARCHAR(30) DEFAULT 'legacy'",
                    'parser_version' => 'VARCHAR(80) NULL',
                    'parser_confidence' => 'DECIMAL(5,4) DEFAULT 1.0000',
                    'validation_status' => "VARCHAR(30) DEFAULT 'VALID'",
                    'normalized_text' => 'LONGTEXT NULL',
                    'question_hash' => 'CHAR(64) NULL'
                ];
                foreach ($qNeeded as $qc => $qd) {
                    if (!in_array(strtolower($qc), $qCols, true)) {
                        $pdo->exec("ALTER TABLE `questions` ADD COLUMN `{$qc}` {$qd}");
                    }
                }
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
                'part_type' => 'VARCHAR(50) NULL',
                'schema_version' => "VARCHAR(20) DEFAULT '4.0'",
                'current_version_id' => 'BIGINT NULL',
                'last_import_id' => 'BIGINT NULL',
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
            // v4 provenance tables are additive and keep ERP master tables untouched.
            $pdo->exec("CREATE TABLE IF NOT EXISTS `qps_imports` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `bank_id` int DEFAULT NULL,
                `token_hash` char(64) DEFAULT NULL,
                `source_file_name` varchar(255) NOT NULL,
                `source_format` varchar(30) NOT NULL,
                `file_size_bytes` bigint unsigned NOT NULL DEFAULT 0,
                `parser_version` varchar(80) NOT NULL,
                `schema_version` varchar(20) NOT NULL DEFAULT '4.0',
                `detected_language` varchar(20) DEFAULT NULL,
                `ocr_used` tinyint(1) NOT NULL DEFAULT 0,
                `total_questions` int unsigned NOT NULL DEFAULT 0,
                `duplicate_questions` int unsigned NOT NULL DEFAULT 0,
                `warning_count` int unsigned NOT NULL DEFAULT 0,
                `low_confidence_count` int unsigned NOT NULL DEFAULT 0,
                `status` varchar(30) NOT NULL DEFAULT 'UPLOADED',
                `diagnostics_json` longtext,
                `created_by` varchar(100) DEFAULT NULL,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                `completed_at` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_qps_import_bank` (`bank_id`,`created_at`),
                KEY `idx_qps_import_staff` (`created_by`,`created_at`),
                KEY `idx_qps_import_hash` (`token_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `qps_import_rows` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `import_id` bigint unsigned NOT NULL,
                `row_no` int unsigned NOT NULL,
                `source_q_number` int DEFAULT NULL,
                `question_hash` char(64) DEFAULT NULL,
                `validation_status` varchar(30) NOT NULL DEFAULT 'VALID',
                `duplicate_type` varchar(40) DEFAULT NULL,
                `matched_question_id` int DEFAULT NULL,
                `diagnostics_json` longtext,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_qps_import_row` (`import_id`,`row_no`),
                KEY `idx_qps_ir_hash` (`question_hash`),
                KEY `idx_qps_ir_match` (`matched_question_id`),
                CONSTRAINT `fk_qps_ir_import` FOREIGN KEY (`import_id`) REFERENCES `qps_imports` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `qps_question_history` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `question_id` int NOT NULL,
                `bank_id` int NOT NULL,
                `version_no` int NOT NULL DEFAULT 1,
                `action` varchar(20) NOT NULL,
                `snapshot_json` longtext NOT NULL,
                `changed_by` varchar(100) DEFAULT NULL,
                `changed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_qps_qh_question` (`question_id`,`changed_at`),
                KEY `idx_qps_qh_bank` (`bank_id`,`changed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `qps_question_usage` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `question_id` int NOT NULL,
                `bank_id` int DEFAULT NULL,
                `generated_paper_id` int DEFAULT NULL,
                `paper_code` varchar(120) DEFAULT NULL,
                `academic_year` varchar(30) DEFAULT NULL,
                `semester` varchar(50) DEFAULT NULL,
                `exam_type` varchar(100) DEFAULT NULL,
                `usage_role` varchar(30) NOT NULL DEFAULT 'GENERATED',
                `used_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                `used_by` varchar(100) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_qps_qu_question` (`question_id`,`used_at`),
                KEY `idx_qps_qu_context` (`paper_code`,`academic_year`,`semester`),
                KEY `idx_qps_qu_year` (`academic_year`,`used_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
 * Rebuild the course question-pool matrix from published questions plus all live
 * staff drafts. Uses a single aggregate snapshot per course/semester/year/exam.
 */
function qps_rebuild_course_matrix(PDO $pdo, string $paperCode, string $semester, string $academicYear, string $examType, string $departmentCode = ''): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS qps_course_question_matrices (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        paper_code VARCHAR(80) NOT NULL,
        semester VARCHAR(30) NOT NULL,
        academic_year VARCHAR(30) NOT NULL,
        exam_type VARCHAR(80) NOT NULL,
        department_code VARCHAR(30) NOT NULL DEFAULT '',
        total_questions INT NOT NULL DEFAULT 0,
        total_marks INT NOT NULL DEFAULT 0,
        matrix_json LONGTEXT NOT NULL,
        refreshed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_qps_course_matrix_context (paper_code,semester,academic_year,exam_type),
        KEY idx_qps_course_matrix_dept (department_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $rows = [];
    $published = $pdo->prepare("SELECT q.unit_no,q.sub_unit,q.section_type,q.question_type,q.marks,q.k_level,q.co_level,q.choice_group
        FROM questions q JOIN question_banks b ON b.id=q.bank_id
        WHERE UPPER(b.paper_code)=UPPER(?) AND b.semester=? AND b.academic_year=? AND b.exam_type=?
          AND LOWER(b.status) IN ('approved','published','coe_approved')");
    $published->execute([$paperCode,$semester,$academicYear,$examType]);
    $rows = $published->fetchAll(PDO::FETCH_ASSOC);
    $draft = $pdo->prepare("SELECT q.unit_no,q.sub_unit,q.section_type,q.question_type,q.marks,q.k_level,q.co_level,q.choice_group
        FROM qps_question_bank_draft_questions q JOIN qps_question_bank_drafts d ON d.id=q.draft_id
        WHERE UPPER(d.paper_code)=UPPER(?) AND d.semester=? AND d.academic_year=? AND d.exam_type=?
          AND d.status IN ('DRAFT','SUBMITTED_TO_HOD','SUBMITTED_TO_COE','RETURNED_BY_HOD','RETURNED_BY_COE','CORRECTION_REQUIRED')");
    $draft->execute([$paperCode,$semester,$academicYear,$examType]);
    $rows = array_merge($rows, $draft->fetchAll(PDO::FETCH_ASSOC));
    $matrix = []; $totalMarks = 0;
    foreach ($rows as $r) {
        $unit = (int)($r['unit_no'] ?? 0); $sub = trim((string)($r['sub_unit'] ?? ''));
        $type = strtoupper(trim((string)($r['question_type'] ?? 'OTHER')));
        $k = strtoupper(trim((string)($r['k_level'] ?? '')));
        $co = strtoupper(trim((string)($r['co_level'] ?? '')));
        $marks = (int)($r['marks'] ?? 0); $section = strtoupper(trim((string)($r['section_type'] ?? '')));
        $key = $unit.'.'.$sub.'|'.$type.'|'.$k.'|'.$co.'|'.$marks.'|'.$section;
        if (!isset($matrix[$key])) $matrix[$key] = ['unit_no'=>$unit,'sub_unit'=>$sub,'question_type'=>$type,'k_level'=>$k,'co_level'=>$co,'marks'=>$marks,'section_type'=>$section,'count'=>0];
        $matrix[$key]['count']++; $totalMarks += $marks;
    }
    $up = $pdo->prepare("INSERT INTO qps_course_question_matrices(paper_code,semester,academic_year,exam_type,department_code,total_questions,total_marks,matrix_json)
        VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE department_code=VALUES(department_code),total_questions=VALUES(total_questions),total_marks=VALUES(total_marks),matrix_json=VALUES(matrix_json),refreshed_at=NOW()");
    $up->execute([$paperCode,$semester,$academicYear,$examType,$departmentCode,count($rows),$totalMarks,json_encode(array_values($matrix),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
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
        try {
            $pdo->prepare("DELETE FROM qps_question_bank_draft_questions WHERE bank_id = ?")->execute([$bankId]);
        } catch (Throwable $e) {}
        try {
            $pdo->prepare("DELETE FROM question_bank_draft_questions WHERE bank_id = ?")->execute([$bankId]);
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
            'sec_c_sub'   => qps_setting($pdo, 'ta_sec_c_sub', 'நான்கு விருப்பங்களில் ஏதேனும் இரண்டு வினாக்களுக்கு விடையளி (2 x 10 = 20 மதிப்பெண்கள்)'),
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
            'sec_c_sub'   => qps_setting($pdo, 'fr_sec_c_sub', 'Répondez à deux questions parmi quatre (2 x 10 = 20 points)'),
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
        'sec_c_sub'   => qps_setting($pdo, 'en_sec_c_sub', 'Answer any TWO Questions from four options (2 x 10 = 20 Marks)'),
        'sec_c_marks' => qps_setting($pdo, 'en_sec_c_marks', '2 x 10 = 20 Marks'),
        
        'sec_d_title' => qps_setting($pdo, 'en_sec_d_title', 'SECTION – D'),
        'sec_d_sub'   => qps_setting($pdo, 'en_sec_d_sub', 'Answer the following compulsory question (1 x 10 = 10 Marks)'),
        'sec_d_marks' => qps_setting($pdo, 'en_sec_d_marks', '1 x 10 = 10 Marks'),
    ];
}


/**
 * Refresh the live, course-scoped question-bank blueprint counts.
 * Counts approved/master questions plus in-progress draft questions, so staff
 * see each new question reflected immediately. Approved drafts are excluded
 * from the draft side after promotion to prevent double counting.
 */
function qps_refresh_question_bank_blueprint(PDO $pdo, string $paperCode): void {
    $paperCode = trim($paperCode);
    if ($paperCode === '') return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS qps_question_bank_blueprint_cells (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        paper_code VARCHAR(100) NOT NULL,
        unit_no INT NOT NULL,
        sub_unit VARCHAR(50) NOT NULL,
        question_type VARCHAR(50) NOT NULL,
        k_level VARCHAR(20) NOT NULL,
        co_level VARCHAR(30) NOT NULL,
        marks INT NOT NULL,
        question_count INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_qps_qbb_cell (paper_code,unit_no,sub_unit,question_type,k_level,co_level,marks),
        KEY idx_qps_qbb_course (paper_code,unit_no,sub_unit)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM qps_question_bank_blueprint_cells WHERE UPPER(paper_code)=UPPER(?)");
        $del->execute([$paperCode]);
        $sql = "SELECT unit_no,sub_unit,question_type,k_level,co_level,marks,COUNT(*) AS question_count
                FROM (
                    SELECT q.unit_no AS unit_no, COALESCE(NULLIF(q.sub_unit,''),'1.1') AS sub_unit,
                           UPPER(COALESCE(NULLIF(q.question_type,''),'VSA')) AS question_type,
                           UPPER(COALESCE(NULLIF(q.k_level,''),'K1')) AS k_level,
                           UPPER(COALESCE(NULLIF(q.co_level,''),'CO1')) AS co_level, q.marks AS marks
                    FROM questions q
                    LEFT JOIN question_banks b ON b.id=q.bank_id
                    WHERE UPPER(COALESCE(NULLIF(q.course_code,''),b.paper_code,''))=UPPER(?)
                    UNION ALL
                    SELECT dq.unit_no, COALESCE(NULLIF(dq.sub_unit,''),'1.1'),
                           UPPER(COALESCE(NULLIF(dq.question_type,''),'VSA')),
                           UPPER(COALESCE(NULLIF(dq.k_level,''),'K1')),
                           UPPER(COALESCE(NULLIF(dq.co_level,''),'CO1')), dq.marks
                    FROM qps_question_bank_draft_questions dq
                    INNER JOIN qps_question_bank_drafts d ON d.id=dq.draft_id
                    WHERE UPPER(d.paper_code)=UPPER(?)
                      AND d.status NOT IN ('APPROVED','REJECTED')
                ) source_rows
                GROUP BY unit_no,sub_unit,question_type,k_level,co_level,marks";
        $st = $pdo->prepare($sql);
        $st->execute([$paperCode, $paperCode]);
        $insert = $pdo->prepare("INSERT INTO qps_question_bank_blueprint_cells
            (paper_code,unit_no,sub_unit,question_type,k_level,co_level,marks,question_count)
            VALUES (?,?,?,?,?,?,?,?)");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $insert->execute([$paperCode,(int)$r['unit_no'],(string)$r['sub_unit'],(string)$r['question_type'],
                (string)$r['k_level'],(string)$r['co_level'],(int)$r['marks'],(int)$r['question_count']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function qps_ensure_question_draft_schema(PDO $pdo): void {
 static $done=false; if($done)return;
 $pdo->exec("CREATE TABLE IF NOT EXISTS qps_question_bank_drafts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, staff_code VARCHAR(100) NOT NULL, dept_code VARCHAR(50) NULL, dept_name VARCHAR(255) NULL, paper_code VARCHAR(100) NOT NULL, course_title VARCHAR(255) NULL, semester VARCHAR(50) NOT NULL, academic_year VARCHAR(20) NOT NULL, exam_type VARCHAR(120) NOT NULL, regulation VARCHAR(80) NULL, degree_level VARCHAR(30) NULL, max_marks INT NOT NULL DEFAULT 75, total_questions INT NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'DRAFT', source_format VARCHAR(20) NULL, source_file_name VARCHAR(255) NULL, source_path VARCHAR(700) NULL, archive_path VARCHAR(700) NULL, questions_json LONGTEXT NOT NULL, qbb_id BIGINT UNSIGNED NULL, qbb_checked TINYINT(1) NOT NULL DEFAULT 0, qbb_check_result_json LONGTEXT NULL, qbb_checked_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, submitted_at DATETIME NULL, hod_reviewed_by VARCHAR(100) NULL, hod_reviewed_at DATETIME NULL, review_comments TEXT NULL, PRIMARY KEY(id), KEY idx_course(paper_code,semester,academic_year), KEY idx_staff(staff_code), KEY idx_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $pdo->exec("CREATE TABLE IF NOT EXISTS qps_question_bank_draft_questions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, draft_id BIGINT UNSIGNED NULL, bank_id BIGINT UNSIGNED NULL, q_number INT NOT NULL DEFAULT 0, unit_no INT NOT NULL DEFAULT 0, sub_unit VARCHAR(50) NULL, section_type VARCHAR(50) NULL, question_type VARCHAR(50) NULL, question_text LONGTEXT NOT NULL, marks INT NOT NULL DEFAULT 0, k_level VARCHAR(20) NULL, co_level VARCHAR(20) NULL, options_json LONGTEXT NULL, answer_key LONGTEXT NULL, match_column_a LONGTEXT NULL, match_column_b LONGTEXT NULL, image_path VARCHAR(700) NULL, compulsory TINYINT(1) NOT NULL DEFAULT 1, choice_group VARCHAR(50) NULL, review_status VARCHAR(30) NOT NULL DEFAULT 'PENDING', review_remarks TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(id), KEY idx_draft(draft_id), KEY idx_bank(bank_id), KEY idx_draft_section(draft_id,section_type), KEY idx_draft_review(draft_id,review_status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 try { $pdo->exec("ALTER TABLE qps_question_bank_drafts MODIFY status VARCHAR(30) NOT NULL DEFAULT 'DRAFT'"); } catch (Throwable $e) {}
 try { $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qps_question_bank_drafts' AND COLUMN_NAME='promoted_bank_id'"); $q->execute(); if (!(int)$q->fetchColumn()) $pdo->exec("ALTER TABLE qps_question_bank_drafts ADD COLUMN promoted_bank_id BIGINT UNSIGNED NULL AFTER status"); } catch (Throwable $e) {}
 $draftQuestionColumns = [
   'match_column_a' => 'LONGTEXT NULL', 'match_column_b' => 'LONGTEXT NULL', 'image_path' => 'VARCHAR(700) NULL',
   'compulsory' => 'TINYINT(1) NOT NULL DEFAULT 1', 'choice_group' => 'VARCHAR(50) NULL',
   'review_status' => "VARCHAR(30) NOT NULL DEFAULT 'PENDING'", 'review_remarks' => 'TEXT NULL',
   'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
 ];
 foreach ($draftQuestionColumns as $col => $definition) {
   try { $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qps_question_bank_draft_questions' AND COLUMN_NAME=?"); $q->execute([$col]); if (!(int)$q->fetchColumn()) $pdo->exec("ALTER TABLE qps_question_bank_draft_questions ADD COLUMN `{$col}` {$definition}"); } catch (Throwable $e) {}
 }
 $pdo->exec("CREATE TABLE IF NOT EXISTS qps_question_review_history (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, draft_id BIGINT UNSIGNED NOT NULL, draft_question_id BIGINT UNSIGNED NULL, reviewer_code VARCHAR(100) NOT NULL, reviewer_role VARCHAR(30) NOT NULL, decision VARCHAR(40) NOT NULL, remarks TEXT NULL, snapshot_json LONGTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), KEY idx_qps_review_draft(draft_id,created_at), KEY idx_qps_review_question(draft_question_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 foreach([['course_code','VARCHAR(100) NULL AFTER bank_id'],['question_json','LONGTEXT NULL AFTER question_text'],['match_column_a','LONGTEXT NULL AFTER answer_key'],['match_column_b','LONGTEXT NULL AFTER match_column_a'],['question_type','VARCHAR(50) NULL AFTER section_type']] as $pair){try{$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND COLUMN_NAME=?");$q->execute([$pair[0]]);if(!(int)$q->fetchColumn())$pdo->exec("ALTER TABLE questions ADD COLUMN {$pair[0]} {$pair[1]}");}catch(Throwable $e){}} $done=true;
}
