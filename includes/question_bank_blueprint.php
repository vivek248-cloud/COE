<?php
declare(strict_types=1);

/**
 * Course-specific Question Bank Blueprint (QBB) support.
 *
 * This is intentionally separate from the COE OBE blueprint table "blueprints".
 * QBB records are created by HODs for a specific course and are published to
 * teaching staff only after the HOD explicitly publishes them.
 */

function qps_qbb_ensure_schema(PDO $pdo): void
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver !== 'mysql') {
        throw new RuntimeException('Question Bank Blueprint requires MySQL/MariaDB.');
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
        'is_hod' => isHOD() && ($isHodFromErp ?? false || strtoupper((string)getUserRole()) === 'HOD')
    ];
}

function qps_qbb_normalize_matrix(array $rows): array
{
    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $section = strtoupper(trim((string)($row['section'] ?? 'A')));
        if (preg_match('/^SECTION[- ]?([A-D])$/', $section, $m)) $section = $m[1];
        if (!in_array($section, ['A','B','C','D'], true)) $section = 'A';

        $unit = max(1, min(5, (int)($row['unit'] ?? 1)));
        $subUnit = trim((string)($row['sub_unit'] ?? ($unit . '.1')));
        if (!preg_match('/^[1-5]\.[1-5]$/', $subUnit)) $subUnit = $unit . '.1';

        $type = strtoupper(trim((string)($row['question_type'] ?? 'MCQ')));
        $allowedTypes = ['MCQ','MATCH','ASSERTION_REASON','VSA','PARAGRAPH','ESSAY','EITHER_OR','PASSAGE'];
        if (!in_array($type, $allowedTypes, true)) $type = 'MCQ';

        $k = strtoupper(trim((string)($row['k_level'] ?? 'K1')));
        if (!preg_match('/^K[1-6]$/', $k)) $k = 'K1';

        $marks = max(0, min(100, (int)($row['marks'] ?? 1)));
        $count = max(0, min(999, (int)($row['required_count'] ?? 0)));

        $choice = strtoupper(trim((string)($row['choice_mode'] ?? 'ALL')));
        if (!in_array($choice, ['ALL','ONE_OF_PAIR','ANY'], true)) $choice = 'ALL';

        $out[] = [
            'section' => $section,
            'unit' => $unit,
            'sub_unit' => $subUnit,
            'question_type' => $type,
            'k_level' => $k,
            'marks' => $marks,
            'required_count' => $count,
            'choice_mode' => $choice,
            'compulsory' => !empty($row['compulsory']) ? 1 : 0,
            'instruction' => trim((string)($row['instruction'] ?? ''))
        ];
    }

    return $out;
}

function qps_qbb_validate_matrix(array $matrix): array
{
    $errors = [];
    $totalCount = 0;
    $totalMarks = 0;

    if (!$matrix) {
        $errors[] = 'Add at least one blueprint row.';
        return ['errors' => $errors, 'total_questions' => 0, 'total_marks' => 0];
    }

    foreach ($matrix as $i => $row) {
        $n = $i + 1;
        $count = (int)($row['required_count'] ?? 0);
        $marks = (int)($row['marks'] ?? 0);

        if ($count <= 0) $errors[] = "Row {$n}: required question count must be greater than 0.";
        if ($marks <= 0) $errors[] = "Row {$n}: marks must be greater than 0.";

        $totalCount += max(0, $count);
        $totalMarks += max(0, $count) * max(0, $marks);
    }

    return [
        'errors' => $errors,
        'total_questions' => $totalCount,
        'total_marks' => $totalMarks
    ];
}

function qps_qbb_course(PDO $pdo, string $deptCode, string $paperCode): ?array
{
    $st = $pdo->prepare(
        "SELECT coursecode, coursetitle, dept_code, level, maxmark, credit, type
         FROM courses
         WHERE UPPER(coursecode)=UPPER(?) AND UPPER(dept_code)=UPPER(?)
         LIMIT 1"
    );
    $st->execute([$paperCode, $deptCode]);
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
