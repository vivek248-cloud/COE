<?php
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

function hcc_default_paper_types(): array {
    return [
        'OBE' => [
            'code' => 'OBE', 'label' => 'OBE', 'total_marks' => 75, 'layout' => 'OBE',
            'section_types' => ['SECTION-A', 'SECTION-B', 'SECTION-C', 'SECTION-D'],
            'shuffle_ranges' => '1-8,11-20,21-25,26-29'
        ],
        'NON_OBE' => [
            'code' => 'NON_OBE', 'label' => 'NON OBE', 'total_marks' => 50, 'layout' => 'NON_OBE',
            'section_types' => ['SECTION-A', 'SECTION-B', 'SECTION-C'],
            'shuffle_ranges' => '1-4,7-10,11-14,15-16'
        ],
        'PGDCA' => [
            'code' => 'PGDCA', 'label' => 'PGDCA', 'total_marks' => 50, 'layout' => 'NON_OBE',
            'section_types' => ['SECTION-A', 'SECTION-B', 'SECTION-C'],
            'shuffle_ranges' => '1-4,7-10,11-14,15-16'
        ]
    ];
}

function hcc_paper_types(?PDO $pdo = null): array {
    $defaults = hcc_default_paper_types();
    try {
        if ($pdo === null && function_exists('getDBConnection')) $pdo = getDBConnection();
        $raw = ($pdo && function_exists('qps_setting')) ? qps_setting($pdo, 'paper_types_json', '') : '';
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded) || !$decoded) return $defaults;

        $types = [];
        foreach ($decoded as $key => $item) {
            if (!is_array($item)) continue;
            $code = strtoupper(trim((string)($item['code'] ?? (is_string($key) ? $key : ''))));
            $code = preg_replace('/[^A-Z0-9_-]/', '_', $code);
            $label = trim((string)($item['label'] ?? $code));
            $marks = (int)($item['total_marks'] ?? 0);
            if ($code === '' || $label === '' || $marks < 1 || $marks > 300) continue;
            $layout = strtoupper(trim((string)($item['layout'] ?? '')));
            if (!in_array($layout, ['OBE', 'NON_OBE'], true)) $layout = ($marks <= 50 ? 'NON_OBE' : 'OBE');
            $sections = $item['section_types'] ?? ['SECTION-A', 'SECTION-B', 'SECTION-C'];
            if (is_string($sections)) $sections = explode(',', $sections);
            $sections = array_values(array_unique(array_filter(array_map(function($v) {
                $v = strtoupper(trim((string)$v));
                return preg_match('/^[A-Z][A-Z0-9_-]{0,49}$/', $v) ? $v : '';
            }, is_array($sections) ? $sections : []))));
            if (!$sections) $sections = ['SECTION-A', 'SECTION-B', 'SECTION-C'];
            $ranges = trim((string)($item['shuffle_ranges'] ?? ''));
            $types[$code] = [
                'code' => $code,
                'label' => $label,
                'total_marks' => $marks,
                'layout' => $layout,
                'section_types' => $sections,
                'shuffle_ranges' => $ranges
            ];
        }
        return $types ?: $defaults;
    } catch (Throwable $e) {
        return $defaults;
    }
}

function hcc_parse_shuffle_ranges($ranges): array {
    if (is_array($ranges)) {
        $parts = $ranges;
    } else {
        $parts = preg_split('/[,;]+/', (string)$ranges, -1, PREG_SPLIT_NO_EMPTY);
    }
    $out = [];
    foreach ($parts as $part) {
        if (is_array($part)) {
            $start = (int)($part[0] ?? 0);
            $end = (int)($part[1] ?? 0);
        } elseif (preg_match('/^\s*(\d+)\s*[-:]\s*(\d+)\s*$/', (string)$part, $m)) {
            $start = (int)$m[1];
            $end = (int)$m[2];
        } else {
            continue;
        }
        if ($start >= 1 && $end >= $start && $end <= 100) $out[] = [$start, $end];
    }
    return $out;
}

function hcc_resolve_paper_type(array $context, PDO $pdo = null): array {
    $types = hcc_paper_types($pdo);
    $rawType = strtoupper(trim(implode(' ', array_filter([
        (string)($context['course_type'] ?? ''),
        (string)($context['type'] ?? ''),
        (string)($context['qpattern'] ?? ''),
        (string)($context['exam_type'] ?? ''),
        (string)($context['course_title'] ?? ($context['coursetitle'] ?? ''))
    ]))));
    $normalized = preg_replace('/[^A-Z0-9]+/', '_', $rawType);

    foreach ($types as $code => $paperType) {
        $codeNorm = preg_replace('/[^A-Z0-9]+/', '_', strtoupper($code));
        $labelNorm = preg_replace('/[^A-Z0-9]+/', '_', strtoupper($paperType['label']));
        if ($normalized !== '' && (strpos($normalized, $codeNorm) !== false || strpos($normalized, $labelNorm) !== false)) return $paperType;
    }

    foreach ($types as $paperType) {
        if (($paperType['code'] ?? '') === 'PGDCA' && strpos($rawType, 'PGDCA') !== false) return $paperType;
    }
    foreach ($types as $paperType) {
        if (($paperType['layout'] ?? '') === 'NON_OBE' && preg_match('/NON[-_ ]?OBE|NONOBE/i', $rawType)) return $paperType;
    }

    $bankMarks = (int)($context['max_marks'] ?? ($context['maxmark'] ?? 0));
    if ($bankMarks > 0 && $bankMarks <= 50) {
        foreach ($types as $paperType) {
            if (($paperType['code'] ?? '') === 'NON_OBE') return $paperType;
        }
    }
    foreach ($types as $paperType) {
        if (($paperType['code'] ?? '') === 'OBE') return $paperType;
    }
    return reset($types) ?: hcc_default_paper_types()['OBE'];
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
function hcc_course_exam_info(array $c, ?PDO $pdo = null): array {
    $title = $c['coursetitle'] ?? '';
    $pattern = strtoupper($c['qpattern'] ?? '');
    $type = strtoupper((string)($c['type'] ?? ($c['course_type'] ?? '')));
    $dbMax = (int)($c['maxmark'] ?? 0);
    $paperType = hcc_resolve_paper_type($c, $pdo);
    $paperTypes = hcc_paper_types($pdo);
    $obeMarks = (int)($paperTypes['OBE']['total_marks'] ?? 75);
    $nonObeMarks = (int)($paperTypes['NON_OBE']['total_marks'] ?? 50);

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
    if (($paperType['code'] ?? '') === 'PGDCA') {
        $marks = (int)$paperType['total_marks'];
        return [
            'type_label' => $paperType['label'] . " ({$marks}M)",
            'exam_marks' => $marks,
            'marks' => $marks,
            'marks_label' => $marks . 'M',
            'is_practical' => false,
            'is_theory' => true,
            'is_obe' => false,
            'badge_class' => 'bg-cyan-50 text-cyan-800 border-cyan-200'
        ];
    }
    if ($isExplicitNonObe) {
        return [
            'type_label' => 'NON-OBE (' . $nonObeMarks . 'M)',
            'exam_marks' => $nonObeMarks,
            'marks' => $nonObeMarks,
            'marks_label' => $nonObeMarks . 'M',
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
            'type_label' => 'NON-OBE (' . $nonObeMarks . 'M)', 'exam_marks' => $nonObeMarks, 'marks' => $nonObeMarks, 'marks_label' => $nonObeMarks . 'M',
            'is_practical' => false, 'is_theory' => true, 'is_obe' => false,
            'badge_class' => 'bg-amber-50 text-amber-800 border-amber-300'
        ];
    }
    $examMarks = preg_match('/(?:OBE\s*100|100M|100\s*MARKS)/i', $type . ' ' . $pattern) ? 100 : $obeMarks;
    $label = $examMarks . 'M';

    return [
        'type_label' => 'OBE Theory (' . $label . ')',
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

            $pdo->exec("CREATE TABLE IF NOT EXISTS `blueprints` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(255) NOT NULL,
              `paper_code` varchar(100) DEFAULT NULL,
              `course_title` varchar(255) DEFAULT NULL,
              `semester` varchar(50) DEFAULT NULL,
              `academic_year` varchar(50) DEFAULT NULL,
              `exam_type` varchar(100) DEFAULT 'Odd Semester End Examination',
              `total_marks` int DEFAULT 75,
              `matrix_config` longtext,
              `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_bp_course` (`paper_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `generated_papers` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `bank_id` int DEFAULT NULL,
              `final_blueprint_id` bigint unsigned DEFAULT NULL,
              `paper_code` varchar(100) DEFAULT NULL,
              `course_code` varchar(100) DEFAULT NULL,
              `course_title` varchar(255) DEFAULT NULL,
              `course_type` varchar(50) DEFAULT 'OBE',
              `dept_code` varchar(50) DEFAULT NULL,
              `semester` varchar(50) DEFAULT NULL,
              `academic_year` varchar(50) DEFAULT NULL,
              `exam_type` varchar(100) DEFAULT NULL,
              `exam_session` varchar(100) DEFAULT NULL,
              `set_code` varchar(20) DEFAULT 'SET-A',
              `total_marks` int DEFAULT 75,
              `paper_json` longtext,
              `docx_file_path` varchar(500) DEFAULT NULL,
              `generated_by` varchar(100) DEFAULT NULL,
              `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_gp_course` (`paper_code`),
              KEY `idx_gp_course_code` (`course_code`)
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
    $staffCode = $u['staff_code'] ?? ($u['name'] ?? 'SYSTEM');
    $staffName = $u['name'] ?? ($u['FIRST_NAME'] ?? $staffCode);
    $staffMobile = $u['mobile_no'] ?? ($u['MOBILE_NO'] ?? '');
    
    // If mobile is not in session, try to query from prof_mast
    if (empty($staffMobile) && !empty($staffCode) && $staffCode !== 'SYSTEM' && $staffCode !== 'COE_OFFICE') {
        try {
            $stMob = $pdo->prepare("SELECT MOBILE_NO, FIRST_NAME FROM pr_x_xxxx_staf_prof_mast WHERE UPPER(STAFF_CODE) = UPPER(?) LIMIT 1");
            $stMob->execute([$staffCode]);
            $rowMob = $stMob->fetch(PDO::FETCH_ASSOC);
            if ($rowMob) {
                $staffMobile = $rowMob['MOBILE_NO'] ?? '';
                if (empty($staffName)) $staffName = $rowMob['FIRST_NAME'] ?? '';
            }
        } catch (Throwable $e) {}
    }

    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    if (strpos($ipAddress, ',') !== false) {
        $ipAddress = trim(explode(',', $ipAddress)[0]);
    }

    try {
        // Ensure audit log columns exist
        qps_ensure_audit_schema($pdo);
        
        $st = $pdo->prepare("INSERT INTO qps_audit_log(staff_code, staff_name, staff_mobile, role, action, entity_type, entity_id, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([
            $staffCode,
            $staffName,
            $staffMobile,
            $u['role'] ?? 'SYSTEM',
            $action,
            $entityType,
            $entityId,
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $ipAddress,
            date('Y-m-d H:i:s')
        ]);
    } catch (Throwable $e) {
        // Fallback for older schema
        try {
            $stOld = $pdo->prepare("INSERT INTO qps_audit_log(actor, role, action, entity_type, entity_id, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stOld->execute([$staffCode, $u['role'] ?? 'SYSTEM', $action, $entityType, $entityId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('Y-m-d H:i:s')]);
        } catch (Throwable $e2) {}
    }
}

function qps_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
            $existing = array_map(function($c) { return strtolower($c['name']); }, $cols);
            if (!in_array(strtolower($column), $existing, true)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        } else {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE UPPER(TABLE_SCHEMA) = UPPER(DATABASE()) AND UPPER(TABLE_NAME) = UPPER(?) AND UPPER(COLUMN_NAME) = UPPER(?)");
            $q->execute([$table, $column]);
            if (!(int)$q->fetchColumn()) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    } catch (Throwable $e) {}
}

function qps_ensure_audit_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $autoInc = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';

    $pdo->exec("CREATE TABLE IF NOT EXISTS qps_audit_log (
        id {$autoInc},
        staff_code VARCHAR(100) NULL,
        staff_name VARCHAR(255) NULL,
        staff_mobile VARCHAR(50) NULL,
        role VARCHAR(50) NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(100) NULL,
        entity_id VARCHAR(100) NULL,
        details LONGTEXT NULL,
        ip_address VARCHAR(100) NULL,
        actor VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Auto-migrate any missing columns on existing tables
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'staff_code', 'VARCHAR(100) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'staff_name', 'VARCHAR(255) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'staff_mobile', 'VARCHAR(50) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'role', 'VARCHAR(50) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'entity_type', 'VARCHAR(100) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'entity_id', 'VARCHAR(100) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'details', 'LONGTEXT NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'ip_address', 'VARCHAR(100) NULL');
}


/** Soft-delete metadata for generated papers and question banks. */
function qps_ensure_recycle_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['generated_papers', 'question_banks'] as $table) {
        qps_add_column_if_missing($pdo, $table, 'deleted_at', 'DATETIME NULL');
        qps_add_column_if_missing($pdo, $table, 'deleted_by', 'VARCHAR(100) NULL');
        qps_add_column_if_missing($pdo, $table, 'expires_at', 'DATETIME NULL');
        qps_add_column_if_missing($pdo, $table, 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
    }
}

/** Display-only audit labels. Stored action codes remain unchanged. */
function qps_audit_label(string $code): string {
    static $labels = [
        'HOD_FORWARDED_TO_COE' => 'HOD forwarded to COE',
        'LOGIN_QUICK_SUCCESS' => 'Quick login',
        'LOGIN_SUCCESS_COE_MASTER' => 'COE master login',
        'LOGIN_SUCCESS_STAFF' => 'Staff login',
        'QUESTION_CORRECTION_FLAGGED' => 'Question correction flagged',
        'QUESTION_CORRECTION_RESOLVED' => 'Question correction resolved',
        'TEACHING_FINAL_BLUEPRINT_SAVED' => 'Final teaching blueprint saved',
        'QUESTION_PAPER_DELETED' => 'Question paper deleted',
        'QUESTION_PAPER_RESTORED' => 'Question paper restored',
        'QUESTION_PAPER_EDITED' => 'Question paper allocations edited',
        'QUESTION_BANK_ITEM_DELETED' => 'Question bank item deleted',
        'QUESTION_BANK_ITEM_RESTORED' => 'Question bank item restored',
    ];
    if (isset($labels[$code])) return $labels[$code];
    return ucwords(strtolower(str_replace(['_', '-'], ' ', $code)));
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

function qps_ensure_question_draft_schema(PDO $pdo): void {
 static $done=false; if($done)return; $done=true;
 qps_ensure_audit_schema($pdo);
 qps_ensure_aux_schema($pdo);

 $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
 $autoInc = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';

 $pdo->exec("CREATE TABLE IF NOT EXISTS qps_question_bank_drafts (
    id {$autoInc},
    staff_code VARCHAR(100) NOT NULL DEFAULT 'STAFF',
    dept_code VARCHAR(50) NULL,
    dept_name VARCHAR(255) NULL,
    paper_code VARCHAR(100) NOT NULL DEFAULT '',
    course_title VARCHAR(255) NULL,
    course_type VARCHAR(50) NOT NULL DEFAULT 'OBE',
    semester VARCHAR(50) NOT NULL DEFAULT 'Semester 1',
    academic_year VARCHAR(50) NOT NULL DEFAULT '2026-2027',
    exam_type VARCHAR(120) NOT NULL DEFAULT 'Odd Semester End Examination',
    regulation VARCHAR(80) NULL,
    degree_level VARCHAR(30) NULL,
    max_marks INT NOT NULL DEFAULT 75,
    total_questions INT NOT NULL DEFAULT 0,
    total_marks INT NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL DEFAULT 'DRAFT',
    source_format VARCHAR(20) NULL,
    source_file_name VARCHAR(255) NULL,
    source_path VARCHAR(700) NULL,
    archive_path VARCHAR(700) NULL,
    questions_json LONGTEXT NULL,
    qbb_id BIGINT UNSIGNED NULL,
    qbb_checked TINYINT(1) NOT NULL DEFAULT 0,
    qbb_check_result_json LONGTEXT NULL,
    qbb_checked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME NULL,
    hod_reviewed_by VARCHAR(100) NULL,
    hod_reviewed_at DATETIME NULL,
    hod_remarks TEXT NULL,
    coe_reviewed_by VARCHAR(100) NULL,
    coe_reviewed_at DATETIME NULL,
    coe_remarks TEXT NULL,
    review_comments TEXT NULL,
    version_no INT UNSIGNED NOT NULL DEFAULT 1
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

 // Dynamic column migrations for qps_question_bank_drafts
 qps_add_column_if_missing($pdo, 'qps_question_bank_drafts', 'staff_code', "VARCHAR(100) NOT NULL DEFAULT 'STAFF'");
 qps_add_column_if_missing($pdo, 'qps_question_bank_drafts', 'dept_code', "VARCHAR(50) NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_drafts', 'dept_name', "VARCHAR(255) NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_drafts', 'paper_code', "VARCHAR(100) NOT NULL DEFAULT ''");
 qps_add_column_if_missing($pdo, 'qps_question_bank_drafts', 'course_title', "VARCHAR(255) NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_drafts', 'course_type', "VARCHAR(50) NOT NULL DEFAULT 'OBE'");
 qps_add_column_if_missing($pdo, 'qps_question_bank_drafts', 'total_marks', "INT NOT NULL DEFAULT 0");
 qps_add_column_if_missing($pdo, 'qps_question_bank_drafts', 'hod_remarks', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_drafts', 'coe_remarks', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_drafts', 'version_no', "INT UNSIGNED NOT NULL DEFAULT 1");

 $pdo->exec("CREATE TABLE IF NOT EXISTS qps_question_bank_draft_questions (
    id {$autoInc},
    draft_id BIGINT UNSIGNED NULL,
    fixed_q_no INT UNSIGNED NOT NULL DEFAULT 1,
    staff_code VARCHAR(100) NOT NULL DEFAULT 'STAFF',
    paper_code VARCHAR(100) NOT NULL DEFAULT '',
    semester VARCHAR(50) NULL,
    academic_year VARCHAR(50) NULL,
    q_number INT NOT NULL DEFAULT 0,
    unit_no INT NOT NULL DEFAULT 1,
    sub_unit VARCHAR(50) NOT NULL DEFAULT '1.1',
    section_type VARCHAR(50) NOT NULL DEFAULT 'SECTION-A',
    question_type VARCHAR(50) NOT NULL DEFAULT 'MCQ',
    question_text LONGTEXT NOT NULL,
    marks INT NOT NULL DEFAULT 1,
    k_level VARCHAR(20) NOT NULL DEFAULT 'K1',
    co_level VARCHAR(20) NOT NULL DEFAULT 'CO1',
    options_json LONGTEXT NULL,
    answer_key TEXT NULL,
    match_column_a LONGTEXT NULL,
    match_column_b LONGTEXT NULL,
    assertion_text TEXT NULL,
    reason_text TEXT NULL,
    image_url VARCHAR(700) NULL,
    has_formula TINYINT(1) NOT NULL DEFAULT 0,
    formula_latex TEXT NULL,
    language VARCHAR(20) NOT NULL DEFAULT 'en',
    correction_status VARCHAR(50) NOT NULL DEFAULT 'CLEAN',
    remarks TEXT NULL,
    version_no INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

 // Dynamic column migrations for qps_question_bank_draft_questions
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'draft_id', "BIGINT UNSIGNED NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'staff_code', "VARCHAR(100) NOT NULL DEFAULT 'STAFF'");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'paper_code', "VARCHAR(100) NOT NULL DEFAULT ''");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'fixed_q_no', "INT UNSIGNED NOT NULL DEFAULT 1");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'semester', "VARCHAR(50) NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'academic_year', "VARCHAR(50) NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'q_number', "INT NOT NULL DEFAULT 0");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'unit_no', "INT NOT NULL DEFAULT 1");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'sub_unit', "VARCHAR(50) NOT NULL DEFAULT '1.1'");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'section_type', "VARCHAR(50) NOT NULL DEFAULT 'SECTION-A'");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'question_type', "VARCHAR(50) NOT NULL DEFAULT 'MCQ'");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'question_text', "LONGTEXT NOT NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'marks', "INT NOT NULL DEFAULT 1");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'k_level', "VARCHAR(20) NOT NULL DEFAULT 'K1'");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'co_level', "VARCHAR(20) NOT NULL DEFAULT 'CO1'");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'options_json', "LONGTEXT NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'answer_key', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'match_column_a', "LONGTEXT NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'match_column_b', "LONGTEXT NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'assertion_text', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'reason_text', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'image_url', "VARCHAR(700) NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'has_formula', "TINYINT(1) NOT NULL DEFAULT 0");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'formula_latex', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'language', "VARCHAR(20) NOT NULL DEFAULT 'en'");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'correction_status', "VARCHAR(50) NOT NULL DEFAULT 'CLEAN'");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'remarks', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'version_no', "INT UNSIGNED NOT NULL DEFAULT 1");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'hod_approved_at', "DATETIME NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'coe_approved_at', "DATETIME NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'coe_returned_at', "DATETIME NULL");
 qps_add_column_if_missing($pdo, 'qps_question_bank_draft_questions', 'master_question_id', "BIGINT UNSIGNED NULL");

 $pdo->exec("CREATE TABLE IF NOT EXISTS qps_final_blueprints (
    id {$autoInc},
    paper_code VARCHAR(100) NOT NULL,
    course_title VARCHAR(255) NULL,
    semester VARCHAR(50) NOT NULL,
    academic_year VARCHAR(50) NOT NULL,
    exam_type VARCHAR(120) NOT NULL DEFAULT 'Odd Semester End Examination',
    total_marks INT NOT NULL DEFAULT 75,
    slots_config LONGTEXT NOT NULL,
    created_by VARCHAR(100) NULL,
    updated_by VARCHAR(100) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

 // Ensure questions and question_banks have all required columns
 qps_add_column_if_missing($pdo, 'questions', 'course_code', "VARCHAR(100) NULL");
 qps_add_column_if_missing($pdo, 'questions', 'paper_code', "VARCHAR(100) NULL");
 qps_add_column_if_missing($pdo, 'questions', 'sub_unit', "VARCHAR(50) NULL DEFAULT '1.1'");
 qps_add_column_if_missing($pdo, 'questions', 'fixed_q_no', "INT UNSIGNED NOT NULL DEFAULT 1");
 qps_add_column_if_missing($pdo, 'questions', 'options_json', "LONGTEXT NULL");
 qps_add_column_if_missing($pdo, 'questions', 'answer_key', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'questions', 'question_json', "LONGTEXT NULL");
 qps_add_column_if_missing($pdo, 'questions', 'match_column_a', "LONGTEXT NULL");
 qps_add_column_if_missing($pdo, 'questions', 'match_column_b', "LONGTEXT NULL");
 qps_add_column_if_missing($pdo, 'questions', 'assertion_text', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'questions', 'reason_text', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'questions', 'image_url', "VARCHAR(700) NULL");
 qps_add_column_if_missing($pdo, 'questions', 'question_type', "VARCHAR(50) NULL");
 qps_add_column_if_missing($pdo, 'questions', 'correction_status', "VARCHAR(50) NULL DEFAULT 'CLEAN'");
 qps_add_column_if_missing($pdo, 'questions', 'remarks', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'questions', 'last_used_year', "VARCHAR(50) NULL");
 qps_add_column_if_missing($pdo, 'questions', 'last_used_semester', "VARCHAR(50) NULL");
 qps_add_column_if_missing($pdo, 'questions', 'last_used_exam', "VARCHAR(120) NULL");
 qps_add_column_if_missing($pdo, 'questions', 'times_used', "INT NOT NULL DEFAULT 0");
 qps_add_column_if_missing($pdo, 'questions', 'updated_at', "DATETIME NULL");

 qps_add_column_if_missing($pdo, 'question_usage_history', 'paper_code', "VARCHAR(100) NULL");
 qps_add_column_if_missing($pdo, 'question_usage_history', 'fixed_q_no', "INT UNSIGNED NULL");
 qps_add_column_if_missing($pdo, 'question_usage_history', 'slot_number', "INT UNSIGNED NULL");

 qps_add_column_if_missing($pdo, 'question_banks', 'paper_code', "VARCHAR(100) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'subject_code', "VARCHAR(100) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'course_title', "VARCHAR(255) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'subject_name', "VARCHAR(255) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'dept_code', "VARCHAR(50) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'dept_name', "VARCHAR(255) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'semester', "VARCHAR(50) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'academic_year', "VARCHAR(50) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'exam_type', "VARCHAR(120) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'regulation', "VARCHAR(80) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'degree_level', "VARCHAR(30) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'max_marks', "INT NOT NULL DEFAULT 75");
 qps_add_column_if_missing($pdo, 'question_banks', 'total_questions', "INT NOT NULL DEFAULT 0");
 qps_add_column_if_missing($pdo, 'question_banks', 'status', "VARCHAR(50) NOT NULL DEFAULT 'DRAFT'");
 qps_add_column_if_missing($pdo, 'question_banks', 'review_comments', "TEXT NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'questions_json', "LONGTEXT NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'course_type', "VARCHAR(50) NOT NULL DEFAULT 'OBE'");
 qps_add_column_if_missing($pdo, 'question_banks', 'staff_code', "VARCHAR(100) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'verified_by', "VARCHAR(100) NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'verified_at', "DATETIME NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'submitted_at', "DATETIME NULL");
 qps_add_column_if_missing($pdo, 'question_banks', 'updated_at', "DATETIME NULL");

 qps_add_column_if_missing($pdo, 'blueprints', 'paper_code', "VARCHAR(100) NULL");
 qps_add_column_if_missing($pdo, 'blueprints', 'course_title', "VARCHAR(255) NULL");

 qps_add_column_if_missing($pdo, 'generated_papers', 'paper_code', "VARCHAR(100) NULL");
 qps_add_column_if_missing($pdo, 'generated_papers', 'course_code', "VARCHAR(100) NULL");
 qps_add_column_if_missing($pdo, 'generated_papers', 'course_title', "VARCHAR(255) NULL");
}

/**
 * Check if a question (by ID or fixed_q_no) was used in prior academic years or semesters
 * Returns null if unused, or associative array with usage details
 */
function qps_check_question_prior_usage(PDO $pdo, string $paperCode, $questionId = null, $fixedQNo = null, ?string $currentYear = null, ?string $currentSemester = null): ?array {
    $qid = (int)($questionId ?? 0);
    $fqno = (int)($fixedQNo ?? 0);
    $paperCode = strtoupper(trim($paperCode));

    if ($paperCode === '' && $qid <= 0 && $fqno <= 0) return null;

    try {
        // 1. Check direct question_usage_history record
        $sql = "SELECT quh.*, gp.paper_code as gen_paper_code, gp.academic_year as gen_year, gp.semester as gen_sem, gp.set_name 
                FROM question_usage_history quh 
                LEFT JOIN generated_papers gp ON gp.id = quh.paper_id 
                WHERE (UPPER(quh.paper_code) = UPPER(?) OR UPPER(COALESCE(gp.paper_code, '')) = UPPER(?))";
        
        $params = [$paperCode, $paperCode];
        $clauses = [];

        if ($qid > 0) {
            $clauses[] = "quh.question_id = ?";
            $params[] = $qid;
        }
        if ($fqno > 0) {
            $clauses[] = "quh.fixed_q_no = ?";
            $params[] = $fqno;
            $clauses[] = "quh.question_id IN (SELECT id FROM questions WHERE fixed_q_no = ? AND (UPPER(course_code) = UPPER(?) OR bank_id IN (SELECT id FROM question_banks WHERE UPPER(paper_code) = UPPER(?))))";
            $params[] = $fqno;
            $params[] = $paperCode;
            $params[] = $paperCode;
        }

        if (!empty($clauses)) {
            $sql .= " AND (" . implode(" OR ", $clauses) . ")";
        }

        $sql .= " ORDER BY quh.id DESC LIMIT 1";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $usedYear = trim((string)($row['academic_year'] ?: ($row['gen_year'] ?: '')));
            $usedSem = trim((string)($row['semester'] ?: ($row['gen_sem'] ?: '')));
            $pCode = $row['paper_code'] ?: ($row['gen_paper_code'] ?: $paperCode);

            // Is this a prior usage (different year/sem or past session)?
            $isPrior = true;
            if ($currentYear && $currentSemester && $usedYear === $currentYear && $usedSem === $currentSemester) {
                // Used in the same session paper set
                $isPrior = false;
            }

            if ($isPrior && $usedYear !== '') {
                return [
                    'is_used' => true,
                    'academic_year' => $usedYear,
                    'semester' => $usedSem,
                    'exam_session' => $row['exam_session'] ?? 'Semester End Examination',
                    'paper_code' => $pCode,
                    'slot_number' => $row['slot_number'] ?? null,
                    'fixed_q_no' => $row['fixed_q_no'] ?? $fqno,
                    'question_id' => $row['question_id'] ?? $qid,
                    'message' => "used in the {$usedYear} semester ({$usedSem})"
                ];
            }
        }

        // 2. Also check questions table for last_used_year / last_used_semester
        if ($qid > 0 || $fqno > 0) {
            $stQ = $pdo->prepare("SELECT id, fixed_q_no, last_used_year, last_used_semester, last_used_exam, course_code FROM questions WHERE (id = ? OR (fixed_q_no = ? AND fixed_q_no > 0 AND UPPER(course_code) = UPPER(?))) AND last_used_year IS NOT NULL AND last_used_year != '' LIMIT 1");
            $stQ->execute([$qid, $fqno, $paperCode]);
            $qRow = $stQ->fetch(PDO::FETCH_ASSOC);
            if ($qRow && !empty($qRow['last_used_year'])) {
                $usedYear = $qRow['last_used_year'];
                $usedSem = $qRow['last_used_semester'] ?: 'Semester 1';
                if (!$currentYear || !$currentSemester || $usedYear !== $currentYear || $usedSem !== $currentSemester) {
                    return [
                        'is_used' => true,
                        'academic_year' => $usedYear,
                        'semester' => $usedSem,
                        'exam_session' => $qRow['last_used_exam'] ?? 'Semester End Examination',
                        'paper_code' => $qRow['course_code'] ?: $paperCode,
                        'slot_number' => null,
                        'fixed_q_no' => $qRow['fixed_q_no'],
                        'question_id' => $qRow['id'],
                        'message' => "used in the {$usedYear} semester ({$usedSem})"
                    ];
                }
            }
        }
    } catch (Throwable $e) {}

    return null;
}
