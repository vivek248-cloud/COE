<?php
declare(strict_types=1);

/**
 * Holy Cross College (Autonomous) - Course Question Bank Blueprint (QBB) Engine
 * Exact Layout Specification matching sample-blueprint.jpeg:
 *  - 15+ Sub-Units (1.1 - 5.5) grouped across Units I to V
 *  - 14 Target Category Columns:
 *      MC: K1(R), K1(R)(MATCH), K2(U), K2(U)(ASSERTION), K3(AP)
 *      V.S.A: K1(R), K2(U), K3(AP)
 *      PART B: K1(R), K2(U), K4(AN)
 *      PART C: K1(R), K3(AP)
 *      PART D: K2(U)
 *  - Standard 275-Question OBE Cumulative Question Bank Pool Target
 */

function qps_qbb_ensure_schema(PDO $pdo): void
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS question_bank_blueprints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                department_code VARCHAR(30) NOT NULL,
                paper_code VARCHAR(100) NOT NULL,
                course_title VARCHAR(255) NOT NULL,
                semester VARCHAR(50) NOT NULL,
                academic_year VARCHAR(20) NOT NULL,
                exam_type VARCHAR(120) NOT NULL,
                total_questions INT NOT NULL DEFAULT 275,
                total_marks INT NOT NULL DEFAULT 0,
                matrix_json TEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
                qbb_enabled INTEGER NOT NULL DEFAULT 0,
                created_by VARCHAR(100) NOT NULL,
                created_by_name VARCHAR(255) NULL,
                published_by VARCHAR(100) NULL,
                published_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS question_bank_blueprints (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            department_code VARCHAR(30) NOT NULL,
            paper_code VARCHAR(100) NOT NULL,
            course_title VARCHAR(255) NOT NULL,
            semester VARCHAR(50) NOT NULL,
            academic_year VARCHAR(20) NOT NULL,
            exam_type VARCHAR(120) NOT NULL,
            total_questions INT NOT NULL DEFAULT 275,
            total_marks INT NOT NULL DEFAULT 0,
            matrix_json LONGTEXT NOT NULL,
            status ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
            qbb_enabled TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL,
            created_by_name VARCHAR(255) NULL,
            published_by VARCHAR(100) NULL,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_qbb_course (department_code, paper_code),
            KEY idx_qbb_status (status),
            KEY idx_qbb_created_by (created_by),
            KEY idx_qbb_context (paper_code, semester, academic_year, exam_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    try {
        $col = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_bank_blueprints' AND COLUMN_NAME='qbb_enabled'")->fetchColumn();
        if (!(int)$col) {
            $pdo->exec("ALTER TABLE question_bank_blueprints ADD COLUMN qbb_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
            $pdo->exec("UPDATE question_bank_blueprints SET qbb_enabled=1 WHERE status='PUBLISHED'");
        }
    } catch (Throwable $e) {}
}

/**
 * Returns column definitions matching sample-blueprint.jpeg
 */
function qps_qbb_column_definitions(): array
{
    return [
        'mc_k1'     => ['group' => 'MC',     'label' => 'K1(R)',              'short' => 'MC K1',       'k' => 'K1', 'type' => 'MCQ',              'marks' => 1,  'color' => 'amber'],
        'mc_match'  => ['group' => 'MC',     'label' => 'K1(R) (MATCH)',      'short' => 'MC MATCH',    'k' => 'K1', 'type' => 'MATCH',            'marks' => 1,  'color' => 'amber'],
        'mc_k2'     => ['group' => 'MC',     'label' => 'K2(U)',              'short' => 'MC K2',       'k' => 'K2', 'type' => 'MCQ',              'marks' => 1,  'color' => 'amber'],
        'mc_ar'     => ['group' => 'MC',     'label' => 'K2(U) (ASSERTION)',  'short' => 'MC AR',       'k' => 'K2', 'type' => 'ASSERTION_REASON', 'marks' => 1,  'color' => 'amber'],
        'mc_k3'     => ['group' => 'MC',     'label' => 'K3(AP)',             'short' => 'MC K3',       'k' => 'K3', 'type' => 'MCQ',              'marks' => 1,  'color' => 'amber'],

        'vsa_k1'    => ['group' => 'V.S.A',  'label' => 'K1(R)',              'short' => 'VSA K1',      'k' => 'K1', 'type' => 'VSA',              'marks' => 2,  'color' => 'sky'],
        'vsa_k2'    => ['group' => 'V.S.A',  'label' => 'K2(U)',              'short' => 'VSA K2',      'k' => 'K2', 'type' => 'VSA',              'marks' => 2,  'color' => 'sky'],
        'vsa_k3'    => ['group' => 'V.S.A',  'label' => 'K3(AP)',             'short' => 'VSA K3',      'k' => 'K3', 'type' => 'VSA',              'marks' => 2,  'color' => 'sky'],

        'partb_k1'  => ['group' => 'PART B', 'label' => 'K1(R)',              'short' => 'Part B K1',   'k' => 'K1', 'type' => 'PARAGRAPH',        'marks' => 5,  'color' => 'purple'],
        'partb_k2'  => ['group' => 'PART B', 'label' => 'K2(U)',              'short' => 'Part B K2',   'k' => 'K2', 'type' => 'PARAGRAPH',        'marks' => 5,  'color' => 'purple'],
        'partb_k4'  => ['group' => 'PART B', 'label' => 'K4(AN)',             'short' => 'Part B K4',   'k' => 'K4', 'type' => 'PARAGRAPH',        'marks' => 5,  'color' => 'purple'],

        'partc_k1'  => ['group' => 'PART C', 'label' => 'K1(R)',              'short' => 'Part C K1',   'k' => 'K1', 'type' => 'ESSAY',            'marks' => 10, 'color' => 'emerald'],
        'partc_k3'  => ['group' => 'PART C', 'label' => 'K3(AP)',             'short' => 'Part C K3',   'k' => 'K3', 'type' => 'ESSAY',            'marks' => 10, 'color' => 'emerald'],

        'partd_k2'  => ['group' => 'PART D', 'label' => 'K2(U)',              'short' => 'Part D K2',   'k' => 'K2', 'type' => 'COMPULSORY',       'marks' => 10, 'color' => 'rose']
    ];
}

/**
 * Returns exact standard 275-Question Blueprint Grid as seen in sample-blueprint.jpeg
 */
function qps_qbb_default_hcc_grid(): array
{
    return [
        ['unit' => 1, 'sub_unit' => '1.1', 'mc_k1' => 1, 'mc_match' => 1, 'mc_k2' => 1, 'mc_ar' => 0, 'mc_k3' => 0, 'vsa_k1' => 1, 'vsa_k2' => 1, 'vsa_k3' => 0, 'partb_k1' => 1, 'partb_k2' => 1, 'partb_k4' => 0, 'partc_k1' => 1, 'partc_k3' => 0, 'partd_k2' => 0],
        ['unit' => 1, 'sub_unit' => '1.2', 'mc_k1' => 5, 'mc_match' => 1, 'mc_k2' => 4, 'mc_ar' => 2, 'mc_k3' => 3, 'vsa_k1' => 4, 'vsa_k2' => 2, 'vsa_k3' => 6, 'partb_k1' => 2, 'partb_k2' => 2, 'partb_k4' => 2, 'partc_k1' => 0, 'partc_k3' => 2, 'partd_k2' => 1],
        ['unit' => 1, 'sub_unit' => '1.3', 'mc_k1' => 0, 'mc_match' => 0, 'mc_k2' => 1, 'mc_ar' => 0, 'mc_k3' => 1, 'vsa_k1' => 2, 'vsa_k2' => 4, 'vsa_k3' => 0, 'partb_k1' => 1, 'partb_k2' => 1, 'partb_k4' => 0, 'partc_k1' => 1, 'partc_k3' => 0, 'partd_k2' => 1],

        ['unit' => 2, 'sub_unit' => '2.1', 'mc_k1' => 2, 'mc_match' => 1, 'mc_k2' => 2, 'mc_ar' => 1, 'mc_k3' => 1, 'vsa_k1' => 4, 'vsa_k2' => 3, 'vsa_k3' => 2, 'partb_k1' => 2, 'partb_k2' => 2, 'partb_k4' => 1, 'partc_k1' => 1, 'partc_k3' => 0, 'partd_k2' => 1],
        ['unit' => 2, 'sub_unit' => '2.2', 'mc_k1' => 2, 'mc_match' => 1, 'mc_k2' => 2, 'mc_ar' => 1, 'mc_k3' => 3, 'vsa_k1' => 0, 'vsa_k2' => 2, 'vsa_k3' => 2, 'partb_k1' => 1, 'partb_k2' => 1, 'partb_k4' => 1, 'partc_k1' => 0, 'partc_k3' => 1, 'partd_k2' => 0],
        ['unit' => 2, 'sub_unit' => '2.3', 'mc_k1' => 2, 'mc_match' => 0, 'mc_k2' => 2, 'mc_ar' => 0, 'mc_k3' => 0, 'vsa_k1' => 3, 'vsa_k2' => 2, 'vsa_k3' => 2, 'partb_k1' => 1, 'partb_k2' => 1, 'partb_k4' => 0, 'partc_k1' => 1, 'partc_k3' => 1, 'partd_k2' => 1],

        ['unit' => 3, 'sub_unit' => '3.1', 'mc_k1' => 4, 'mc_match' => 1, 'mc_k2' => 2, 'mc_ar' => 2, 'mc_k3' => 3, 'vsa_k1' => 3, 'vsa_k2' => 3, 'vsa_k3' => 5, 'partb_k1' => 2, 'partb_k2' => 1, 'partb_k4' => 1, 'partc_k1' => 1, 'partc_k3' => 1, 'partd_k2' => 0],
        ['unit' => 3, 'sub_unit' => '3.2', 'mc_k1' => 2, 'mc_match' => 1, 'mc_k2' => 1, 'mc_ar' => 0, 'mc_k3' => 1, 'vsa_k1' => 2, 'vsa_k2' => 2, 'vsa_k3' => 0, 'partb_k1' => 1, 'partb_k2' => 2, 'partb_k4' => 1, 'partc_k1' => 0, 'partc_k3' => 0, 'partd_k2' => 0],
        ['unit' => 3, 'sub_unit' => '3.3', 'mc_k1' => 0, 'mc_match' => 0, 'mc_k2' => 3, 'mc_ar' => 0, 'mc_k3' => 0, 'vsa_k1' => 2, 'vsa_k2' => 2, 'vsa_k3' => 1, 'partb_k1' => 1, 'partb_k2' => 1, 'partb_k4' => 0, 'partc_k1' => 0, 'partc_k3' => 1, 'partd_k2' => 1],

        ['unit' => 4, 'sub_unit' => '4.1', 'mc_k1' => 5, 'mc_match' => 0, 'mc_k2' => 4, 'mc_ar' => 0, 'mc_k3' => 2, 'vsa_k1' => 3, 'vsa_k2' => 3, 'vsa_k3' => 3, 'partb_k1' => 1, 'partb_k2' => 2, 'partb_k4' => 0, 'partc_k1' => 1, 'partc_k3' => 1, 'partd_k2' => 1],
        ['unit' => 4, 'sub_unit' => '4.2', 'mc_k1' => 1, 'mc_match' => 2, 'mc_k2' => 1, 'mc_ar' => 1, 'mc_k3' => 1, 'vsa_k1' => 3, 'vsa_k2' => 2, 'vsa_k3' => 1, 'partb_k1' => 3, 'partb_k2' => 2, 'partb_k4' => 2, 'partc_k1' => 0, 'partc_k3' => 0, 'partd_k2' => 0],
        ['unit' => 4, 'sub_unit' => '4.3', 'mc_k1' => 0, 'mc_match' => 0, 'mc_k2' => 1, 'mc_ar' => 1, 'mc_k3' => 1, 'vsa_k1' => 1, 'vsa_k2' => 2, 'vsa_k3' => 2, 'partb_k1' => 0, 'partb_k2' => 0, 'partb_k4' => 0, 'partc_k1' => 0, 'partc_k3' => 1, 'partd_k2' => 1],

        ['unit' => 5, 'sub_unit' => '5.1', 'mc_k1' => 3, 'mc_match' => 0, 'mc_k2' => 3, 'mc_ar' => 2, 'mc_k3' => 0, 'vsa_k1' => 4, 'vsa_k2' => 2, 'vsa_k3' => 4, 'partb_k1' => 2, 'partb_k2' => 0, 'partb_k4' => 2, 'partc_k1' => 1, 'partc_k3' => 1, 'partd_k2' => 0],
        ['unit' => 5, 'sub_unit' => '5.2', 'mc_k1' => 1, 'mc_match' => 1, 'mc_k2' => 2, 'mc_ar' => 0, 'mc_k3' => 3, 'vsa_k1' => 3, 'vsa_k2' => 3, 'vsa_k3' => 2, 'partb_k1' => 1, 'partb_k2' => 1, 'partb_k4' => 0, 'partc_k1' => 0, 'partc_k3' => 1, 'partd_k2' => 1],
        ['unit' => 5, 'sub_unit' => '5.3', 'mc_k1' => 2, 'mc_match' => 1, 'mc_k2' => 1, 'mc_ar' => 0, 'mc_k3' => 1, 'vsa_k1' => 0, 'vsa_k2' => 2, 'vsa_k3' => 0, 'partb_k1' => 1, 'partb_k2' => 3, 'partb_k4' => 0, 'partc_k1' => 0, 'partc_k3' => 0, 'partd_k2' => 1]
    ];
}

/**
 * Maps a single question item to one of the exact 14 columns
 */
function qps_qbb_classify_question(array $q): string
{
    $sec = strtoupper(trim((string)($q['section_type'] ?? '')));
    $k = strtoupper(trim((string)($q['k_level'] ?? '')));
    $m = (int)($q['marks'] ?? 0);
    $type = strtoupper(trim((string)($q['question_type'] ?? '')));
    $txt = mb_strtolower((string)($q['question_text'] ?? ''), 'UTF-8');

    // 1. MATCH THE FOLLOWING
    if (strpos($k, 'MATCH') !== false || strpos($sec, 'MATCH') !== false || $type === 'MATCH' || strpos($txt, 'match the following') !== false || strpos($txt, 'பொருத்துக') !== false || !empty($q['match_column_a'])) {
        return 'mc_match';
    }

    // 2. ASSERTION & REASON
    if (strpos($k, 'ASSERTION') !== false || strpos($sec, 'ASSERTION') !== false || in_array($type, ['AR', 'ASSERTION', 'ASSERTION_REASON'], true) || strpos($txt, 'assertion (a)') !== false || strpos($txt, 'assertion:') !== false || strpos($txt, 'கூற்று') !== false || !empty($q['assertion'])) {
        return 'mc_ar';
    }

    // 3. MC (1 Mark)
    if ($sec === 'MC' || $sec === 'PART A' || $m === 1 || $type === 'MCQ') {
        if (strpos($k, 'K1') !== false) return 'mc_k1';
        if (strpos($k, 'K2') !== false) return 'mc_k2';
        return 'mc_k3';
    }

    // 4. V.S.A (2 Marks)
    if (strpos($sec, 'V.S.A') !== false || strpos($sec, 'VSA') !== false || $m === 2 || $type === 'VSA') {
        if (strpos($k, 'K1') !== false) return 'vsa_k1';
        if (strpos($k, 'K2') !== false) return 'vsa_k2';
        return 'vsa_k3';
    }

    // 5. PART B (5 Marks - Paragraph / Either-Or)
    if (strpos($sec, 'PART B') !== false || strpos($sec, 'PART_B') !== false || $m === 5 || in_array($type, ['PARAGRAPH', 'PARA'], true)) {
        if (strpos($k, 'K1') !== false) return 'partb_k1';
        if (strpos($k, 'K2') !== false || strpos($k, 'K3') !== false) return 'partb_k2';
        return 'partb_k4';
    }

    // 6. PART D (10 Marks - Compulsory / Case Study)
    if (strpos($sec, 'PART D') !== false || strpos($sec, 'PART_D') !== false || strpos($sec, 'COMPULSORY') !== false || in_array($type, ['COMPULSORY', 'COMP'], true) || strpos($k, 'K6') !== false || strpos($txt, 'compulsory') !== false || strpos($txt, 'case study') !== false) {
        return 'partd_k2';
    }

    // 7. PART C (10 Marks / 8 Marks - Long Essays)
    if (strpos($sec, 'PART C') !== false || strpos($sec, 'PART_C') !== false || $m >= 8 || $type === 'ESSAY') {
        if (strpos($k, 'K1') !== false || strpos($k, 'K2') !== false) return 'partc_k1';
        return 'partc_k3';
    }

    return 'mc_k1';
}

function qps_qbb_hod_context(PDO $pdo, array $user): array
{
    $staffCode = trim((string)($user['staff_code'] ?? ''));
    if ($staffCode === '') {
        return ['staff_code' => '', 'name' => '', 'dept_code' => '', 'is_hod' => false];
    }

    $dept = trim((string)($user['dept_code'] ?? ($user['department_code'] ?? '')));
    $name = trim((string)($user['name'] ?? ''));
    $hodStatus = '';

    try {
        $st = $pdo->prepare(
            "SELECT
                COALESCE(NULLIF(deptcode,''), NULLIF(dept_code1,''), NULLIF(dept1,''), NULLIF(dept2,'')) AS resolved_dept,
                FIRST_NAME,
                hod_status,
                designation
             FROM pr_x_xxxx_staf_prof_mast
             WHERE UPPER(STAFF_CODE) = UPPER(?)
             LIMIT 1"
        );
        $st->execute([$staffCode]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        if ($dept === '') $dept = trim((string)($row['resolved_dept'] ?? ''));
        if ($name === '') $name = trim((string)($row['FIRST_NAME'] ?? ''));
        $hodStatus = strtoupper(trim((string)($row['hod_status'] ?? '')));
        $designation = strtoupper(trim((string)($row['designation'] ?? '')));
        $isHodFromErp = $hodStatus === 'Y' || strpos($designation, 'HEAD') !== false || strpos($designation, 'HOD') !== false;
    } catch (Throwable $e) {
        $isHodFromErp = false;
    }

    return [
        'staff_code' => $staffCode,
        'name' => $name !== '' ? $name : $staffCode,
        'dept_code' => strtoupper($dept),
        'is_hod' => isHOD() || $isHodFromErp || strtoupper((string)getUserRole()) === 'HOD'
    ];
}

/**
 * Returns distinct units & subunits available for a course
 */
function qps_qbb_get_course_subunits(PDO $pdo, string $paperCode): array
{
    $baseline = [
        '1.1', '1.2', '1.3',
        '2.1', '2.2', '2.3',
        '3.1', '3.2', '3.3',
        '4.1', '4.2', '4.3',
        '5.1', '5.2', '5.3'
    ];

    $found = [];
    try {
        // Check from questions table
        $st = $pdo->prepare("SELECT DISTINCT unit_no, sub_unit FROM questions q JOIN question_banks qb ON qb.id=q.bank_id WHERE UPPER(qb.paper_code)=UPPER(?)");
        $st->execute([$paperCode]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $su = trim((string)($r['sub_unit'] ?? ''));
            if (preg_match('/^[1-5]\.[0-9]+$/', $su)) {
                $found[] = $su;
            } elseif (!empty($r['unit_no']) && (int)$r['unit_no'] >= 1 && (int)$r['unit_no'] <= 5) {
                $found[] = ((int)$r['unit_no']) . '.1';
            }
        }

        // Check from question_banks JSON
        $stJ = $pdo->prepare("SELECT questions_json FROM question_banks WHERE UPPER(paper_code)=UPPER(?)");
        $stJ->execute([$paperCode]);
        foreach ($stJ->fetchAll(PDO::FETCH_COLUMN) as $j) {
            if (!$j) continue;
            $data = json_decode((string)$j, true);
            $qs = is_array($data) ? ($data['questions'] ?? $data) : [];
            if (is_array($qs)) {
                foreach ($qs as $q) {
                    $su = trim((string)($q['subunit_no'] ?? ($q['sub_unit'] ?? '')));
                    if (preg_match('/^[1-5]\.[0-9]+$/', $su)) {
                        $found[] = $su;
                    } elseif (!empty($q['unit_no']) && (int)$q['unit_no'] >= 1 && (int)$q['unit_no'] <= 5) {
                        $found[] = ((int)$q['unit_no']) . '.1';
                    }
                }
            }
        }
    } catch (Throwable $e) {}

    $all = array_values(array_unique(array_merge($baseline, $found)));
    usort($all, 'strnatcmp');
    return $all;
}

/**
 * Dynamic Course Grid Builder:
 *  - Automatically loads and populates the exact blueprint matrix when a course is loaded
 *  - Checks saved blueprint, question bank questions, or default standard preset
 */
function qps_qbb_build_grid_for_course(PDO $pdo, string $paperCode, string $academicYear = '', string $semester = '', string $examType = ''): array
{
    $colDefs = qps_qbb_column_definitions();
    $subunits = qps_qbb_get_course_subunits($pdo, $paperCode);

    // 1. Check if an official or draft blueprint already exists
    try {
        $st = $pdo->prepare("SELECT * FROM question_bank_blueprints WHERE UPPER(paper_code)=UPPER(?) ORDER BY (status='PUBLISHED') DESC, id DESC LIMIT 1");
        $st->execute([$paperCode]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing && !empty($existing['matrix_json'])) {
            $decoded = json_decode((string)$existing['matrix_json'], true);
            if (is_array($decoded)) {
                return qps_qbb_normalize_grid($decoded, $subunits);
            }
        }
    } catch (Throwable $e) {}

    // 2. Scan Question Bank for actual questions
    $qbCounts = [];
    $hasQbQuestions = false;
    try {
        $sql = "SELECT q.*, qb.paper_code AS course_code FROM questions q INNER JOIN question_banks qb ON qb.id=q.bank_id WHERE UPPER(qb.paper_code)=UPPER(?)";
        $stQ = $pdo->prepare($sql);
        $stQ->execute([$paperCode]);
        $qRows = $stQ->fetchAll(PDO::FETCH_ASSOC);

        if (empty($qRows)) {
            $stJ = $pdo->prepare("SELECT questions_json FROM question_banks WHERE UPPER(paper_code)=UPPER(?) ORDER BY id DESC LIMIT 1");
            $stJ->execute([$paperCode]);
            $j = $stJ->fetchColumn();
            if ($j) {
                $data = json_decode((string)$j, true);
                $qRows = is_array($data) ? ($data['questions'] ?? $data) : [];
            }
        }

        if (!empty($qRows)) {
            $hasQbQuestions = true;
            foreach ($qRows as $q) {
                if (!is_array($q)) continue;
                $u = max(1, min(5, (int)($q['unit_no'] ?? ($q['unit'] ?? 1))));
                $su = trim((string)($q['subunit_no'] ?? ($q['sub_unit'] ?? ($u . '.1'))));
                if (!preg_match('/^[1-5]\.[0-9]+$/', $su)) $su = $u . '.1';

                $colKey = qps_qbb_classify_question($q);
                $cellKey = $su . '_' . $colKey;
                $qbCounts[$cellKey] = ($qbCounts[$cellKey] ?? 0) + 1;
            }
        }
    } catch (Throwable $e) {}

    // If Question Bank has questions, populate from question bank counts!
    if ($hasQbQuestions) {
        $grid = [];
        foreach ($subunits as $su) {
            $u = (int)explode('.', $su)[0];
            $rowObj = ['unit' => $u, 'sub_unit' => $su];
            $rowSum = 0;
            foreach (array_keys($colDefs) as $ck) {
                $cellKey = $su . '_' . $ck;
                $val = (int)($qbCounts[$cellKey] ?? 0);
                $rowObj[$ck] = $val;
                $rowSum += $val;
            }
            $rowObj['total'] = $rowSum;
            $grid[] = $rowObj;
        }

        return qps_qbb_normalize_grid(['grid' => $grid], $subunits);
    }

    // Otherwise, load standard default 275-Question preset
    return qps_qbb_normalize_grid(['grid' => qps_qbb_default_hcc_grid()], $subunits);
}

/**
 * Normalizes input matrix ensuring all subunits are present and sorted
 */
function qps_qbb_normalize_grid(array $input, array $expectedSubunits = []): array
{
    $cols = array_keys(qps_qbb_column_definitions());
    $grid = [];
    $rawRows = $input['grid'] ?? ($input['rows'] ?? (isset($input[0]) ? $input : []));

    if (!is_array($rawRows) || empty($rawRows)) {
        $rawRows = qps_qbb_default_hcc_grid();
    }

    $keyedRows = [];
    foreach ($rawRows as $r) {
        if (!is_array($r)) continue;
        $sub = trim((string)($r['sub_unit'] ?? ($r['subunit'] ?? '1.1')));
        if (preg_match('/^[1-5]\.[0-9]+$/', $sub)) {
            $keyedRows[$sub] = $r;
        }
    }

    $subunitsToUse = !empty($expectedSubunits) ? $expectedSubunits : array_keys($keyedRows);
    if (empty($subunitsToUse)) {
        $subunitsToUse = [
            '1.1', '1.2', '1.3',
            '2.1', '2.2', '2.3',
            '3.1', '3.2', '3.3',
            '4.1', '4.2', '4.3',
            '5.1', '5.2', '5.3'
        ];
    }
    usort($subunitsToUse, 'strnatcmp');

    foreach ($subunitsToUse as $sub) {
        $u = (int)explode('.', $sub)[0];
        $r = $keyedRows[$sub] ?? [];
        $rowObj = [
            'unit' => $u,
            'sub_unit' => $sub
        ];

        $rowSum = 0;
        foreach ($cols as $ck) {
            $val = max(0, min(999, (int)($r[$ck] ?? 0)));
            $rowObj[$ck] = $val;
            $rowSum += $val;
        }
        $rowObj['total'] = $rowSum;
        $grid[] = $rowObj;
    }

    // Compute column totals & grand total
    $totals = [];
    $grandTotal = 0;
    foreach ($cols as $ck) {
        $colSum = array_sum(array_column($grid, $ck));
        $totals[$ck] = $colSum;
        $grandTotal += $colSum;
    }
    $totals['grand_total'] = $grandTotal;

    return [
        'grid' => $grid,
        'totals' => $totals
    ];
}

/**
 * Calculates Question Pool Statistics from uploaded question banks
 */
function qps_qbb_pool_stats(PDO $pdo, string $paperCode, string $semester = '', string $academicYear = '', string $examType = '', bool $includeDrafts = true): array
{
    $sql = "SELECT id FROM question_banks
            WHERE UPPER(paper_code)=UPPER(?)
              AND (UPPER(COALESCE(status,'')) IN ('SUBMITTED TO COE','APPROVED','HOD APPROVED','VERIFIED','COE VERIFIED','PUBLISHED')
                   OR UPPER(COALESCE(hod_status,''))='APPROVED')";
    $params = [$paperCode];
    if ($semester !== '') { $sql .= " AND (semester=? OR semester IS NULL OR semester='')"; $params[]=$semester; }
    if ($academicYear !== '') { $sql .= " AND (academic_year=? OR academic_year IS NULL OR academic_year='')"; $params[]=$academicYear; }
    if ($examType !== '') { $sql .= " AND (exam_type=? OR exam_type IS NULL OR exam_type='')"; $params[]=$examType; }
    $st=$pdo->prepare($sql); $st->execute($params);
    $bankIds=array_values(array_unique(array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN))));
    $rows=[];
    if($bankIds){
        $ph=implode(',',array_fill(0,count($bankIds),'?'));
        $st=$pdo->prepare("SELECT q.*, qb.paper_code AS course_code FROM questions q INNER JOIN question_banks qb ON qb.id=q.bank_id WHERE q.bank_id IN ($ph) ORDER BY q.bank_id,q.q_number,q.id");
        $st->execute($bankIds);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    }
    if($includeDrafts){
        try{
            $ds=$pdo->prepare("SELECT questions_json FROM question_bank_drafts WHERE UPPER(paper_code)=UPPER(?) AND status IN ('DRAFT','SUBMITTED_TO_HOD','Draft','Submitted to HOD')");
            $ds->execute([$paperCode]);
            foreach($ds->fetchAll(PDO::FETCH_COLUMN) as $json){
                $dec=json_decode((string)$json,true);
                $dqs=is_array($dec)?($dec['questions']??$dec):[];
                foreach($dqs as $dq){
                    if(is_array($dq)){$dq['course_code']=$paperCode;$dq['bank_id']=0;$rows[]=$dq;}
                }
            }
        }catch(Throwable $e){}
    }
    if(!$rows) return ['found'=>false,'total'=>0,'units'=>[],'sub_units'=>[],'grid_counts'=>[],'bank_ids'=>$bankIds];

    $seen=[]; $pool=[];
    foreach($rows as $q){
        $text=trim(strip_tags((string)($q['question_text']??''))); if($text==='') continue;
        $sig=mb_strtolower(preg_replace('/\\s+/u',' ',implode(' ',[$text,(string)($q['options_json']??''),(string)($q['answer_key']??''),(string)($q['assertion']??''),(string)($q['reason']??''),(string)($q['sub_questions']??'')])), 'UTF-8');
        $h=hash('sha256',$sig); if(isset($seen[$h])) continue; $seen[$h]=1; $pool[]=$q;
    }

    $units=[]; $subs=[]; $gridCounts=[];

    foreach($pool as $q){
        $u=(int)($q['unit_no']??$q['unit']??0); if($u<1||$u>5) continue;
        $su=trim((string)($q['subunit_no'] ?? ($q['sub_unit'] ?? ($q['subunit'] ?? ($u . '.1')))));
        if(!preg_match('/^[1-5]\\.[0-9]+$/',$su)) $su=$u.'.1';

        $units[$u]=($units[$u]??0)+1;
        $subs[$su]=($subs[$su]??0)+1;

        $matchedCol = qps_qbb_classify_question($q);
        if ($matchedCol) {
            $cellKey = $su . '_' . $matchedCol;
            $gridCounts[$cellKey] = ($gridCounts[$cellKey] ?? 0) + 1;
        }
    }

    ksort($units, SORT_NATURAL);
    uksort($subs, 'strnatcmp');

    return [
        'found' => true,
        'total' => count($pool),
        'units' => $units,
        'sub_units' => $subs,
        'grid_counts' => $gridCounts,
        'bank_ids' => $bankIds
    ];
}

function qps_qbb_course(PDO $pdo, string $deptCode, string $paperCode): ?array
{
    $st = $pdo->prepare(
        "SELECT coursecode, coursetitle, dept_code, level, maxmark, credit, type
         FROM courses
         WHERE UPPER(coursecode)=UPPER(?)
         LIMIT 1"
    );
    $st->execute([$paperCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function qps_qbb_staff_assigned_to_course(PDO $pdo, string $staffCode, string $paperCode): bool
{
    $st = $pdo->prepare(
        "SELECT 1
         FROM timetablefaculty
         WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?)
         LIMIT 1"
    );
    $st->execute([$staffCode, $paperCode]);
    return (bool)$st->fetchColumn();
}
