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

    // Backward-compatible migration for installations created before the ON/OFF control.
    try {
        $col = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_bank_blueprints' AND COLUMN_NAME='qbb_enabled'")->fetchColumn();
        if (!(int)$col) {
            $pdo->exec("ALTER TABLE question_bank_blueprints ADD COLUMN qbb_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
            $pdo->exec("UPDATE question_bank_blueprints SET qbb_enabled=1 WHERE status='PUBLISHED'");
        }
    } catch (Throwable $e) {
        // Existing installations can apply database/migrate_question_bank_blueprints.sql manually.
    }
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
        if (!in_array($choice, ['ALL','ONE_OF_PAIR','ANY','COMPULSORY','VSA_ALL'], true)) $choice = 'ALL';

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


function qps_qbb_choice_labels(): array
{
    return [
        'ALL' => 'Answer ALL the questions:',
        'VSA_ALL' => 'II Answer the following in one or two sentences each:',
        'ONE_OF_PAIR' => 'Answer FIVE questions, selecting one from each set:',
        'ANY' => 'Answer any TWO questions:',
        'COMPULSORY' => 'Answer the following Question (COMPULSORY):'
    ];
}

function qps_qbb_pool_stats(PDO $pdo, string $paperCode, string $semester = '', string $academicYear = '', string $examType = '', bool $includeDrafts = false): array
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
    if($bankIds){$ph=implode(',',array_fill(0,count($bankIds),'?'));$st=$pdo->prepare("SELECT q.*, qb.paper_code AS course_code FROM questions q INNER JOIN question_banks qb ON qb.id=q.bank_id WHERE q.bank_id IN ($ph) ORDER BY q.bank_id,q.q_number,q.id");$st->execute($bankIds);$rows=$st->fetchAll(PDO::FETCH_ASSOC);}
    if($includeDrafts){try{$ds=$pdo->prepare("SELECT questions_json FROM qps_question_bank_drafts WHERE UPPER(paper_code)=UPPER(?) AND status IN ('DRAFT','SUBMITTED_TO_HOD')");$ds->execute([$paperCode]);foreach($ds->fetchAll(PDO::FETCH_COLUMN) as $json){$dec=json_decode((string)$json,true);$dqs=is_array($dec)?($dec['questions']??$dec):[];foreach($dqs as $dq){if(is_array($dq)){$dq['course_code']=$paperCode;$dq['bank_id']=0;$rows[]=$dq;}}}}catch(Throwable $e){}}
    if(!$rows)return ['found'=>false,'total'=>0,'units'=>[],'sub_units'=>[],'combinations'=>[]];
    $seen=[]; $pool=[];
    foreach($rows as $q){
        $text=trim(strip_tags((string)($q['question_text']??''))); if($text==='') continue;
        $sig=mb_strtolower(preg_replace('/\\s+/u',' ',implode(' ',[$text,(string)($q['options_json']??''),(string)($q['answer_key']??''),(string)($q['assertion']??''),(string)($q['reason']??''),(string)($q['sub_questions']??'')])), 'UTF-8');
        $h=hash('sha256',$sig); if(isset($seen[$h])) continue; $seen[$h]=1; $pool[]=$q;
    }
    $units=[]; $subs=[]; $comb=[];
    foreach($pool as $q){
        $u=(int)($q['unit_no']??$q['unit']??0); if($u<1||$u>5) continue;
        $su=trim((string)($q['sub_unit']??$q['subunit']??'')); if(!preg_match('/^[1-5]\\.[0-9]+$/',$su)) $su=$u.'.1';
        $k=strtoupper(trim((string)($q['k_level']??'K1'))); $co=strtoupper(trim((string)($q['co_level']??'')));
        $marks=(int)($q['marks']??0); $sec=strtoupper(trim((string)($q['section_type']??''))); $type=strtoupper(trim((string)($q['question_type']??'')));
        $units[$u]=($units[$u]??0)+1; $subs[$su]=($subs[$su]??0)+1;
        $key=$su.'|'.$type.'|'.$k.'|'.$marks.'|'.$sec; $comb[$key]=($comb[$key]??0)+1;
        if($type==='') { $key2=$su.'|ANY|'.$k.'|'.$marks.'|'.$sec; $comb[$key2]=($comb[$key2]??0)+1; }
    }
    ksort($units,SORT_NATURAL); uksort($subs,'strnatcmp');
    return ['found'=>true,'total'=>count($pool),'units'=>$units,'sub_units'=>$subs,'combinations'=>$comb,'bank_ids'=>$bankIds];
}

function qps_qbb_validate_against_pool(PDO $pdo, string $paperCode, string $semester, string $academicYear, string $examType, array $matrix, bool $includeDrafts = true): array
{
    $stats=qps_qbb_pool_stats($pdo,$paperCode,$semester,$academicYear,$examType,$includeDrafts);
    if(!$stats['found']) return ['errors'=>['No submitted/verified question bank is available for '.$paperCode.'. Publish is blocked until the course has an approved question pool.'],'stats'=>$stats];
    $errors=[];
    $unitUsed=[]; $subUsed=[];
    foreach($matrix as $i=>$row){
        $n=$i+1; $u=(int)($row['unit']??0); $su=trim((string)($row['sub_unit']??'')); $count=(int)($row['required_count']??0);
        if($u<1||$u>5||!isset($stats['units'][$u])) { $errors[]="Row {$n}: Unit {$u} has no questions in the cumulative uploaded pool."; continue; }
        $availableUnit=(int)$stats['units'][$u];
        $unitUsed[$u]=($unitUsed[$u]??0)+$count;
        if($su!=='' && !isset($stats['sub_units'][$su])) { $errors[]="Row {$n}: Sub-Unit {$su} has no questions in the cumulative uploaded pool."; continue; }
        $availableSub=(int)($stats['sub_units'][$su]??0); $subUsed[$su]=($subUsed[$su]??0)+$count;
        if($count>$availableSub) $errors[]="Row {$n}: {$su} requires {$count} questions, but the cumulative {$paperCode} pool contains only {$availableSub}.";
        if($count>$availableUnit) $errors[]="Row {$n}: Unit {$u} requires {$count} questions, but Unit {$u} totally contains only {$availableUnit} in the cumulative {$paperCode} pool.";
    }
    foreach($unitUsed as $u=>$used){$avail=(int)($stats['units'][$u]??0); if($used>$avail) $errors[]="Unit {$u}: blueprint requests {$used} questions but the cumulative pool contains only {$avail}.";}
    foreach($subUsed as $su=>$used){$avail=(int)($stats['sub_units'][$su]??0); if($used>$avail) $errors[]="Sub-Unit {$su}: blueprint requests {$used} questions but the cumulative pool contains only {$avail}.";}
    return ['errors'=>array_values(array_unique($errors)),'stats'=>$stats];
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
