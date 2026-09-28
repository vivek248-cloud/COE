<?php
/**
 * Super Admin Control Center - Full CRUD Suite
 * Holy Cross College (Autonomous) Examination Management System
 */
define('PAGE_TITLE', 'Super Admin Control Center');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';

requireCOE();
$pdo = getDBConnection();
qps_ensure_aux_schema($pdo);
$user = getCurrentUser();

$msg = '';
$error = '';
$tab = $_GET['tab'] ?? 'overview';

// Determine permission levels
$isSuperAdmin = isSuperAdmin();
$canEditERP = canEditERPMasters();

// Handle all admin CRUD actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $now = date('Y-m-d H:i:s');

        // ==================== 1. STAFF CRUD ====================
        
        // Strict COE Staff Read-Only Guard on ERP Master tables
        $erpMasterActions = ["staff_create", "staff_update", "staff_delete", "dept_create", "dept_update", "dept_delete", "course_create", "course_update", "course_delete", "alloc_create", "alloc_delete"];
        if (in_array($action, $erpMasterActions, true) && !canEditERPMasters()) {
            throw new RuntimeException("Permission Denied: COE Staff has Read-Only access to ERP Master tables (Staff, Departments, Courses, Timetable Allocations). Modifications are restricted to Super Admin / Central ERP Sync.");
        }

        if ($action === 'staff_create') {
            $code = strtoupper(trim($_POST['staff_code'] ?? ''));
            $name = trim($_POST['first_name'] ?? '');
            $dept = trim($_POST['department'] ?? '');
            $deptCode = strtoupper(trim($_POST['dept_code1'] ?? $dept));
            $desig = trim($_POST['designation'] ?? 'Assistant Professor');
            $mobile = trim($_POST['mobile_no'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $pwd = trim($_POST['staff_password'] ?? 'hcc123');
            $status = ($_POST['status'] ?? 'Y') === 'Y' ? 'Y' : 'N';
            $hod = ($_POST['hod_status'] ?? 'N') === 'Y' ? 'Y' : 'N';

            if (!$code || !$name) throw new RuntimeException('Staff Code and Name are required.');

            $st = $pdo->prepare("INSERT INTO pr_x_xxxx_staf_prof_mast (STAFF_CODE, FIRST_NAME, DEPARTMENT, dept_code1, designation, MOBILE_NO, email, STAFF_PASSWORD, status, hod_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->execute([$code, $name, $dept, $deptCode, $desig, $mobile, $email, $pwd, $status, $hod]);
            qps_audit($pdo, 'STAFF_CREATE', 'STAFF', $code, ['name' => $name, 'dept' => $dept]);
            $msg = "Staff member [$code] $name created successfully.";
            $tab = 'staff';
        }
        elseif ($action === 'staff_update') {
            $origCode = trim($_POST['orig_staff_code'] ?? '');
            $name = trim($_POST['first_name'] ?? '');
            $dept = trim($_POST['department'] ?? '');
            $deptCode = strtoupper(trim($_POST['dept_code1'] ?? $dept));
            $desig = trim($_POST['designation'] ?? '');
            $mobile = trim($_POST['mobile_no'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $pwd = trim($_POST['staff_password'] ?? '');
            $status = ($_POST['status'] ?? 'Y') === 'Y' ? 'Y' : 'N';
            $hod = ($_POST['hod_status'] ?? 'N') === 'Y' ? 'Y' : 'N';

            if (!$origCode || !$name) throw new RuntimeException('Staff Code and Name are required.');

            if (!empty($pwd)) {
                $st = $pdo->prepare("UPDATE pr_x_xxxx_staf_prof_mast SET FIRST_NAME=?, DEPARTMENT=?, dept_code1=?, designation=?, MOBILE_NO=?, email=?, STAFF_PASSWORD=?, status=?, hod_status=? WHERE STAFF_CODE=?");
                $st->execute([$name, $dept, $deptCode, $desig, $mobile, $email, $pwd, $status, $hod, $origCode]);
            } else {
                $st = $pdo->prepare("UPDATE pr_x_xxxx_staf_prof_mast SET FIRST_NAME=?, DEPARTMENT=?, dept_code1=?, designation=?, MOBILE_NO=?, email=?, status=?, hod_status=? WHERE STAFF_CODE=?");
                $st->execute([$name, $dept, $deptCode, $desig, $mobile, $email, $status, $hod, $origCode]);
            }
            qps_audit($pdo, 'STAFF_UPDATE', 'STAFF', $origCode);
            $msg = "Staff [$origCode] updated successfully.";
            $tab = 'staff';
        }
        elseif ($action === 'staff_delete') {
            $code = trim($_POST['staff_code'] ?? '');
            if (!$code) throw new RuntimeException('Staff code required.');
            $pdo->prepare("DELETE FROM pr_x_xxxx_staf_prof_mast WHERE STAFF_CODE = ?")->execute([$code]);
            qps_audit($pdo, 'STAFF_DELETE', 'STAFF', $code);
            $msg = "Staff [$code] removed.";
            $tab = 'staff';
        }

        // ==================== 2. DEPARTMENT CRUD ====================
        elseif ($action === 'dept_create') {
            $code = strtoupper(trim($_POST['code'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $active = isset($_POST['is_active']) ? '1' : '0';
            if (!$code || !$name) throw new RuntimeException('Department Code and Name are required.');

            $st = $pdo->prepare("INSERT INTO departments (code, name, is_active, created_at) VALUES (?, ?, ?, ?)");
            $st->execute([$code, $name, $active, $now]);
            qps_audit($pdo, 'DEPT_CREATE', 'DEPARTMENT', $code);
            $msg = "Department [$code] created.";
            $tab = 'departments';
        }
        elseif ($action === 'dept_update') {
            $origCode = trim($_POST['orig_code'] ?? '');
            $code = strtoupper(trim($_POST['code'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $active = isset($_POST['is_active']) ? '1' : '0';
            if (!$code || !$name) throw new RuntimeException('Code and Name required.');

            $st = $pdo->prepare("UPDATE departments SET code=?, name=?, is_active=? WHERE code=?");
            $st->execute([$code, $name, $active, $origCode]);
            qps_audit($pdo, 'DEPT_UPDATE', 'DEPARTMENT', $code);
            $msg = "Department [$code] updated.";
            $tab = 'departments';
        }
        elseif ($action === 'dept_delete') {
            $code = trim($_POST['code'] ?? '');
            $pdo->prepare("DELETE FROM departments WHERE code = ?")->execute([$code]);
            qps_audit($pdo, 'DEPT_DELETE', 'DEPARTMENT', $code);
            $msg = "Department [$code] deleted.";
            $tab = 'departments';
        }

        // ==================== 3. COURSE CRUD ====================
        elseif ($action === 'course_create') {
            $code = strtoupper(trim($_POST['coursecode'] ?? ''));
            $title = trim($_POST['coursetitle'] ?? '');
            $dept = strtoupper(trim($_POST['dept_code'] ?? ''));
            $level = strtoupper(trim($_POST['level'] ?? 'UG'));
            $credit = intval($_POST['credit'] ?? 3);
            $hrs = intval($_POST['hrs_week'] ?? 4);
            $maxMark = intval($_POST['maxmark'] ?? 75);
            $qPattern = trim($_POST['qpattern'] ?? 'OBE');
            $type = trim($_POST['type'] ?? 'CORE');

            if (!$code || !$title) throw new RuntimeException('Course Code and Title are required.');

            $st = $pdo->prepare("INSERT INTO courses (coursecode, coursetitle, dept_code, level, credit, hrs_week, maxmark, qpattern, type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->execute([$code, $title, $dept, $level, $credit, $hrs, $maxMark, $qPattern, $type, $now]);
            qps_audit($pdo, 'COURSE_CREATE', 'COURSE', $code);
            $msg = "Course [$code] $title added.";
            $tab = 'courses';
        }
        elseif ($action === 'course_update') {
            $origCode = trim($_POST['orig_coursecode'] ?? '');
            $code = strtoupper(trim($_POST['coursecode'] ?? ''));
            $title = trim($_POST['coursetitle'] ?? '');
            $dept = strtoupper(trim($_POST['dept_code'] ?? ''));
            $level = strtoupper(trim($_POST['level'] ?? 'UG'));
            $credit = intval($_POST['credit'] ?? 3);
            $hrs = intval($_POST['hrs_week'] ?? 4);
            $maxMark = intval($_POST['maxmark'] ?? 75);
            $qPattern = trim($_POST['qpattern'] ?? 'OBE');
            $type = trim($_POST['type'] ?? 'CORE');

            $st = $pdo->prepare("UPDATE courses SET coursecode=?, coursetitle=?, dept_code=?, level=?, credit=?, hrs_week=?, maxmark=?, qpattern=?, type=? WHERE coursecode=?");
            $st->execute([$code, $title, $dept, $level, $credit, $hrs, $maxMark, $qPattern, $type, $origCode]);
            qps_audit($pdo, 'COURSE_UPDATE', 'COURSE', $code);
            $msg = "Course [$code] updated.";
            $tab = 'courses';
        }
        elseif ($action === 'course_delete') {
            $code = trim($_POST['coursecode'] ?? '');
            $pdo->prepare("DELETE FROM courses WHERE coursecode = ?")->execute([$code]);
            qps_audit($pdo, 'COURSE_DELETE', 'COURSE', $code);
            $msg = "Course [$code] deleted.";
            $tab = 'courses';
        }

        // ==================== 4. TIMETABLE ALLOCATION CRUD ====================
        elseif ($action === 'alloc_create') {
            $fid = strtoupper(trim($_POST['fid'] ?? ''));
            $paperCode = strtoupper(trim($_POST['papercode'] ?? ''));
            $deptCode = strtoupper(trim($_POST['deptcode'] ?? ''));
            $acYear = trim($_POST['acyear'] ?? DEFAULT_ACADEMIC_YEAR);
            $degree = trim($_POST['degree'] ?? 'UG');
            $secc = trim($_POST['secc'] ?? 'A');

            if (!$fid || !$paperCode) throw new RuntimeException('Faculty ID and Course Code are required.');

            $st = $pdo->prepare("INSERT INTO timetablefaculty (fid, deptcode, papercode, acyear, degree, secc, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $st->execute([$fid, $deptCode, $paperCode, $acYear, $degree, $secc, $now]);
            qps_audit($pdo, 'ALLOCATION_CREATE', 'TIMETABLE', "$fid:$paperCode");
            $msg = "Assigned paper [$paperCode] to faculty [$fid].";
            $tab = 'allocations';
        }
        elseif ($action === 'alloc_delete') {
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM timetablefaculty WHERE id = ?")->execute([$id]);
            qps_audit($pdo, 'ALLOCATION_DELETE', 'TIMETABLE', (string)$id);
            $msg = "Allocation removed.";
            $tab = 'allocations';
        }

        // ==================== 5. QUESTION BANK & QUESTIONS CRUD ====================
        elseif ($action === 'bank_status') {
            $bankId = intval($_POST['bank_id'] ?? 0);
            $status = trim($_POST['status'] ?? 'Draft');
            $pdo->prepare("UPDATE question_banks SET status = ?, updated_at = ? WHERE id = ?")->execute([$status, $now, $bankId]);
            qps_audit($pdo, 'BANK_STATUS_CHANGE', 'QUESTION_BANK', (string)$bankId, ['new_status' => $status]);
            $msg = "Bank #$bankId status changed to $status.";
            $tab = 'banks';
        }
        elseif ($action === 'bank_delete') {
            $bankId = intval($_POST['bank_id'] ?? 0);
            qps_delete_bank_cascade($pdo, $bankId);
            qps_audit($pdo, 'BANK_DELETE', 'QUESTION_BANK', (string)$bankId);
            $msg = "Bank #$bankId and all its questions and generated papers were permanently removed.";
            $tab = 'banks';
        }
        elseif ($action === 'paper_delete') {
            $paperId = intval($_POST['paper_id'] ?? 0);
            qps_delete_paper_cascade($pdo, $paperId);
            qps_audit($pdo, 'PAPER_DELETE', 'GENERATED_PAPER', (string)$paperId);
            $msg = "Generated Examination Paper #$paperId was permanently deleted.";
            $tab = 'papers';
        }
        elseif ($action === 'question_update') {
            $qId = intval($_POST['q_id'] ?? 0);
            $bankId = intval($_POST['bank_id'] ?? 0);
            $text = trim($_POST['question_text'] ?? '');
            $marks = intval($_POST['marks'] ?? 2);
            $kLevel = strtoupper(trim($_POST['k_level'] ?? 'K1'));
            $coLevel = strtoupper(trim($_POST['co_level'] ?? 'CO1'));
            $unitNo = intval($_POST['unit_no'] ?? 1);
            $secType = trim($_POST['section_type'] ?? 'Part A');

            if (!$qId || !$text) throw new RuntimeException('Question text cannot be empty.');

            $pdo->prepare("UPDATE questions SET question_text = ?, marks = ?, k_level = ?, co_level = ?, unit_no = ?, section_type = ? WHERE id = ?")
                ->execute([$text, $marks, $kLevel, $coLevel, $unitNo, $secType, $qId]);

            // Sync bank total questions
            $stAll = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
            $stAll->execute([$bankId]);
            $allQ = $stAll->fetchAll();
            $pdo->prepare("UPDATE question_banks SET total_questions = ?, updated_at = ? WHERE id = ?")->execute([count($allQ), $now, $bankId]);

            qps_audit($pdo, 'QUESTION_EDIT', 'QUESTION', (string)$qId, ['bank_id' => $bankId]);
            $msg = "Question #$qId updated successfully.";
            $tab = 'banks';
        }

        // ==================== 6. SYSTEM SETTINGS & TRANSLATION ====================
        elseif ($action === 'system_settings') {
            $allowed = [
                'current_academic_year', 'default_regulation', 'ocr_languages', 'allow_staff_draft', 'staff_can_reopen_submitted',
                'default_exam_session', 'non_obe_max_marks', 'obe_max_marks', 'institution_name',
                'ta_sec_a_title', 'ta_sec_a_sub', 'ta_sec_a_marks',
                'ta_sec_b_title', 'ta_sec_b_sub', 'ta_sec_b_marks',
                'ta_sec_c_title', 'ta_sec_c_sub', 'ta_sec_c_marks',
                'ta_sec_d_title', 'ta_sec_d_sub', 'ta_sec_d_marks',
                'fr_sec_a_title', 'fr_sec_a_sub', 'fr_sec_a_marks',
                'fr_sec_b_title', 'fr_sec_b_sub', 'fr_sec_b_marks',
                'fr_sec_c_title', 'fr_sec_c_sub', 'fr_sec_c_marks',
                'fr_sec_d_title', 'fr_sec_d_sub', 'fr_sec_d_marks',
                'en_sec_a_title', 'en_sec_a_sub', 'en_sec_a_marks',
                'en_sec_b_title', 'en_sec_b_sub', 'en_sec_b_marks',
                'en_sec_c_title', 'en_sec_c_sub', 'en_sec_c_marks',
                'en_sec_d_title', 'en_sec_d_sub', 'en_sec_d_marks'
            ];
            foreach ($allowed as $k) {
                if (isset($_POST[$k])) {
                    qps_set_setting($pdo, $k, trim((string)$_POST[$k]), $user['staff_code'] ?? 'SUPER_ADMIN');
                }
            }
            qps_audit($pdo, 'SYSTEM_SETTINGS_UPDATE', 'SETTINGS', '');
            $msg = "System settings, default marks, and translation headers successfully updated.";
            $tab = 'settings';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Fetch stats
$stats = [
    'staff' => (int)$pdo->query("SELECT COUNT(*) FROM pr_x_xxxx_staf_prof_mast")->fetchColumn(),
    'departments' => (int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn(),
    'courses' => (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn(),
    'allocations' => (int)$pdo->query("SELECT COUNT(*) FROM timetablefaculty")->fetchColumn(),
    'banks' => (int)$pdo->query("SELECT COUNT(*) FROM question_banks")->fetchColumn(),
    'questions' => (int)$pdo->query("SELECT COUNT(*) FROM questions")->fetchColumn(),
    'blueprints' => (int)$pdo->query("SELECT COUNT(*) FROM blueprints")->fetchColumn(),
    'generated' => (int)$pdo->query("SELECT COUNT(*) FROM generated_papers")->fetchColumn()
];

// Pagination & Fetch Data for Current Tab
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$totalRows = 0;
$totalPages = 1;

$staffList = [];
if ($tab === 'staff' || $tab === 'overview') {
    if ($search) {
        $term = "%$search%";
        $stCnt = $pdo->prepare("SELECT COUNT(*) FROM pr_x_xxxx_staf_prof_mast s WHERE s.STAFF_CODE LIKE ? OR s.FIRST_NAME LIKE ? OR s.DEPARTMENT LIKE ?");
        $stCnt->execute([$term, $term, $term]);
        $totalRows = (int)$stCnt->fetchColumn();

        $qSql = "SELECT s.*, (SELECT COUNT(*) FROM timetablefaculty WHERE fid = s.STAFF_CODE) as paper_count FROM pr_x_xxxx_staf_prof_mast s WHERE s.STAFF_CODE LIKE ? OR s.FIRST_NAME LIKE ? OR s.DEPARTMENT LIKE ? ORDER BY s.FIRST_NAME ASC LIMIT " . ($tab === 'overview' ? 10 : $perPage) . " OFFSET " . ($tab === 'overview' ? 0 : $offset);
        $st = $pdo->prepare($qSql);
        $st->execute([$term, $term, $term]);
        $staffList = $st->fetchAll();
    } else {
        $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM pr_x_xxxx_staf_prof_mast")->fetchColumn();
        $qSql = "SELECT s.*, (SELECT COUNT(*) FROM timetablefaculty WHERE fid = s.STAFF_CODE) as paper_count FROM pr_x_xxxx_staf_prof_mast s ORDER BY s.FIRST_NAME ASC LIMIT " . ($tab === 'overview' ? 10 : $perPage) . " OFFSET " . ($tab === 'overview' ? 0 : $offset);
        $staffList = $pdo->query($qSql)->fetchAll();
    }
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
}

$deptList = [];
if ($tab === 'departments' || $tab === 'overview' || $tab === 'staff' || $tab === 'courses' || $tab === 'allocations') {
    if ($tab === 'departments') {
        $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $deptList = $pdo->query("SELECT d.*, (SELECT COUNT(*) FROM courses WHERE dept_code = d.code) as course_count, (SELECT COUNT(*) FROM pr_x_xxxx_staf_prof_mast WHERE dept_code1 = d.code OR DEPARTMENT = d.name) as staff_count FROM departments d ORDER BY d.name ASC LIMIT $perPage OFFSET $offset")->fetchAll();
    } else {
        $deptList = $pdo->query("SELECT d.*, (SELECT COUNT(*) FROM courses WHERE dept_code = d.code) as course_count, (SELECT COUNT(*) FROM pr_x_xxxx_staf_prof_mast WHERE dept_code1 = d.code OR DEPARTMENT = d.name) as staff_count FROM departments d ORDER BY d.name ASC")->fetchAll();
    }
}

$courseList = [];
if ($tab === 'courses' || $tab === 'allocations') {
    if ($tab === 'courses') {
        if ($search) {
            $term = "%$search%";
            $stCnt = $pdo->prepare("SELECT COUNT(*) FROM courses c WHERE c.coursecode LIKE ? OR c.coursetitle LIKE ? OR c.dept_code LIKE ?");
            $stCnt->execute([$term, $term, $term]);
            $totalRows = (int)$stCnt->fetchColumn();

            $cSql = "SELECT c.*, d.name as dept_name FROM courses c LEFT JOIN departments d ON d.code = c.dept_code WHERE c.coursecode LIKE ? OR c.coursetitle LIKE ? OR c.dept_code LIKE ? ORDER BY c.coursecode ASC LIMIT $perPage OFFSET $offset";
            $st = $pdo->prepare($cSql);
            $st->execute([$term, $term, $term]);
            $courseList = $st->fetchAll();
        } else {
            $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
            $cSql = "SELECT c.*, d.name as dept_name FROM courses c LEFT JOIN departments d ON d.code = c.dept_code ORDER BY c.coursecode ASC LIMIT $perPage OFFSET $offset";
            $courseList = $pdo->query($cSql)->fetchAll();
        }
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
    } else {
        $courseList = $pdo->query("SELECT c.*, d.name as dept_name FROM courses c LEFT JOIN departments d ON d.code = c.dept_code ORDER BY c.coursecode ASC")->fetchAll();
    }
}

$allocList = [];
if ($tab === 'allocations') {
    if ($search) {
        $term = "%$search%";
        $stCnt = $pdo->prepare("SELECT COUNT(*) FROM timetablefaculty tf LEFT JOIN pr_x_xxxx_staf_prof_mast s ON s.STAFF_CODE = tf.fid WHERE tf.fid LIKE ? OR tf.papercode LIKE ? OR s.FIRST_NAME LIKE ?");
        $stCnt->execute([$term, $term, $term]);
        $totalRows = (int)$stCnt->fetchColumn();

        $aSql = "SELECT tf.*, s.FIRST_NAME as faculty_name, c.coursetitle FROM timetablefaculty tf LEFT JOIN pr_x_xxxx_staf_prof_mast s ON s.STAFF_CODE = tf.fid LEFT JOIN courses c ON c.coursecode = tf.papercode WHERE tf.fid LIKE ? OR tf.papercode LIKE ? OR s.FIRST_NAME LIKE ? ORDER BY tf.id DESC LIMIT $perPage OFFSET $offset";
        $st = $pdo->prepare($aSql);
        $st->execute([$term, $term, $term]);
        $allocList = $st->fetchAll();
    } else {
        $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM timetablefaculty")->fetchColumn();
        $aSql = "SELECT tf.*, s.FIRST_NAME as faculty_name, c.coursetitle FROM timetablefaculty tf LEFT JOIN pr_x_xxxx_staf_prof_mast s ON s.STAFF_CODE = tf.fid LEFT JOIN courses c ON c.coursecode = tf.papercode ORDER BY tf.id DESC LIMIT $perPage OFFSET $offset";
        $allocList = $pdo->query($aSql)->fetchAll();
    }
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
}

$bankList = [];
if ($tab === 'banks') {
    if ($search) {
        $term = "%$search%";
        $stCnt = $pdo->prepare("SELECT COUNT(*) FROM question_banks qb LEFT JOIN pr_x_xxxx_staf_prof_mast s ON s.STAFF_CODE = qb.staff_code WHERE qb.paper_code LIKE ? OR qb.course_title LIKE ? OR qb.staff_code LIKE ? OR s.FIRST_NAME LIKE ?");
        $stCnt->execute([$term, $term, $term, $term]);
        $totalRows = (int)$stCnt->fetchColumn();

        $bSql = "SELECT qb.*, s.FIRST_NAME as staff_name FROM question_banks qb LEFT JOIN pr_x_xxxx_staf_prof_mast s ON s.STAFF_CODE = qb.staff_code WHERE qb.paper_code LIKE ? OR qb.course_title LIKE ? OR qb.staff_code LIKE ? OR s.FIRST_NAME LIKE ? ORDER BY qb.id DESC LIMIT $perPage OFFSET $offset";
        $st = $pdo->prepare($bSql);
        $st->execute([$term, $term, $term, $term]);
        $bankList = $st->fetchAll();
    } else {
        $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM question_banks")->fetchColumn();
        $bSql = "SELECT qb.*, s.FIRST_NAME as staff_name FROM question_banks qb LEFT JOIN pr_x_xxxx_staf_prof_mast s ON s.STAFF_CODE = qb.staff_code ORDER BY qb.id DESC LIMIT $perPage OFFSET $offset";
        $bankList = $pdo->query($bSql)->fetchAll();
    }
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
}

// Active questions to inspect if a bank is selected
$viewBankId = intval($_GET['view_bank'] ?? 0);
$activeQuestions = [];
$activeBank = null;
if ($viewBankId) {
    $st = $pdo->prepare("SELECT * FROM question_banks WHERE id = ?");
    $st->execute([$viewBankId]);
    $activeBank = $st->fetch();
    if ($activeBank) {
        $stQ = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
        $stQ->execute([$viewBankId]);
        $activeQuestions = $stQ->fetchAll();
    }
}

$settings = [];
$allSettingKeys = [
    'current_academic_year', 'default_regulation', 'ocr_languages', 'allow_staff_draft', 'staff_can_reopen_submitted',
    'default_exam_session', 'non_obe_max_marks', 'obe_max_marks', 'institution_name',
    'ta_sec_a_title', 'ta_sec_a_sub', 'ta_sec_a_marks',
    'ta_sec_b_title', 'ta_sec_b_sub', 'ta_sec_b_marks',
    'ta_sec_c_title', 'ta_sec_c_sub', 'ta_sec_c_marks',
    'ta_sec_d_title', 'ta_sec_d_sub', 'ta_sec_d_marks',
    'fr_sec_a_title', 'fr_sec_a_sub', 'fr_sec_a_marks',
    'fr_sec_b_title', 'fr_sec_b_sub', 'fr_sec_b_marks',
    'fr_sec_c_title', 'fr_sec_c_sub', 'fr_sec_c_marks',
    'fr_sec_d_title', 'fr_sec_d_sub', 'fr_sec_d_marks',
    'en_sec_a_title', 'en_sec_a_sub', 'en_sec_a_marks',
    'en_sec_b_title', 'en_sec_b_sub', 'en_sec_b_marks',
    'en_sec_c_title', 'en_sec_c_sub', 'en_sec_c_marks',
    'en_sec_d_title', 'en_sec_d_sub', 'en_sec_d_marks'
];
foreach ($allSettingKeys as $k) {
    $settings[$k] = qps_setting($pdo, $k, '');
}

$paperList = [];
if ($tab === 'papers' || $tab === 'overview') {
    if ($search) {
        $term = "%$search%";
        $stCnt = $pdo->prepare("SELECT COUNT(*) FROM generated_papers WHERE paper_code LIKE ? OR course_title LIKE ? OR dept_name LIKE ?");
        $stCnt->execute([$term, $term, $term]);
        $totalRows = (int)$stCnt->fetchColumn();

        $pSql = "SELECT * FROM generated_papers WHERE paper_code LIKE ? OR course_title LIKE ? OR dept_name LIKE ? ORDER BY id DESC LIMIT $perPage OFFSET $offset";
        $st = $pdo->prepare($pSql);
        $st->execute([$term, $term, $term]);
        $paperList = $st->fetchAll();
    } else {
        $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM generated_papers")->fetchColumn();
        $pSql = "SELECT * FROM generated_papers ORDER BY id DESC LIMIT $perPage OFFSET $offset";
        $paperList = $pdo->query($pSql)->fetchAll();
    }
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
}

$auditLogs = [];
if ($tab === 'settings' || $tab === 'overview') {
    $auditLogs = $pdo->query("SELECT * FROM qps_audit_log ORDER BY id DESC LIMIT 25")->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Header Banner -->
  <div class="bg-gradient-to-r from-slate-950 via-indigo-950 to-blue-900 rounded-2xl p-6 text-white shadow-xl border border-indigo-900/50">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <div class="flex items-center space-x-2">
          <span class="text-[11px] bg-amber-400 text-slate-950 rounded-full px-3 py-0.5 font-bold uppercase tracking-wider">
            <?php echo $isSuperAdmin ? 'SUPER ADMIN ACCESS' : 'COE READ-ONLY ACCESS'; ?>
          </span>
          <span class="text-xs text-indigo-200">Holy Cross College (Autonomous)</span>
        </div>
        <h2 class="text-2xl font-extrabold mt-2">Database & Examination Operations Suite</h2>
        <p class="text-indigo-200 text-xs mt-1 max-w-2xl">
          Complete management for question bank submissions, blueprints, settings, and ERP directories.
        </p>
      </div>

      <div class="flex items-center gap-2">
        <a href="<?php echo getBaseUrl(); ?>/api/export_sql.php" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl shadow flex items-center space-x-1.5 transition">
          <i data-lucide="download" class="w-4 h-4"></i>
          <span>Download SQL Backup</span>
        </a>
        <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php" class="bg-amber-500 hover:bg-amber-600 text-slate-950 text-xs font-bold px-4 py-2.5 rounded-xl shadow flex items-center space-x-1.5 transition">
          <i data-lucide="shuffle" class="w-4 h-4"></i>
          <span>Go to Shuffler</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Messages -->
  <?php if ($msg): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl p-3.5 text-xs font-semibold flex items-center space-x-2 shadow-sm">
      <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600"></i>
      <span><?php echo htmlspecialchars($msg); ?></span>
    </div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-xl p-3.5 text-xs font-semibold flex items-center space-x-2 shadow-sm">
      <i data-lucide="alert-triangle" class="w-4 h-4 text-rose-600"></i>
      <span><?php echo htmlspecialchars($error); ?></span>
    </div>
  <?php endif; ?>

  <!-- Navigation Tabs -->
  <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-2 flex overflow-x-auto space-x-1 no-scrollbar text-xs font-bold">
    <a href="?tab=overview" class="<?php echo $tab === 'overview' ? 'bg-indigo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'; ?> px-4 py-2 rounded-xl flex items-center space-x-1.5 whitespace-nowrap transition">
      <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
      <span>Overview (<?php echo array_sum($stats); ?>)</span>
    </a>
    <a href="?tab=staff" class="<?php echo $tab === 'staff' ? 'bg-indigo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'; ?> px-4 py-2 rounded-xl flex items-center space-x-1.5 whitespace-nowrap transition">
      <i data-lucide="users" class="w-4 h-4"></i>
      <span>Staff Master (<?php echo $stats['staff']; ?>)</span>
    </a>
    <a href="?tab=departments" class="<?php echo $tab === 'departments' ? 'bg-indigo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'; ?> px-4 py-2 rounded-xl flex items-center space-x-1.5 whitespace-nowrap transition">
      <i data-lucide="building-2" class="w-4 h-4"></i>
      <span>Departments (<?php echo $stats['departments']; ?>)</span>
    </a>
    <a href="?tab=courses" class="<?php echo $tab === 'courses' ? 'bg-indigo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'; ?> px-4 py-2 rounded-xl flex items-center space-x-1.5 whitespace-nowrap transition">
      <i data-lucide="book" class="w-4 h-4"></i>
      <span>Courses (<?php echo $stats['courses']; ?>)</span>
    </a>
    <a href="?tab=allocations" class="<?php echo $tab === 'allocations' ? 'bg-indigo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'; ?> px-4 py-2 rounded-xl flex items-center space-x-1.5 whitespace-nowrap transition">
      <i data-lucide="calendar-check" class="w-4 h-4"></i>
      <span>Timetable Allocations (<?php echo $stats['allocations']; ?>)</span>
    </a>
    <a href="?tab=banks" class="<?php echo $tab === 'banks' ? 'bg-indigo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'; ?> px-4 py-2 rounded-xl flex items-center space-x-1.5 whitespace-nowrap transition">
      <i data-lucide="database" class="w-4 h-4"></i>
      <span>Question Banks & Questions (<?php echo $stats['banks']; ?>)</span>
    </a>
    <a href="?tab=papers" class="<?php echo $tab === 'papers' ? 'bg-indigo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'; ?> px-4 py-2 rounded-xl flex items-center space-x-1.5 whitespace-nowrap transition">
      <i data-lucide="file-check-2" class="w-4 h-4 text-amber-400"></i>
      <span>Generated Papers (<?php echo $stats['generated']; ?>)</span>
    </a>
    <a href="?tab=settings" class="<?php echo $tab === 'settings' ? 'bg-indigo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'; ?> px-4 py-2 rounded-xl flex items-center space-x-1.5 whitespace-nowrap transition">
      <i data-lucide="sliders" class="w-4 h-4"></i>
      <span>Settings & Translation</span>
    </a>
  </div>

  <!-- ==================== TAB 1: OVERVIEW ==================== -->
  <?php if ($tab === 'overview'): ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
      <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
        <div class="flex items-center justify-between text-indigo-600"><span class="text-xs font-bold text-slate-500">Total Faculty</span><i data-lucide="users" class="w-5 h-5"></i></div>
        <div class="text-2xl font-black text-slate-900 mt-2"><?php echo $stats['staff']; ?></div>
        <a href="?tab=staff" class="text-[11px] text-indigo-600 hover:underline font-bold mt-1 inline-block">View Staff Master &rarr;</a>
      </div>
      <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
        <div class="flex items-center justify-between text-teal-600"><span class="text-xs font-bold text-slate-500">Departments</span><i data-lucide="building" class="w-5 h-5"></i></div>
        <div class="text-2xl font-black text-slate-900 mt-2"><?php echo $stats['departments']; ?></div>
        <a href="?tab=departments" class="text-[11px] text-teal-600 hover:underline font-bold mt-1 inline-block">View Departments &rarr;</a>
      </div>
      <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
        <div class="flex items-center justify-between text-blue-600"><span class="text-xs font-bold text-slate-500">Active Courses</span><i data-lucide="book-open" class="w-5 h-5"></i></div>
        <div class="text-2xl font-black text-slate-900 mt-2"><?php echo $stats['courses']; ?></div>
        <a href="?tab=courses" class="text-[11px] text-blue-600 hover:underline font-bold mt-1 inline-block">View Courses &rarr;</a>
      </div>
      <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
        <div class="flex items-center justify-between text-amber-600"><span class="text-xs font-bold text-slate-500">Allocations</span><i data-lucide="calendar" class="w-5 h-5"></i></div>
        <div class="text-2xl font-black text-slate-900 mt-2"><?php echo $stats['allocations']; ?></div>
        <a href="?tab=allocations" class="text-[11px] text-amber-600 hover:underline font-bold mt-1 inline-block">View Allocations &rarr;</a>
      </div>
      <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
        <div class="flex items-center justify-between text-rose-600"><span class="text-xs font-bold text-slate-500">Question Banks</span><i data-lucide="database" class="w-5 h-5"></i></div>
        <div class="text-2xl font-black text-slate-900 mt-2"><?php echo $stats['banks']; ?></div>
        <a href="?tab=banks" class="text-[11px] text-rose-600 hover:underline font-bold mt-1 inline-block">Manage Banks &rarr;</a>
      </div>
      <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
        <div class="flex items-center justify-between text-purple-600"><span class="text-xs font-bold text-slate-500">Total Questions</span><i data-lucide="help-circle" class="w-5 h-5"></i></div>
        <div class="text-2xl font-black text-slate-900 mt-2"><?php echo $stats['questions']; ?></div>
        <span class="text-[11px] text-slate-400 font-semibold mt-1 inline-block">Relational Pool</span>
      </div>
      <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
        <div class="flex items-center justify-between text-emerald-600"><span class="text-xs font-bold text-slate-500">OBE Blueprints</span><i data-lucide="layout-template" class="w-5 h-5"></i></div>
        <div class="text-2xl font-black text-slate-900 mt-2"><?php echo $stats['blueprints']; ?></div>
        <a href="<?php echo getBaseUrl(); ?>/modules/coe/blueprint.php" class="text-[11px] text-emerald-600 hover:underline font-bold mt-1 inline-block">Configure Rules &rarr;</a>
      </div>
      <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
        <div class="flex items-center justify-between text-orange-600"><span class="text-xs font-bold text-slate-500">Generated Papers</span><i data-lucide="file-check" class="w-5 h-5"></i></div>
        <div class="text-2xl font-black text-slate-900 mt-2"><?php echo $stats['generated']; ?></div>
        <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php" class="text-[11px] text-orange-600 hover:underline font-bold mt-1 inline-block">Shuffle Papers &rarr;</a>
      </div>
    </div>

    <!-- Quick Shortcuts & System Status -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 lg:col-span-2">
        <h3 class="font-bold text-slate-900 text-sm mb-3 flex items-center space-x-2">
          <i data-lucide="activity" class="w-4 h-4 text-indigo-600"></i>
          <span>Recent System Audit Trail</span>
        </h3>
        <div class="overflow-x-auto text-xs">
          <table class="w-full text-left">
            <thead>
              <tr class="bg-slate-50 text-slate-500 border-b border-slate-200">
                <th class="p-2">Timestamp</th>
                <th class="p-2">User / Role</th>
                <th class="p-2">Action</th>
                <th class="p-2">Entity</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach (array_slice($auditLogs, 0, 8) as $log): ?>
                <tr>
                  <td class="p-2 text-slate-500 font-mono"><?php echo $log['created_at']; ?></td>
                  <td class="p-2 font-bold text-slate-800"><?php echo htmlspecialchars($log['actor'] ?? $log['user_code'] ?? 'SYSTEM'); ?></td>
                  <td class="p-2"><span class="bg-indigo-50 text-indigo-700 font-mono text-[10px] px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($log['action'] ?? $log['action_name'] ?? ''); ?></span></td>
                  <td class="p-2 text-slate-600"><?php echo htmlspecialchars(($log['entity_type'] ?? '') . ' #' . ($log['entity_id'] ?? '')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 space-y-4">
        <h3 class="font-bold text-slate-900 text-sm flex items-center space-x-2">
          <i data-lucide="zap" class="w-4 h-4 text-amber-500"></i>
          <span>Quick Administrative Tools</span>
        </h3>
        <div class="space-y-2 text-xs">
          <a href="<?php echo getBaseUrl(); ?>/modules/coe/blueprint.php" class="w-full bg-slate-50 hover:bg-amber-50 border border-slate-200 p-3 rounded-xl flex items-center justify-between font-bold text-slate-800 transition">
            <span>Configure OBE Blueprints (75M / 100M)</span>
            <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400"></i>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php" class="w-full bg-slate-50 hover:bg-emerald-50 border border-slate-200 p-3 rounded-xl flex items-center justify-between font-bold text-slate-800 transition">
            <span>Intelligent Multi-Set Shuffler</span>
            <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400"></i>
          </a>
          <a href="<?php echo getBaseUrl(); ?>/api/export_sql.php" class="w-full bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 p-3 rounded-xl flex items-center justify-between font-bold text-emerald-900 transition">
            <span>Backup Full Database (SQL)</span>
            <i data-lucide="download" class="w-4 h-4 text-emerald-600"></i>
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- ==================== TAB 2: STAFF CRUD / READ-ONLY ==================== -->
  <?php if ($tab === 'staff'): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div class="flex items-center space-x-2">
            <h3 class="font-extrabold text-slate-900 text-lg">Staff Profile Master (pr_x_xxxx_staf_prof_mast)</h3>
            <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded border border-slate-200">
              <?php echo $isSuperAdmin ? 'Full Access' : 'Read-Only (ERP Managed)'; ?>
            </span>
          </div>
          <p class="text-xs text-slate-500">View teaching faculty, staff codes, and department allocations across Holy Cross College.</p>
        </div>
        <div class="flex items-center gap-2">
          <form method="GET" class="flex items-center">
            <input type="hidden" name="tab" value="staff">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name/code..." class="border border-slate-300 rounded-l-xl px-3 py-1.5 text-xs focus:ring-1 focus:ring-indigo-500">
            <button type="submit" class="bg-slate-800 text-white px-3 py-1.5 rounded-r-xl text-xs font-bold">Search</button>
          </form>
          <?php if ($canEditERP): ?>
            <button onclick="document.getElementById('modal-create-staff').classList.remove('hidden')" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3.5 py-2 rounded-xl flex items-center space-x-1 shadow">
              <i data-lucide="plus" class="w-4 h-4"></i>
              <span>Add Staff</span>
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Staff Table -->
      <div class="overflow-x-auto text-xs">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-100 text-slate-700 border-b border-slate-200 font-bold">
              <th class="p-3">Staff Code</th>
              <th class="p-3">Full Name</th>
              <th class="p-3">Department</th>
              <th class="p-3">Designation</th>
              <th class="p-3">Contact</th>
              <th class="p-3 text-center">Allocations</th>
              <th class="p-3 text-center">Status</th>
              <?php if ($isSuperAdmin): ?><th class="p-3 text-right">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($staffList as $s): ?>
              <tr class="hover:bg-slate-50/80">
                <td class="p-3 font-mono font-bold text-indigo-700"><?php echo htmlspecialchars($s['STAFF_CODE']); ?></td>
                <td class="p-3 font-bold text-slate-900"><?php echo htmlspecialchars($s['FIRST_NAME']); ?></td>
                <td class="p-3 text-slate-700"><?php echo htmlspecialchars($s['DEPARTMENT'] ?: $s['dept_code1']); ?></td>
                <td class="p-3 text-slate-600"><?php echo htmlspecialchars($s['designation'] ?? 'Assistant Professor'); ?></td>
                <td class="p-3 text-slate-500">
                  <div><?php echo htmlspecialchars($s['email'] ?? ''); ?></div>
                  <div class="text-[10px]"><?php echo htmlspecialchars($s['MOBILE_NO'] ?? ''); ?></div>
                </td>
                <td class="p-3 text-center">
                  <span class="bg-indigo-50 text-indigo-700 font-bold px-2 py-0.5 rounded-full text-[10px]"><?php echo $s['paper_count']; ?> Courses</span>
                </td>
                <td class="p-3 text-center">
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $s['status'] === 'Y' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'; ?>">
                    <?php echo $s['status'] === 'Y' ? 'ACTIVE' : 'INACTIVE'; ?>
                  </span>
                </td>
                <?php if ($isSuperAdmin): ?>
                  <td class="p-3 text-right">
                    <div class="flex items-center justify-end space-x-1.5">
                      <button onclick='openEditStaff(<?php echo json_encode($s); ?>)' class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2 py-1 rounded text-[11px] font-bold">Edit</button>
                      <form method="POST" onsubmit="return confirm('Delete staff member <?php echo htmlspecialchars($s['STAFF_CODE']); ?>?');" class="inline">
                        <input type="hidden" name="action" value="staff_delete">
                        <input type="hidden" name="staff_code" value="<?php echo htmlspecialchars($s['STAFF_CODE']); ?>">
                        <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-700 px-2 py-1 rounded text-[11px] font-bold">Delete</button>
                      </form>
                    </div>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPages > 1): ?>
        <?php echo render_pagination($page, $totalPages, $totalRows, $perPage, $_GET); ?>
      <?php endif; ?>
    </div>

    <?php if ($isSuperAdmin): ?>
      <!-- Create Staff Modal -->
      <div id="modal-create-staff" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h4 class="font-extrabold text-slate-900 text-base">Add New Staff Member</h4>
            <button onclick="document.getElementById('modal-create-staff').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">&times;</button>
          </div>
          <form method="POST" class="mt-4 space-y-3 text-xs">
            <input type="hidden" name="action" value="staff_create">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="font-bold text-slate-700">Staff Code *</label>
                <input type="text" name="staff_code" required placeholder="e.g. SF0199" class="w-full mt-1 border rounded-xl p-2 font-mono uppercase">
              </div>
              <div>
                <label class="font-bold text-slate-700">Full Name *</label>
                <input type="text" name="first_name" required placeholder="Dr. / Prof. Name" class="w-full mt-1 border rounded-xl p-2">
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="font-bold text-slate-700">Department Name</label>
                <select name="department" class="w-full mt-1 border rounded-xl p-2">
                  <?php foreach ($deptList as $d): ?>
                    <option value="<?php echo htmlspecialchars($d['name']); ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="font-bold text-slate-700">Department Code</label>
                <select name="dept_code1" class="w-full mt-1 border rounded-xl p-2 font-mono">
                  <?php foreach ($deptList as $d): ?>
                    <option value="<?php echo htmlspecialchars($d['code']); ?>"><?php echo htmlspecialchars($d['code']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="font-bold text-slate-700">Designation</label>
                <input type="text" name="designation" value="Assistant Professor" class="w-full mt-1 border rounded-xl p-2">
              </div>
              <div>
                <label class="font-bold text-slate-700">Login Password</label>
                <input type="password" name="staff_password" value="hcc123" class="w-full mt-1 border rounded-xl p-2">
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="font-bold text-slate-700">Email Address</label>
                <input type="email" name="email" placeholder="staff@holycross.edu" class="w-full mt-1 border rounded-xl p-2">
              </div>
              <div>
                <label class="font-bold text-slate-700">Mobile No</label>
                <input type="text" name="mobile_no" placeholder="9876543210" class="w-full mt-1 border rounded-xl p-2">
              </div>
            </div>
            <div class="flex items-center space-x-4 pt-2">
              <label class="flex items-center space-x-1.5 font-bold">
                <input type="checkbox" name="status" value="Y" checked class="rounded">
                <span>Active Staff</span>
              </label>
              <label class="flex items-center space-x-1.5 font-bold">
                <input type="checkbox" name="hod_status" value="Y" class="rounded">
                <span>Head of Department (HOD)</span>
              </label>
            </div>
            <div class="flex justify-end space-x-2 pt-4 border-t border-slate-100">
              <button type="button" onclick="document.getElementById('modal-create-staff').classList.add('hidden')" class="bg-slate-100 text-slate-600 px-4 py-2 rounded-xl font-bold">Cancel</button>
              <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-xl font-bold">Save Staff</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Edit Staff Modal -->
      <div id="modal-edit-staff" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h4 class="font-extrabold text-slate-900 text-base">Edit Staff Member</h4>
            <button onclick="document.getElementById('modal-edit-staff').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">&times;</button>
          </div>
          <form method="POST" class="mt-4 space-y-3 text-xs">
            <input type="hidden" name="action" value="staff_update">
            <input type="hidden" name="orig_staff_code" id="edit-staff-orig-code">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="font-bold text-slate-700">Staff Code</label>
                <input type="text" id="edit-staff-code" disabled class="w-full mt-1 border rounded-xl p-2 bg-slate-100 font-mono font-bold">
              </div>
              <div>
                <label class="font-bold text-slate-700">Full Name *</label>
                <input type="text" name="first_name" id="edit-staff-name" required class="w-full mt-1 border rounded-xl p-2">
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="font-bold text-slate-700">Department</label>
                <input type="text" name="department" id="edit-staff-dept" class="w-full mt-1 border rounded-xl p-2">
              </div>
              <div>
                <label class="font-bold text-slate-700">Dept Code</label>
                <input type="text" name="dept_code1" id="edit-staff-deptcode" class="w-full mt-1 border rounded-xl p-2 font-mono uppercase">
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="font-bold text-slate-700">Designation</label>
                <input type="text" name="designation" id="edit-staff-desig" class="w-full mt-1 border rounded-xl p-2">
              </div>
              <div>
                <label class="font-bold text-slate-700">Reset Password (Optional)</label>
                <input type="password" name="staff_password" placeholder="Leave blank to keep current" class="w-full mt-1 border rounded-xl p-2">
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="font-bold text-slate-700">Email Address</label>
                <input type="email" name="email" id="edit-staff-email" class="w-full mt-1 border rounded-xl p-2">
              </div>
              <div>
                <label class="font-bold text-slate-700">Mobile No</label>
                <input type="text" name="mobile_no" id="edit-staff-mobile" class="w-full mt-1 border rounded-xl p-2">
              </div>
            </div>
            <div class="flex items-center space-x-4 pt-2">
              <label class="flex items-center space-x-1.5 font-bold">
                <input type="checkbox" name="status" id="edit-staff-status" value="Y" class="rounded">
                <span>Active</span>
              </label>
              <label class="flex items-center space-x-1.5 font-bold">
                <input type="checkbox" name="hod_status" id="edit-staff-hod" value="Y" class="rounded">
                <span>HOD Status</span>
              </label>
            </div>
            <div class="flex justify-end space-x-2 pt-4 border-t border-slate-100">
              <button type="button" onclick="document.getElementById('modal-edit-staff').classList.add('hidden')" class="bg-slate-100 text-slate-600 px-4 py-2 rounded-xl font-bold">Cancel</button>
              <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-xl font-bold">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- ==================== TAB 3: DEPARTMENTS ==================== -->
  <?php if ($tab === 'departments'): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div class="flex items-center space-x-2">
            <h3 class="font-extrabold text-slate-900 text-lg">Academic Departments (departments)</h3>
            <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded border border-slate-200">
              <?php echo $isSuperAdmin ? 'Full Access' : 'Read-Only (ERP Managed)'; ?>
            </span>
          </div>
          <p class="text-xs text-slate-500">View departments, schools, and course allocations.</p>
        </div>
        <?php if ($isSuperAdmin): ?>
          <button onclick="document.getElementById('modal-create-dept').classList.remove('hidden')" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3.5 py-2 rounded-xl flex items-center space-x-1 shadow self-start">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Add Department</span>
          </button>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($deptList as $d): ?>
          <div class="border border-slate-200 rounded-2xl p-4 hover:shadow-md transition bg-slate-50/50">
            <div class="flex items-start justify-between">
              <div>
                <span class="font-mono font-extrabold text-xs bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded"><?php echo htmlspecialchars($d['code']); ?></span>
                <h4 class="font-bold text-slate-900 text-sm mt-2"><?php echo htmlspecialchars($d['name']); ?></h4>
              </div>
              <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo ($d['is_active'] == '1' || $d['is_active'] === 1) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'; ?>">
                <?php echo ($d['is_active'] == '1' || $d['is_active'] === 1) ? 'ACTIVE' : 'INACTIVE'; ?>
              </span>
            </div>
            <div class="flex items-center gap-3 text-xs text-slate-500 mt-4 pt-3 border-t border-slate-200">
              <span><strong><?php echo $d['course_count']; ?></strong> Courses</span>
              <span>•</span>
              <span><strong><?php echo $d['staff_count']; ?></strong> Faculty</span>
            </div>
            <?php if ($isSuperAdmin): ?>
              <div class="flex justify-end space-x-2 mt-3">
                <button onclick='openEditDept(<?php echo json_encode($d); ?>)' class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 px-2.5 py-1 rounded text-xs font-bold">Edit</button>
                <form method="POST" onsubmit="return confirm('Delete department <?php echo htmlspecialchars($d['code']); ?>?');" class="inline">
                  <input type="hidden" name="action" value="dept_delete">
                  <input type="hidden" name="code" value="<?php echo htmlspecialchars($d['code']); ?>">
                  <button type="submit" class="bg-rose-50 text-rose-700 hover:bg-rose-100 px-2.5 py-1 rounded text-xs font-bold">Delete</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ==================== TAB 4: COURSES ==================== -->
  <?php if ($tab === 'courses'): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div class="flex items-center space-x-2">
            <h3 class="font-extrabold text-slate-900 text-lg">Degree Courses Directory (courses)</h3>
            <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded border border-slate-200">
              <?php echo $isSuperAdmin ? 'Full Access' : 'Read-Only (ERP Managed)'; ?>
            </span>
          </div>
          <p class="text-xs text-slate-500">View course codes, maximum marks, credits, and OBE patterns.</p>
        </div>
        <div class="flex items-center gap-2">
          <form method="GET" class="flex items-center">
            <input type="hidden" name="tab" value="courses">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search course code/title..." class="border border-slate-300 rounded-l-xl px-3 py-1.5 text-xs focus:ring-1 focus:ring-indigo-500">
            <button type="submit" class="bg-slate-800 text-white px-3 py-1.5 rounded-r-xl text-xs font-bold">Search</button>
          </form>
          <?php if ($canEditERP): ?>
            <button onclick="document.getElementById('modal-create-course').classList.remove('hidden')" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3.5 py-2 rounded-xl flex items-center space-x-1 shadow">
              <i data-lucide="plus" class="w-4 h-4"></i>
              <span>Add Course</span>
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Course Table -->
      <div class="overflow-x-auto text-xs">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-100 text-slate-700 border-b border-slate-200 font-bold">
              <th class="p-3">Course Code</th>
              <th class="p-3">Course Title</th>
              <th class="p-3">Department</th>
              <th class="p-3 text-center">Level</th>
              <th class="p-3 text-center">Credits</th>
              <th class="p-3 text-center">Max Marks</th>
              <th class="p-3 text-center">Pattern</th>
              <?php if ($isSuperAdmin): ?><th class="p-3 text-right">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($courseList as $c): ?>
              <tr class="hover:bg-slate-50/80">
                <td class="p-3 font-mono font-bold text-indigo-700"><?php echo htmlspecialchars($c['coursecode']); ?></td>
                <td class="p-3 font-bold text-slate-900"><?php echo htmlspecialchars($c['coursetitle']); ?></td>
                <td class="p-3 text-slate-700"><?php echo htmlspecialchars($c['dept_code']); ?></td>
                <td class="p-3 text-center">
                  <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700"><?php echo htmlspecialchars($c['level'] ?? 'UG'); ?></span>
                </td>
                <td class="p-3 text-center font-semibold"><?php echo htmlspecialchars($c['credit'] ?? 3); ?></td>
                <?php $examInfo = hcc_course_exam_info($c); ?>
                <td class="p-3 text-center font-bold text-amber-900"><?php echo htmlspecialchars($examInfo['marks_label']); ?></td>
                <td class="p-3 text-center">
                  <span class="px-2.5 py-1 rounded-full text-[10px] font-black border <?php echo $examInfo['badge_class']; ?>">
                    <?php echo htmlspecialchars($examInfo['type_label']); ?>
                  </span>
                </td>
                <?php if ($isSuperAdmin): ?>
                  <td class="p-3 text-right">
                    <div class="flex items-center justify-end space-x-1.5">
                      <button onclick='openEditCourse(<?php echo json_encode($c); ?>)' class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2 py-1 rounded text-[11px] font-bold">Edit</button>
                      <form method="POST" onsubmit="return confirm('Delete course <?php echo htmlspecialchars($c['coursecode']); ?>?');" class="inline">
                        <input type="hidden" name="action" value="course_delete">
                        <input type="hidden" name="coursecode" value="<?php echo htmlspecialchars($c['coursecode']); ?>">
                        <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-700 px-2 py-1 rounded text-[11px] font-bold">Delete</button>
                      </form>
                    </div>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($tab === 'courses' && $totalPages > 1): ?>
        <?php echo render_pagination($page, $totalPages, $totalRows, $perPage, $_GET); ?>
      <?php endif; ?>

      <?php if ($tab === 'departments' && $totalPages > 1): ?>
        <?php echo render_pagination($page, $totalPages, $totalRows, $perPage, $_GET); ?>
      <?php endif; ?>

    </div>
  <?php endif; ?>

  <!-- ==================== TAB 5: TIMETABLE ALLOCATIONS ==================== -->
  <?php if ($tab === 'allocations'): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div class="flex items-center space-x-2">
            <h3 class="font-extrabold text-slate-900 text-lg">Course & Faculty Timetable Allocations (timetablefaculty)</h3>
            <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded border border-slate-200">
              <?php echo $isSuperAdmin ? 'Full Access' : 'Read-Only (ERP Managed)'; ?>
            </span>
          </div>
          <p class="text-xs text-slate-500">View faculty paper allocations for question bank submission permissions.</p>
        </div>
        <?php if ($isSuperAdmin): ?>
          <button onclick="document.getElementById('modal-create-alloc').classList.remove('hidden')" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3.5 py-2 rounded-xl flex items-center space-x-1 shadow">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Assign Course to Faculty</span>
          </button>
        <?php endif; ?>
      </div>

      <!-- Allocations Table -->
      <div class="overflow-x-auto text-xs">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-100 text-slate-700 border-b border-slate-200 font-bold">
              <th class="p-3">ID</th>
              <th class="p-3">Faculty Member</th>
              <th class="p-3">Course Code & Title</th>
              <th class="p-3">Department</th>
              <th class="p-3 text-center">Academic Year</th>
              <th class="p-3 text-center">Degree / Sec</th>
              <?php if ($isSuperAdmin): ?><th class="p-3 text-right">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($allocList as $a): ?>
              <tr class="hover:bg-slate-50/80">
                <td class="p-3 font-mono text-slate-400">#<?php echo $a['id']; ?></td>
                <td class="p-3">
                  <div class="font-bold text-slate-900"><?php echo htmlspecialchars($a['faculty_name'] ?? $a['fid']); ?></div>
                  <div class="font-mono text-[10px] text-indigo-700"><?php echo htmlspecialchars($a['fid']); ?></div>
                </td>
                <td class="p-3">
                  <div class="font-bold font-mono text-slate-900"><?php echo htmlspecialchars($a['papercode']); ?></div>
                  <div class="text-[11px] text-slate-600"><?php echo htmlspecialchars($a['coursetitle'] ?? ''); ?></div>
                </td>
                <td class="p-3 font-semibold text-slate-700"><?php echo htmlspecialchars($a['deptcode']); ?></td>
                <td class="p-3 text-center font-mono"><?php echo htmlspecialchars($a['acyear'] ?? '2025-2026'); ?></td>
                <td class="p-3 text-center font-bold text-slate-700"><?php echo htmlspecialchars($a['degree'] ?? 'UG'); ?> - <?php echo htmlspecialchars($a['secc'] ?? 'A'); ?></td>
                <?php if ($isSuperAdmin): ?>
                  <td class="p-3 text-right">
                    <form method="POST" onsubmit="return confirm('Revoke allocation #<?php echo $a['id']; ?>?');" class="inline">
                      <input type="hidden" name="action" value="alloc_delete">
                      <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                      <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-700 px-2.5 py-1 rounded text-[11px] font-bold">Revoke</button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($tab === 'allocations' && $totalPages > 1): ?>
        <?php echo render_pagination($page, $totalPages, $totalRows, $perPage, $_GET); ?>
      <?php endif; ?>

    </div>
  <?php endif; ?>

  <!-- ==================== TAB 6: QUESTION BANKS & QUESTIONS CRUD ==================== -->
  <?php if ($tab === 'banks'): ?>
    <div class="space-y-6">
      
      <!-- Banks List Table -->
      <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h3 class="font-extrabold text-slate-900 text-lg">Question Banks & Questions Repository</h3>
            <p class="text-xs text-slate-500">Review faculty submissions, approve question banks, and edit individual questions.</p>
          </div>
          <form method="GET" class="flex items-center">
            <input type="hidden" name="tab" value="banks">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search bank / course / staff..." class="border border-slate-300 rounded-l-xl px-3 py-1.5 text-xs focus:ring-1 focus:ring-indigo-500">
            <button type="submit" class="bg-slate-800 text-white px-3 py-1.5 rounded-r-xl text-xs font-bold">Search</button>
          </form>
        </div>

        <div class="overflow-x-auto text-xs">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-slate-100 text-slate-700 border-b border-slate-200 font-bold">
                <th class="p-3">Bank ID</th>
                <th class="p-3">Course</th>
                <th class="p-3">Staff Member</th>
                <th class="p-3 text-center">Version</th>
                <th class="p-3 text-center">Total Qs</th>
                <th class="p-3 text-center">Status</th>
                <th class="p-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($bankList as $b): ?>
                <tr class="hover:bg-slate-50/80 <?php echo ($viewBankId === (int)$b['id']) ? 'bg-indigo-50/50' : ''; ?>">
                  <td class="p-3 font-mono font-bold text-indigo-700">#<?php echo $b['id']; ?></td>
                  <td class="p-3">
                    <div class="font-bold font-mono text-slate-900"><?php echo htmlspecialchars($b['paper_code']); ?></div>
                    <div class="text-[11px] text-slate-600"><?php echo htmlspecialchars($b['course_title']); ?></div>
                  </td>
                  <td class="p-3">
                    <div class="font-bold text-slate-800"><?php echo htmlspecialchars($b['staff_name'] ?? $b['staff_code']); ?></div>
                    <div class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($b['staff_code']); ?></div>
                  </td>
                  <td class="p-3 text-center">
                    <span class="bg-slate-100 text-slate-700 font-bold px-2 py-0.5 rounded text-[10px]">v<?php echo $b['version_no'] ?? 1; ?></span>
                  </td>
                  <td class="p-3 text-center font-bold text-indigo-900"><?php echo $b['total_questions']; ?></td>
                  <td class="p-3 text-center">
                    <form method="POST" class="inline">
                      <input type="hidden" name="action" value="bank_status">
                      <input type="hidden" name="bank_id" value="<?php echo $b['id']; ?>">
                      <select name="status" onchange="this.form.submit()" class="text-[10px] font-bold rounded-full px-2 py-0.5 border <?php 
                        if ($b['status'] === 'Approved') echo 'bg-emerald-100 text-emerald-800 border-emerald-300';
                        elseif ($b['status'] === 'Submitted') echo 'bg-amber-100 text-amber-800 border-amber-300';
                        else echo 'bg-slate-100 text-slate-700 border-slate-300';
                      ?>">
                        <option value="Draft" <?php echo $b['status'] === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="Submitted" <?php echo $b['status'] === 'Submitted' ? 'selected' : ''; ?>>Submitted</option>
                        <option value="Approved" <?php echo $b['status'] === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="Rejected" <?php echo $b['status'] === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                      </select>
                    </form>
                  </td>
                  <td class="p-3 text-right">
                    <div class="flex items-center justify-end space-x-1.5">
                      <a href="?tab=banks&view_bank=<?php echo $b['id']; ?>#bank-questions-section" class="bg-indigo-600 hover:bg-indigo-700 text-white px-2.5 py-1 rounded text-[11px] font-bold">
                        Inspect Questions (<?php echo $b['total_questions']; ?>)
                      </a>
                      <form method="POST" onsubmit="return confirm('Permanently delete Bank #<?php echo $b['id']; ?> and all its questions?');" class="inline">
                        <input type="hidden" name="action" value="bank_delete">
                        <input type="hidden" name="bank_id" value="<?php echo $b['id']; ?>">
                        <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-700 px-2 py-1 rounded text-[11px] font-bold">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php if ($tab === 'banks' && $totalPages > 1): ?>
        <?php echo render_pagination($page, $totalPages, $totalRows, $perPage, $_GET); ?>
      <?php endif; ?>

      </div>

      <!-- Individual Questions Viewer & Editor Drawer -->
      <?php if ($activeBank): ?>
        <div id="bank-questions-section" class="bg-white rounded-2xl shadow-lg border border-indigo-200 p-6 space-y-6">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200">
            <div>
              <div class="flex items-center space-x-2">
                <span class="bg-indigo-600 text-white font-mono font-bold text-[10px] px-2 py-0.5 rounded">BANK #<?php echo $activeBank['id']; ?></span>
                <span class="font-bold text-slate-800 text-sm"><?php echo htmlspecialchars($activeBank['paper_code'] . ' - ' . $activeBank['course_title']); ?></span>
              </div>
              <p class="text-xs text-slate-500 mt-1">Uploaded by <strong><?php echo htmlspecialchars($activeBank['staff_code']); ?></strong> • Total <?php echo count($activeQuestions); ?> Questions in Relational Pool</p>
            </div>
            <a href="?tab=banks" class="text-xs text-slate-500 hover:text-slate-800 font-bold bg-slate-100 px-3 py-1.5 rounded-lg">Close Questions View</a>
          </div>

          <div class="space-y-4">
            <?php foreach ($activeQuestions as $q): ?>
              <div class="border border-slate-200 rounded-xl p-4 bg-slate-50/50 hover:bg-white hover:shadow-md transition">
                <form method="POST" class="space-y-3 text-xs">
                  <input type="hidden" name="action" value="question_update">
                  <input type="hidden" name="q_id" value="<?php echo $q['id']; ?>">
                  <input type="hidden" name="bank_id" value="<?php echo $activeBank['id']; ?>">
                  
                  <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-2">
                      <span class="bg-slate-800 text-white font-bold font-mono px-2 py-0.5 rounded text-[11px]">Q.<?php echo $q['q_number']; ?></span>
                      <span class="font-bold text-slate-700"><?php echo htmlspecialchars($q['section_type']); ?></span>
                    </div>
                    <div class="flex items-center space-x-2">
                      <label class="font-bold">Unit:</label>
                      <input type="number" name="unit_no" value="<?php echo $q['unit_no']; ?>" min="1" max="5" class="w-12 p-1 border rounded text-center font-bold">
                      <label class="font-bold ml-2">Marks:</label>
                      <input type="number" name="marks" value="<?php echo $q['marks']; ?>" min="1" max="50" class="w-14 p-1 border rounded text-center font-bold text-amber-900">
                      <label class="font-bold ml-2">K-Level:</label>
                      <select name="k_level" class="p-1 border rounded font-bold text-blue-900">
                        <?php foreach (['K1', 'K2', 'K3', 'K4', 'K5', 'K6'] as $kl): ?>
                          <option value="<?php echo $kl; ?>" <?php echo $q['k_level'] === $kl ? 'selected' : ''; ?>><?php echo $kl; ?></option>
                        <?php endforeach; ?>
                      </select>
                      <label class="font-bold ml-2">CO:</label>
                      <select name="co_level" class="p-1 border rounded font-bold text-emerald-900">
                        <?php foreach (['CO1', 'CO2', 'CO3', 'CO4', 'CO5', 'CO6'] as $co): ?>
                          <option value="<?php echo $co; ?>" <?php echo $q['co_level'] === $co ? 'selected' : ''; ?>><?php echo $co; ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>

                  <div>
                    <textarea name="question_text" rows="2" class="w-full border rounded-xl p-2.5 font-sans leading-relaxed text-slate-800 focus:ring-1 focus:ring-indigo-500"><?php echo htmlspecialchars($q['question_text']); ?></textarea>
                  </div>

                  <div class="flex items-center justify-between pt-1">
                    <div class="text-[11px] text-slate-400">
                      <?php if ($q['has_formula']): ?><span class="bg-indigo-50 text-indigo-700 px-1.5 py-0.5 rounded font-mono font-bold">LaTeX Formula</span><?php endif; ?>
                      <?php if ($q['image_url']): ?><span class="bg-teal-50 text-teal-700 px-1.5 py-0.5 rounded font-bold ml-2">Image Attached</span><?php endif; ?>
                    </div>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-3 py-1 rounded-lg shadow text-xs">
                      Update Q.<?php echo $q['q_number']; ?>
                    </button>
                  </div>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    </div>
  <?php endif; ?>

  <!-- ==================== TAB 7: SYSTEM SETTINGS & AUDIT LOGS ==================== -->
  
  <!-- ==================== TAB: GENERATED PAPERS ==================== -->
  <?php if ($tab === 'papers'): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-4">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-4">
        <div>
          <h3 class="font-extrabold text-slate-900 text-base flex items-center space-x-2">
            <i data-lucide="file-check-2" class="w-5 h-5 text-indigo-600"></i>
            <span>Generated Examination Papers Repository (<?php echo $totalRows; ?>)</span>
          </h3>
          <p class="text-xs text-slate-500">Official archived paper sets generated by COE Shuffler. Fully editable, printable, and downloadable as DOCX.</p>
        </div>
        <div class="flex items-center space-x-2">
          <form method="GET" class="flex items-center space-x-2">
            <input type="hidden" name="tab" value="papers">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search paper code / title..." class="border border-slate-300 rounded-xl px-3 py-1.5 text-xs">
            <button type="submit" class="bg-indigo-600 text-white font-bold px-3 py-1.5 rounded-xl text-xs">Search</button>
          </form>
          <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php" class="bg-amber-400 hover:bg-amber-300 text-slate-950 font-black px-4 py-2 rounded-xl text-xs shadow flex items-center space-x-1.5">
            <i data-lucide="shuffle" class="w-4 h-4"></i>
            <span>New Shuffled Sets</span>
          </a>
        </div>
      </div>

      <div class="overflow-x-auto text-xs">
        <table class="w-full text-left border-collapse min-w-[850px]">
          <thead class="bg-slate-50 text-slate-700 uppercase font-black text-[11px] border-b border-slate-200">
            <tr>
              <th class="py-3 px-3">ID</th>
              <th class="py-3 px-3">Course / Paper Code</th>
              <th class="py-3 px-3">Course Title</th>
              <th class="py-3 px-3 text-center">Set</th>
              <th class="py-3 px-3">Semester / Year</th>
              <th class="py-3 px-3 text-center">Marks</th>
              <th class="py-3 px-3">Generated At</th>
              <th class="py-3 px-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 font-medium">
            <?php if (empty($paperList)): ?>
              <tr><td colspan="8" class="text-center py-8 text-slate-400">No generated question papers found.</td></tr>
            <?php else: ?>
              <?php foreach ($paperList as $gp): ?>
                <tr class="hover:bg-slate-50 transition">
                  <td class="py-3 px-3 font-mono font-black text-indigo-700">#<?php echo $gp['id']; ?></td>
                  <td class="py-3 px-3 font-mono font-black text-slate-900"><?php echo htmlspecialchars($gp['paper_code']); ?></td>
                  <td class="py-3 px-3 text-slate-800 font-bold"><?php echo htmlspecialchars($gp['course_title']); ?></td>
                  <td class="py-3 px-3 text-center"><span class="bg-[#1C1D21] text-amber-300 px-2.5 py-0.5 rounded-full font-mono font-black text-[11px]"><?php echo htmlspecialchars($gp['set_name'] ?: 'SET A'); ?></span></td>
                  <td class="py-3 px-3 text-slate-600"><?php echo htmlspecialchars($gp['semester']); ?> (<?php echo htmlspecialchars($gp['academic_year']); ?>)</td>
                  <td class="py-3 px-3 text-center font-black text-amber-900"><?php echo $gp['total_marks']; ?>M</td>
                  <td class="py-3 px-3 font-mono text-slate-500 text-[11px]"><?php echo htmlspecialchars($gp['created_at']); ?></td>
                  <td class="py-3 px-3 text-right">
                    <div class="flex items-center justify-end space-x-1.5">
                      <a href="<?php echo getBaseUrl(); ?>/modules/coe/view_paper.php?paper_id=<?php echo $gp['id']; ?>" class="bg-slate-100 hover:bg-slate-200 text-slate-800 px-2.5 py-1 rounded-lg text-xs font-bold transition flex items-center space-x-1">
                        <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                        <span>Print</span>
                      </a>
                      <a href="<?php echo getBaseUrl(); ?>/api/export_docx.php?paper_id=<?php echo $gp['id']; ?>" class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-2.5 py-1 rounded-lg text-xs font-bold transition flex items-center space-x-1">
                        <i data-lucide="file-down" class="w-3.5 h-3.5"></i>
                        <span>DOCX</span>
                      </a>
                      <form method="POST" onsubmit="return confirm('Permanently delete Generated Paper #<?php echo $gp['id']; ?>?');" class="inline">
                        <input type="hidden" name="action" value="paper_delete">
                        <input type="hidden" name="paper_id" value="<?php echo $gp['id']; ?>">
                        <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-700 p-1.5 rounded-lg text-xs font-bold transition" title="Delete Paper">
                          <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="pt-3 border-t border-slate-100">
          <?php echo render_pagination($page, $totalPages, $totalRows, $perPage, $_GET); ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'settings'): ?>
    <div class="space-y-6">
      
      <form method="POST" class="space-y-6">
        <input type="hidden" name="action" value="system_settings">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          
          <!-- Card 1: Core System & Marks Defaults -->
          <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-4">
            <h3 class="font-black text-slate-900 text-sm flex items-center space-x-2 border-b border-slate-100 pb-3">
              <i data-lucide="sliders" class="w-4 h-4 text-indigo-600"></i>
              <span>Institution & Marks Defaults</span>
            </h3>

            <div class="space-y-3 text-xs">
              <div>
                <label class="font-bold text-slate-700">Institution Name (Header Line 1)</label>
                <input type="text" name="institution_name" value="<?php echo htmlspecialchars($settings['institution_name'] ?: 'HOLY CROSS COLLEGE (AUTONOMOUS), TIRUCHIRAPPALLI – 620 002'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2.5 font-bold">
              </div>

              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="font-bold text-slate-700">Academic Year</label>
                  <input type="text" name="current_academic_year" value="<?php echo htmlspecialchars($settings['current_academic_year'] ?: '2026-2027'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2.5 font-mono font-bold">
                </div>
                <div>
                  <label class="font-bold text-slate-700">Exam Session</label>
                  <input type="text" name="default_exam_session" value="<?php echo htmlspecialchars($settings['default_exam_session'] ?: 'NOVEMBER 2026'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2.5 font-bold">
                </div>
              </div>

              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="font-bold text-slate-700">OBE Max Marks</label>
                  <input type="number" name="obe_max_marks" value="<?php echo htmlspecialchars($settings['obe_max_marks'] ?: '75'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2.5 font-bold font-mono text-indigo-900">
                </div>
                <div>
                  <label class="font-bold text-slate-700">Non-OBE Max Marks</label>
                  <input type="number" name="non_obe_max_marks" value="<?php echo htmlspecialchars($settings['non_obe_max_marks'] ?: '50'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2.5 font-bold font-mono text-amber-900">
                </div>
              </div>

              <div>
                <label class="font-bold text-slate-700">Default OBE Regulation</label>
                <input type="text" name="default_regulation" value="<?php echo htmlspecialchars($settings['default_regulation'] ?: '2024 (OBE)'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2.5">
              </div>

              <div>
                <label class="font-bold text-slate-700">OCR Language Engine</label>
                <input type="text" name="ocr_languages" value="<?php echo htmlspecialchars($settings['ocr_languages'] ?: 'eng+fra+tam'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2.5 font-mono">
              </div>

              <div class="pt-2 space-y-2 border-t border-slate-100">
                <label class="flex items-center space-x-2 font-semibold text-slate-700">
                  <input type="hidden" name="allow_staff_draft" value="0">
                  <input type="checkbox" name="allow_staff_draft" value="1" <?php echo ($settings['allow_staff_draft'] === '1' || $settings['allow_staff_draft'] === '') ? 'checked' : ''; ?> class="rounded">
                  <span>Allow Faculty to save Question Bank Drafts</span>
                </label>
                <label class="flex items-center space-x-2 font-semibold text-slate-700">
                  <input type="hidden" name="staff_can_reopen_submitted" value="0">
                  <input type="checkbox" name="staff_can_reopen_submitted" value="1" <?php echo $settings['staff_can_reopen_submitted'] === '1' ? 'checked' : ''; ?> class="rounded">
                  <span>Allow Faculty to re-edit Submitted Banks</span>
                </label>
              </div>
            </div>
          </div>

          <!-- Card 2: Tamil Translation & Hardcoded Section Settings -->
          <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-4">
            <h3 class="font-black text-slate-900 text-sm flex items-center space-x-2 border-b border-slate-100 pb-3">
              <i data-lucide="languages" class="w-4 h-4 text-emerald-600"></i>
              <span>Tamil (தமிழ்) Headers & Translations</span>
            </h3>

            <div class="space-y-3 text-xs">
              <div>
                <label class="font-bold text-slate-700">Section A Title & Instructions</label>
                <input type="text" name="ta_sec_a_title" value="<?php echo htmlspecialchars($settings['ta_sec_a_title'] ?: 'பகுதி – அ'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-bold font-sans">
                <textarea name="ta_sec_a_sub" rows="2" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-sans text-[11px] leading-relaxed"><?php echo htmlspecialchars($settings['ta_sec_a_sub'] ?: 'பகுதி I (வினா 1-10): சரியான விடையைத் தேர்ந்தெடுத்து எழுதுக & பகுதி II (வினா 11-20): மிகக் குறுகிய விடையளி (20 x 1 = 20 மதிப்பெண்கள்)'); ?></textarea>
              </div>

              <div>
                <label class="font-bold text-slate-700">Section B Title & Instructions</label>
                <input type="text" name="ta_sec_b_title" value="<?php echo htmlspecialchars($settings['ta_sec_b_title'] ?: 'பகுதி – ஆ'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-bold font-sans">
                <textarea name="ta_sec_b_sub" rows="2" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-sans text-[11px] leading-relaxed"><?php echo htmlspecialchars($settings['ta_sec_b_sub'] ?: 'அனைத்து வினாக்களுக்கும் விடையளி (எவையேனும் ஒன்று / அல்லது வகை: வினா 21 முதல் 25 வரை) (5 x 5 = 25 மதிப்பெண்கள்)'); ?></textarea>
              </div>

              <div>
                <label class="font-bold text-slate-700">Section C Title & Instructions</label>
                <input type="text" name="ta_sec_c_title" value="<?php echo htmlspecialchars($settings['ta_sec_c_title'] ?: 'பகுதி – இ'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-bold font-sans">
                <textarea name="ta_sec_c_sub" rows="2" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-sans text-[11px] leading-relaxed"><?php echo htmlspecialchars($settings['ta_sec_c_sub'] ?: 'எவையேனும் இரண்டு வினாக்களுக்கு மட்டும் விடையளி (வினா 26 முதல் 28 வரை) (2 x 10 = 20 மதிப்பெண்கள்)'); ?></textarea>
              </div>

              <div>
                <label class="font-bold text-slate-700">Section D Title & Instructions</label>
                <input type="text" name="ta_sec_d_title" value="<?php echo htmlspecialchars($settings['ta_sec_d_title'] ?: 'பகுதி – ஈ'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-bold font-sans">
                <textarea name="ta_sec_d_sub" rows="2" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-sans text-[11px] leading-relaxed"><?php echo htmlspecialchars($settings['ta_sec_d_sub'] ?: 'கட்டாய வினா (வினா 29) (1 x 10 = 10 மதிப்பெண்கள்)'); ?></textarea>
              </div>
            </div>
          </div>

          <!-- Card 3: French (Français) & English Section Settings -->
          <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-4">
            <h3 class="font-black text-slate-900 text-sm flex items-center space-x-2 border-b border-slate-100 pb-3">
              <i data-lucide="globe" class="w-4 h-4 text-blue-600"></i>
              <span>French (Français) Translations</span>
            </h3>

            <div class="space-y-3 text-xs">
              <div>
                <label class="font-bold text-slate-700">Section A (French)</label>
                <input type="text" name="fr_sec_a_title" value="<?php echo htmlspecialchars($settings['fr_sec_a_title'] ?: 'SECTION – A'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-bold">
                <textarea name="fr_sec_a_sub" rows="2" class="w-full mt-1 border border-slate-300 rounded-xl p-2 text-[11px]"><?php echo htmlspecialchars($settings['fr_sec_a_sub'] ?: 'Partie I (Q.1 à 10): Choix multiples & Partie II (Q.11 à 20): Réponse très courte (20 x 1 = 20 Points)'); ?></textarea>
              </div>

              <div>
                <label class="font-bold text-slate-700">Section B (French)</label>
                <input type="text" name="fr_sec_b_title" value="<?php echo htmlspecialchars($settings['fr_sec_b_title'] ?: 'SECTION – B'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-bold">
                <textarea name="fr_sec_b_sub" rows="2" class="w-full mt-1 border border-slate-300 rounded-xl p-2 text-[11px]"><?php echo htmlspecialchars($settings['fr_sec_b_sub'] ?: 'Répondez à TOUTES les questions (Type Soit/Ou: Q.21 à Q.25) (5 x 5 = 25 Points)'); ?></textarea>
              </div>

              <div>
                <label class="font-bold text-slate-700">Section C (French)</label>
                <input type="text" name="fr_sec_c_title" value="<?php echo htmlspecialchars($settings['fr_sec_c_title'] ?: 'SECTION – C'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-bold">
                <textarea name="fr_sec_c_sub" rows="2" class="w-full mt-1 border border-slate-300 rounded-xl p-2 text-[11px]"><?php echo htmlspecialchars($settings['fr_sec_c_sub'] ?: 'Répondez à DEUX questions au choix (Q.26 à Q.28) (2 x 10 = 20 Points)'); ?></textarea>
              </div>

              <div>
                <label class="font-bold text-slate-700">Section D (French)</label>
                <input type="text" name="fr_sec_d_title" value="<?php echo htmlspecialchars($settings['fr_sec_d_title'] ?: 'SECTION – D'); ?>" class="w-full mt-1 border border-slate-300 rounded-xl p-2 font-bold">
                <textarea name="fr_sec_d_sub" rows="2" class="w-full mt-1 border border-slate-300 rounded-xl p-2 text-[11px]"><?php echo htmlspecialchars($settings['fr_sec_d_sub'] ?: 'Question obligatoire (Q.29) (1 x 10 = 10 Points)'); ?></textarea>
              </div>
            </div>
          </div>

        </div>

        <div class="flex justify-end">
          <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-black px-8 py-3 rounded-xl text-xs shadow-lg transition flex items-center space-x-2">
            <i data-lucide="save" class="w-4 h-4 text-amber-300"></i>
            <span>Save System Parameters & Translation Settings</span>
          </button>
        </div>
      </form>

      <!-- Audit Trail -->
      <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-4">
        <h3 class="font-bold text-slate-900 text-base flex items-center space-x-2 border-b border-slate-100 pb-3">
          <i data-lucide="shield-alert" class="w-5 h-5 text-amber-600"></i>
          <span>System Audit Trail & Access Logs (Recent 25 Actions)</span>
        </h3>

        <div class="overflow-x-auto text-xs">
          <table class="w-full text-left border-collapse min-w-[700px]">
            <thead class="bg-slate-50 text-slate-700 uppercase font-black text-[11px] border-b border-slate-200">
              <tr>
                <th class="py-2.5 px-3">Log ID</th>
                <th class="py-2.5 px-3">Actor / Staff</th>
                <th class="py-2.5 px-3">Role</th>
                <th class="py-2.5 px-3">Action</th>
                <th class="py-2.5 px-3">Entity</th>
                <th class="py-2.5 px-3">Timestamp</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium">
              <?php if (empty($auditLogs)): ?>
                <tr><td colspan="6" class="text-center py-6 text-slate-400">No audit logs recorded yet.</td></tr>
              <?php else: ?>
                <?php foreach ($auditLogs as $l): ?>
                  <tr class="hover:bg-slate-50">
                    <td class="py-2.5 px-3 font-mono text-slate-500">#<?php echo $l['id']; ?></td>
                    <td class="py-2.5 px-3 font-bold text-slate-900"><?php echo htmlspecialchars($l['actor'] ?? 'SYSTEM'); ?></td>
                    <td class="py-2.5 px-3"><span class="bg-slate-100 px-2 py-0.5 rounded text-[10px] font-bold"><?php echo htmlspecialchars($l['role'] ?? 'GUEST'); ?></span></td>
                    <td class="py-2.5 px-3 font-mono font-bold text-indigo-700"><?php echo htmlspecialchars($l['action'] ?? ''); ?></td>
                    <td class="py-2.5 px-3 text-slate-600"><?php echo htmlspecialchars($l['entity_type'] . ($l['entity_id'] ? " [{$l['entity_id']}]" : '')); ?></td>
                    <td class="py-2.5 px-3 text-slate-400 font-mono text-[11px]"><?php echo htmlspecialchars($l['created_at'] ?? ''); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  <?php endif; ?>

</main>

<script>
function openEditStaff(s) {
  document.getElementById('edit-staff-orig-code').value = s.STAFF_CODE;
  document.getElementById('edit-staff-code').value = s.STAFF_CODE;
  document.getElementById('edit-staff-name').value = s.FIRST_NAME || '';
  document.getElementById('edit-staff-dept').value = s.DEPARTMENT || '';
  document.getElementById('edit-staff-deptcode').value = s.dept_code1 || '';
  document.getElementById('edit-staff-desig').value = s.designation || '';
  document.getElementById('edit-staff-email').value = s.email || '';
  document.getElementById('edit-staff-mobile').value = s.MOBILE_NO || '';
  document.getElementById('edit-staff-status').checked = (s.status === 'Y');
  document.getElementById('edit-staff-hod').checked = (s.hod_status === 'Y');
  document.getElementById('modal-edit-staff').classList.remove('hidden');
}

function openEditDept(d) {
  document.getElementById('edit-dept-orig-code').value = d.code;
  document.getElementById('edit-dept-code').value = d.code;
  document.getElementById('edit-dept-name').value = d.name;
  document.getElementById('edit-dept-active').checked = (d.is_active == 1 || d.is_active === '1');
  document.getElementById('modal-edit-dept').classList.remove('hidden');
}

function openEditCourse(c) {
  document.getElementById('edit-course-orig-code').value = c.coursecode;
  document.getElementById('edit-course-code').value = c.coursecode;
  document.getElementById('edit-course-dept').value = c.dept_code;
  document.getElementById('edit-course-title').value = c.coursetitle;
  document.getElementById('edit-course-level').value = c.level || 'UG';
  document.getElementById('edit-course-credit').value = c.credit || 3;
  document.getElementById('edit-course-maxmark').value = c.maxmark || 75;
  document.getElementById('edit-course-pattern').value = c.qpattern || 'OBE';
  document.getElementById('edit-course-type').value = c.type || 'CORE';
  document.getElementById('modal-edit-course').classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
