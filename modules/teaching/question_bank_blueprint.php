<?php
declare(strict_types=1);

define('PAGE_TITLE', 'Question Bank Blueprint');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/question_bank_blueprint.php';

requireAuth();

$pdo = getDBConnection();
$user = getCurrentUser();
qps_qbb_ensure_schema($pdo);
qps_ensure_question_draft_schema($pdo);

$baseUrl = getBaseUrl();
$ctx = qps_qbb_hod_context($pdo, $user);
$isCoe = isCOE();
$isHod = !$isCoe && !empty($ctx['is_hod']);
$staffCode = (string)$ctx['staff_code'];
$deptCode = strtoupper((string)$ctx['dept_code']);
$flashSuccess = '';
$flashError = '';

if ($deptCode === '' && !$isHod) {
    // Staff can still view published blueprints using their timetable allocations.
    $deptCode = strtoupper((string)($user['dept_code'] ?? ''));
}

function qbb_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function qbb_redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isHod) {
            throw new RuntimeException('Only the HOD of the course department can create or publish a Question Bank Blueprint.');
        }

        $csrf = (string)($_POST['csrf'] ?? '');
        if (empty($_SESSION['qbb_csrf']) || !hash_equals((string)$_SESSION['qbb_csrf'], $csrf)) {
            throw new RuntimeException('Security token expired. Refresh the page and try again.');
        }

        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'save') {
            $paperCode = strtoupper(trim((string)($_POST['paper_code'] ?? '')));
            $semester = trim((string)($_POST['semester'] ?? 'Semester 1'));
            $academicYear = trim((string)($_POST['academic_year'] ?? '2026-2027'));
            $examType = trim((string)($_POST['exam_type'] ?? 'Odd Semester End Examination'));
            $targetQuestions = max(1, min(9999, (int)($_POST['total_questions'] ?? 275)));
            $matrixRaw = json_decode((string)($_POST['matrix_json'] ?? '[]'), true);

            if ($paperCode === '') throw new RuntimeException('Select a course before saving the blueprint.');

            $course = qps_qbb_course($pdo, $deptCode, $paperCode);
            if (!$course) {
                throw new RuntimeException('This course does not belong to your HOD department. The blueprint cannot be created.');
            }

            if (!is_array($matrixRaw)) throw new RuntimeException('Invalid blueprint matrix.');
            $matrix = qps_qbb_normalize_matrix($matrixRaw);
            $check = qps_qbb_validate_matrix($matrix);

            if ($check['errors']) throw new RuntimeException(implode(' ', $check['errors']));
            $poolCheck = qps_qbb_validate_against_pool($pdo, $paperCode, $semester, $academicYear, $examType, $matrix, true);
            if ($poolCheck['errors']) throw new RuntimeException(implode(' ', $poolCheck['errors']));
            if ((int)$check['total_questions'] !== $targetQuestions) {
                throw new RuntimeException(
                    'Blueprint question total mismatch. Target: ' . $targetQuestions .
                    ', matrix total: ' . (int)$check['total_questions'] . '.'
                );
            }

            $matrixJson = json_encode($matrix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($matrixJson === false) throw new RuntimeException('Could not encode the blueprint matrix.');

            $courseTitle = trim((string)($course['coursetitle'] ?? $paperCode));

            $pdo->beginTransaction();

            if ($id > 0) {
                $st = $pdo->prepare(
                    "SELECT id FROM question_bank_blueprints
                     WHERE id=? AND UPPER(department_code)=UPPER(?)
                     LIMIT 1"
                );
                $st->execute([$id, $deptCode]);
                if (!$st->fetchColumn()) throw new RuntimeException('Blueprint not found in your department.');

                // Editing a published blueprint creates a new draft state. It
                // must be explicitly published again before staff can see it.
                $st = $pdo->prepare(
                    "UPDATE question_bank_blueprints
                     SET paper_code=?, course_title=?, semester=?, academic_year=?, exam_type=?,
                         total_questions=?, total_marks=?, matrix_json=?, status='DRAFT',
                         published_by=NULL, published_at=NULL
                     WHERE id=?"
                );
                $st->execute([
                    $paperCode, $courseTitle, $semester, $academicYear, $examType,
                    $targetQuestions, (int)$check['total_marks'], $matrixJson, $id
                ]);
                $savedId = $id;
            } else {
                $st = $pdo->prepare(
                    "INSERT INTO question_bank_blueprints
                     (department_code,paper_code,course_title,semester,academic_year,exam_type,
                      total_questions,total_marks,matrix_json,status,created_by,created_by_name)
                     VALUES (?,?,?,?,?,?,?,?,?,'DRAFT',?,?)"
                );
                $st->execute([
                    $deptCode, $paperCode, $courseTitle, $semester, $academicYear, $examType,
                    $targetQuestions, (int)$check['total_marks'], $matrixJson,
                    $staffCode, $ctx['name']
                ]);
                $savedId = (int)$pdo->lastInsertId();
            }

            $pdo->commit();

            try {
                $stAudit = $pdo->prepare(
                    "INSERT INTO qps_audit_log (actor_staff_code, actor_role, action, entity_type, entity_id, new_data_json, created_at)
                     VALUES (?,?,?,?,?,?,NOW())"
                );
                $stAudit->execute([
                    $staffCode, 'HOD', $id > 0 ? 'QUESTION_BANK_BLUEPRINT_UPDATED' : 'QUESTION_BANK_BLUEPRINT_CREATED',
                    'question_bank_blueprint', $savedId, $matrixJson
                ]);
            } catch (Throwable $e) {}

            qbb_redirect($baseUrl . '/modules/teaching/question_bank_blueprint.php?edit_id=' . $savedId . '&saved=1');
        }

        if ($action === 'publish') {
            if ($id <= 0) throw new RuntimeException('Blueprint ID is required.');

            $st = $pdo->prepare(
                "SELECT * FROM question_bank_blueprints
                 WHERE id=? AND UPPER(department_code)=UPPER(?)
                 LIMIT 1"
            );
            $st->execute([$id, $deptCode]);
            $bp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$bp) throw new RuntimeException('Blueprint not found in your department.');

            $matrix = json_decode((string)$bp['matrix_json'], true);
            if (!is_array($matrix)) throw new RuntimeException('Saved blueprint matrix is invalid.');
            $check = qps_qbb_validate_matrix($matrix);
            if ($check['errors']) throw new RuntimeException(implode(' ', $check['errors']));
            $poolCheck = qps_qbb_validate_against_pool($pdo, (string)$bp['paper_code'], (string)$bp['semester'], (string)$bp['academic_year'], (string)$bp['exam_type'], $matrix, true);
            if ($poolCheck['errors']) throw new RuntimeException(implode(' ', $poolCheck['errors']));
            if ((int)$check['total_questions'] !== (int)$bp['total_questions']) {
                throw new RuntimeException('This blueprint cannot be published because its matrix total does not match its target question count.');
            }

            $pdo->beginTransaction();

            // Only one published QBB is active for the same course/context.
            $st = $pdo->prepare(
                "UPDATE question_bank_blueprints
                 SET status='ARCHIVED'
                 WHERE id<>?
                   AND UPPER(department_code)=UPPER(?)
                   AND UPPER(paper_code)=UPPER(?)
                   AND semester=?
                   AND academic_year=?
                   AND exam_type=?
                   AND status='PUBLISHED'"
            );
            $st->execute([
                $id, $deptCode, $bp['paper_code'], $bp['semester'],
                $bp['academic_year'], $bp['exam_type']
            ]);

            $st = $pdo->prepare(
                "UPDATE question_bank_blueprints
                 SET status='PUBLISHED', published_by=?, published_at=NOW()
                 WHERE id=?"
            );
            $st->execute([$staffCode, $id]);
            $pdo->commit();

            try {
                $stAudit = $pdo->prepare(
                    "INSERT INTO qps_audit_log (actor_staff_code, actor_role, action, entity_type, entity_id, new_data_json, created_at)
                     VALUES (?,?,?,?,?,?,NOW())"
                );
                $stAudit->execute([
                    $staffCode, 'HOD', 'QUESTION_BANK_BLUEPRINT_PUBLISHED',
                    'question_bank_blueprint', $id,
                    json_encode(['paper_code'=>$bp['paper_code'],'status'=>'PUBLISHED'], JSON_UNESCAPED_UNICODE)
                ]);
            } catch (Throwable $e) {}

            qbb_redirect($baseUrl . '/modules/teaching/question_bank_blueprint.php?published=1');
        }

        if ($action === 'toggle_use') {
            if ($id <= 0) throw new RuntimeException('Blueprint ID is required.');
            $enabled = ((int)($_POST['enabled'] ?? 0) === 1) ? 1 : 0;

            $st = $pdo->prepare(
                "SELECT * FROM question_bank_blueprints
                 WHERE id=? AND UPPER(department_code)=UPPER(?)
                 LIMIT 1"
            );
            $st->execute([$id, $deptCode]);
            $bp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$bp) throw new RuntimeException('Blueprint not found in your department.');
            if ((string)$bp['status'] !== 'PUBLISHED' && $enabled === 1) {
                throw new RuntimeException('Publish the Question Bank Blueprint before turning Use Question Bank Blueprint ON.');
            }

            $pdo->beginTransaction();
            if ($enabled === 1) {
                // Only one active QBB may be used for a course/context. Turning this one ON
                // automatically turns older published QBBs for the same course/context OFF.
                $st = $pdo->prepare(
                    "UPDATE question_bank_blueprints
                     SET qbb_enabled=0
                     WHERE id<>?
                       AND UPPER(department_code)=UPPER(?)
                       AND UPPER(paper_code)=UPPER(?)
                       AND semester=? AND academic_year=? AND exam_type=?
                       AND status='PUBLISHED'"
                );
                $st->execute([$id, $deptCode, $bp['paper_code'], $bp['semester'], $bp['academic_year'], $bp['exam_type']]);
            }
            $st = $pdo->prepare("UPDATE question_bank_blueprints SET qbb_enabled=? WHERE id=? AND UPPER(department_code)=UPPER(?)");
            $st->execute([$enabled, $id, $deptCode]);
            $pdo->commit();

            try {
                $stAudit = $pdo->prepare(
                    "INSERT INTO qps_audit_log (actor_staff_code, actor_role, action, entity_type, entity_id, new_data_json, created_at)
                     VALUES (?,?,?,?,?,?,NOW())"
                );
                $stAudit->execute([$staffCode, 'HOD', $enabled ? 'QUESTION_BANK_BLUEPRINT_ENABLED' : 'QUESTION_BANK_BLUEPRINT_DISABLED', 'question_bank_blueprint', $id, json_encode(['qbb_enabled'=>$enabled], JSON_UNESCAPED_UNICODE)]);
            } catch (Throwable $e) {}

            qbb_redirect($baseUrl . '/modules/teaching/question_bank_blueprint.php?toggle=' . ($enabled ? 'on' : 'off'));
        }

        if ($action === 'archive') {
            if ($id <= 0) throw new RuntimeException('Blueprint ID is required.');
            $st = $pdo->prepare(
                "UPDATE question_bank_blueprints
                 SET status='ARCHIVED'
                 WHERE id=? AND UPPER(department_code)=UPPER(?)"
            );
            $st->execute([$id, $deptCode]);
            qbb_redirect($baseUrl . '/modules/teaching/question_bank_blueprint.php?archived=1');
        }

        throw new RuntimeException('Unknown blueprint action.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $flashError = $e->getMessage();
}

if (empty($_SESSION['qbb_csrf'])) $_SESSION['qbb_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['qbb_csrf'];

$hodCourses = [];
$staffCourses = [];
if ($isHod) {
    $st = $pdo->prepare(
        "SELECT coursecode, coursetitle, dept_code, level, maxmark, credit, type
         FROM courses
         WHERE UPPER(dept_code)=UPPER(?)
         ORDER BY coursecode ASC"
    );
    $st->execute([$deptCode]);
    $hodCourses = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT tf.papercode AS coursecode,
                    COALESCE(c.coursetitle,tf.papercode) AS coursetitle,
                    COALESCE(c.dept_code,tf.deptcode) AS dept_code,
                    c.level,c.maxmark,c.credit,c.type
             FROM timetablefaculty tf
             LEFT JOIN courses c ON UPPER(c.coursecode)=UPPER(tf.papercode)
             WHERE UPPER(tf.fid)=UPPER(?)
             ORDER BY tf.papercode ASC"
        );
        $st->execute([$staffCode]);
        $staffCourses = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$filterCourse = strtoupper(trim((string)($_GET['course_code'] ?? '')));
$editId = (int)($_GET['edit_id'] ?? 0);
$editing = null;

if ($isHod && $editId > 0) {
    $st = $pdo->prepare(
        "SELECT * FROM question_bank_blueprints
         WHERE id=? AND UPPER(department_code)=UPPER(?)
         LIMIT 1"
    );
    $st->execute([$editId, $deptCode]);
    $editing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$hodBlueprints = [];
$publishedForStaff = [];

if ($isHod) {
    $sql = "SELECT * FROM question_bank_blueprints WHERE UPPER(department_code)=UPPER(?)";
    $params = [$deptCode];
    if ($filterCourse !== '') {
        $sql .= " AND UPPER(paper_code)=UPPER(?)";
        $params[] = $filterCourse;
    }
    $sql .= " ORDER BY updated_at DESC, id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $hodBlueprints = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
    $courseCodes = array_values(array_unique(array_map(
        static fn($r) => strtoupper((string)($r['coursecode'] ?? '')),
        $staffCourses
    )));
    if ($filterCourse !== '' && in_array($filterCourse, $courseCodes, true)) {
        $courseCodes = [$filterCourse];
    }

    if ($courseCodes) {
        $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
        $sql = "SELECT * FROM question_bank_blueprints
                WHERE status='PUBLISHED' AND qbb_enabled=1 AND UPPER(paper_code) IN ($placeholders)
                ORDER BY paper_code ASC, updated_at DESC, id DESC";
        $st = $pdo->prepare($sql);
        $st->execute($courseCodes);
        $publishedForStaff = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

$matrix = [];
if ($editing) {
    $matrix = json_decode((string)$editing['matrix_json'], true);
    if (!is_array($matrix)) $matrix = [];
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <div class="bg-gradient-to-r from-slate-950 via-indigo-950 to-slate-900 rounded-[28px] p-7 text-white shadow-xl border border-indigo-900/50">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <span class="inline-flex items-center gap-2 bg-amber-400/15 text-amber-300 border border-amber-400/25 rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-wider">
          Question Bank Blueprint • <?php echo $isHod ? 'HOD' : 'Teaching Staff'; ?>
        </span>
        <h1 class="text-2xl font-black mt-2">Course Question Bank Blueprint</h1>
        <p class="text-slate-300 text-xs mt-1 max-w-3xl">
          This page contains only HOD-created Question Bank Blueprints. COE OBE blueprints are stored separately and are never listed here.
        </p>
      </div>
      <div class="flex flex-wrap gap-2 text-[11px] font-bold">
        <span class="bg-white/10 border border-white/15 rounded-full px-3 py-2">Department: <?php echo qbb_h($deptCode ?: 'Assigned Courses'); ?></span>
        <?php if ($isHod): ?>
          <a href="?create=1" class="bg-amber-400 text-slate-950 rounded-full px-4 py-2 hover:bg-amber-300 transition">+ Create Blueprint</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($flashError): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl p-4 text-xs font-bold"><?php echo qbb_h($flashError); ?></div>
  <?php endif; ?>

  <?php if (isset($_GET['saved'])): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl p-4 text-xs font-bold">Blueprint saved as Draft. Publish it when it is ready; only then will staff see it.</div>
  <?php elseif (isset($_GET['published'])): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl p-4 text-xs font-bold">Blueprint published. It is now visible only to staff assigned to that course.</div>
  <?php elseif (isset($_GET['toggle'])): ?>
    <div class="bg-<?php echo $_GET['toggle']==='on' ? 'emerald' : 'amber'; ?>-50 border border-<?php echo $_GET['toggle']==='on' ? 'emerald' : 'amber'; ?>-200 text-<?php echo $_GET['toggle']==='on' ? 'emerald' : 'amber'; ?>-800 rounded-2xl p-4 text-xs font-bold">Use Question Bank Blueprint <?php echo $_GET['toggle']==='on' ? 'enabled. Staff and COE will use the active published QBB for this course.' : 'disabled. Staff will no longer see the QBB and COE QBB sync will be unavailable.'; ?></div>
  <?php elseif (isset($_GET['archived'])): ?>
    <div class="bg-slate-100 border border-slate-200 text-slate-700 rounded-2xl p-4 text-xs font-bold">Blueprint archived. Staff can no longer see it.</div>
  <?php endif; ?>

  <?php if ($isHod && (isset($_GET['create']) || $editing)): ?>
    <section class="bg-white rounded-[28px] border border-stone-200 shadow-sm p-6 space-y-5">
      <div class="flex items-center justify-between border-b border-stone-100 pb-4">
        <div>
          <h2 class="font-black text-slate-900"><?php echo $editing ? 'Edit Question Bank Blueprint' : 'Create Question Bank Blueprint'; ?></h2>
          <p class="text-[11px] text-slate-500 mt-1">The selected course must belong to your HOD department. Save first, then publish.</p>
        </div>
        <a href="<?php echo $baseUrl; ?>/modules/teaching/question_bank_blueprint.php" class="text-xs font-bold text-slate-500 hover:text-slate-900">Close</a>
      </div>

      <form method="post" id="qbbForm" class="space-y-5">
        <input type="hidden" name="csrf" value="<?php echo qbb_h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int)($editing['id'] ?? 0); ?>">
        <input type="hidden" name="matrix_json" id="matrix_json">

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
          <label class="text-xs font-bold text-slate-700">
            Course *
            <select name="paper_code" id="qbbCourse" required class="mt-1 w-full rounded-xl border border-stone-300 bg-stone-50 p-2.5">
              <option value="">Select course</option>
              <?php foreach ($hodCourses as $c): ?>
                <option value="<?php echo qbb_h((string)$c['coursecode']); ?>" <?php echo strtoupper((string)($editing['paper_code'] ?? '')) === strtoupper((string)$c['coursecode']) ? 'selected' : ''; ?>>
                  <?php echo qbb_h($c['coursecode'] . ' — ' . $c['coursetitle']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="text-xs font-bold text-slate-700">
            Academic Year
            <select name="academic_year" class="mt-1 w-full rounded-xl border border-stone-300 bg-stone-50 p-2.5">
              <?php foreach (['2026-2027','2025-2026','2024-2025'] as $ay): ?>
                <option value="<?php echo $ay; ?>" <?php echo ($editing['academic_year'] ?? '2026-2027') === $ay ? 'selected' : ''; ?>><?php echo $ay; ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="text-xs font-bold text-slate-700">
            Semester
            <select name="semester" class="mt-1 w-full rounded-xl border border-stone-300 bg-stone-50 p-2.5">
              <?php for ($s=1; $s<=8; $s++): $sv='Semester '.$s; ?>
                <option value="<?php echo $sv; ?>" <?php echo ($editing['semester'] ?? 'Semester 1') === $sv ? 'selected' : ''; ?>><?php echo $sv; ?></option>
              <?php endfor; ?>
            </select>
          </label>

          <label class="text-xs font-bold text-slate-700">
            Exam Type
            <select name="exam_type" class="mt-1 w-full rounded-xl border border-stone-300 bg-stone-50 p-2.5">
              <?php
                $examTypes = ['Odd Semester End Examination','Even Semester End Examination','Internal 1','Internal 2','Year'];
                foreach ($examTypes as $et):
              ?>
                <option value="<?php echo qbb_h($et); ?>" <?php echo ($editing['exam_type'] ?? 'Odd Semester End Examination') === $et ? 'selected' : ''; ?>><?php echo qbb_h($et); ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="text-xs font-bold text-slate-700">
            Target Questions *
            <input type="number" name="total_questions" min="1" max="9999" value="<?php echo (int)($editing['total_questions'] ?? 275); ?>" class="mt-1 w-full rounded-xl border border-stone-300 bg-stone-50 p-2.5 font-black">
          </label>
        </div>

        <div class="rounded-2xl bg-indigo-50 border border-indigo-100 p-4 text-[11px] text-indigo-900">
          <strong>Publish rule:</strong> the matrix total must exactly equal Target Questions. When you publish, any older published Question Bank Blueprint for the same course + academic year + semester + exam is archived automatically.
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
          <div class="text-xs text-emerald-900"><strong>Uploaded pool:</strong> Sync the selected course to load its real Units/Sub-Units before editing. The server will reject counts that exceed the cumulative uploaded pool.</div>
          <button type="button" id="qbbSyncBtn" onclick="syncQbbPool()" class="rounded-xl bg-emerald-600 text-white px-4 py-2 text-xs font-black hover:bg-emerald-700">↻ Sync Uploaded Units</button>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-stone-200">
          <table class="min-w-[1100px] w-full text-xs">
            <thead class="bg-slate-950 text-white">
              <tr>
                <th class="p-2 text-left">Section</th>
                <th class="p-2 text-left">Unit</th>
                <th class="p-2 text-left">Sub-Unit</th>
                <th class="p-2 text-left">Type</th>
                <th class="p-2 text-left">K-Level</th>
                <th class="p-2 text-left">Marks</th>
                <th class="p-2 text-left">Required Count</th>
                <th class="p-2 text-left">Choice</th>
                <th class="p-2 text-left">Compulsory</th>
                <th class="p-2 text-left">Instruction</th>
                <th class="p-2"></th>
              </tr>
            </thead>
            <tbody id="qbbRows"></tbody>
          </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <button type="button" onclick="addQbbRow()" class="rounded-xl bg-indigo-50 text-indigo-700 border border-indigo-200 px-4 py-2.5 text-xs font-black hover:bg-indigo-100">+ Add Blueprint Row</button>
          <div class="flex items-center gap-3">
            <div class="text-xs font-bold text-slate-500">Matrix total: <span id="qbbTotal" class="text-slate-950 font-black">0</span></div>
            <button type="submit" onclick="return prepareQbbSubmit()" class="rounded-xl bg-slate-950 text-white px-5 py-2.5 text-xs font-black hover:bg-slate-800">Save Draft</button>
          </div>
        </div>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($isHod): ?>
    <section class="bg-white rounded-[28px] border border-stone-200 shadow-sm p-6 space-y-4">
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <div>
          <h2 class="font-black text-slate-900">HOD Question Bank Blueprints</h2>
          <p class="text-[11px] text-slate-500">Only Question Bank Blueprints created for courses in <?php echo qbb_h($deptCode); ?> are shown.</p>
        </div>
        <form method="get" class="flex gap-2">
          <select name="course_code" onchange="this.form.submit()" class="rounded-xl border border-stone-300 bg-stone-50 p-2 text-xs font-bold min-w-[260px]">
            <option value="">All Department Courses</option>
            <?php foreach ($hodCourses as $c): ?>
              <option value="<?php echo qbb_h((string)$c['coursecode']); ?>" <?php echo $filterCourse === strtoupper((string)$c['coursecode']) ? 'selected' : ''; ?>>
                <?php echo qbb_h($c['coursecode'] . ' — ' . $c['coursetitle']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-[950px] w-full text-xs">
          <thead class="bg-stone-100 text-slate-700">
            <tr>
              <th class="p-3 text-left">Course</th>
              <th class="p-3 text-left">Context</th>
              <th class="p-3 text-left">Target</th>
              <th class="p-3 text-left">Matrix</th>
              <th class="p-3 text-left">Status</th>
              <th class="p-3 text-left">Use QBB</th>
              <th class="p-3 text-left">Updated</th>
              <th class="p-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$hodBlueprints): ?>
            <tr><td colspan="8" class="p-8 text-center text-slate-500 font-semibold">No Question Bank Blueprint found for this filter.</td></tr>
          <?php endif; ?>
          <?php foreach ($hodBlueprints as $bp): ?>
            <?php $m = json_decode((string)$bp['matrix_json'], true); $m = is_array($m) ? $m : []; ?>
            <tr class="border-b border-stone-100 hover:bg-stone-50">
              <td class="p-3">
                <div class="font-black text-slate-900"><?php echo qbb_h($bp['paper_code']); ?></div>
                <div class="text-[10px] text-slate-500"><?php echo qbb_h($bp['course_title']); ?></div>
              </td>
              <td class="p-3 text-slate-600"><?php echo qbb_h($bp['academic_year'] . ' • ' . $bp['semester']); ?><br><?php echo qbb_h($bp['exam_type']); ?></td>
              <td class="p-3 font-black"><?php echo (int)$bp['total_questions']; ?></td>
              <td class="p-3 text-slate-600"><?php echo count($m); ?> rows / <?php echo (int)$bp['total_marks']; ?> marks</td>
              <td class="p-3">
                <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-black <?php echo $bp['status']==='PUBLISHED' ? 'bg-emerald-100 text-emerald-700' : ($bp['status']==='ARCHIVED' ? 'bg-slate-100 text-slate-600' : 'bg-amber-100 text-amber-800'); ?>">
                  <?php echo qbb_h($bp['status']); ?>
                </span>
              </td>
              <td class="p-3">
                <?php if ($bp['status'] === 'PUBLISHED'): ?>
                  <form method="post" class="inline-flex items-center gap-2" onsubmit="return confirm('<?php echo $bp['qbb_enabled'] ? 'Turn OFF use of this Question Bank Blueprint?' : 'Turn ON use of this Question Bank Blueprint for this course?'; ?>');">
                    <input type="hidden" name="csrf" value="<?php echo qbb_h($csrf); ?>">
                    <input type="hidden" name="action" value="toggle_use">
                    <input type="hidden" name="id" value="<?php echo (int)$bp['id']; ?>">
                    <input type="hidden" name="enabled" value="<?php echo $bp['qbb_enabled'] ? '0' : '1'; ?>">
                    <button type="submit" class="relative inline-flex h-6 w-11 items-center rounded-full transition <?php echo $bp['qbb_enabled'] ? 'bg-emerald-600' : 'bg-slate-300'; ?>" aria-label="Toggle Use Question Bank Blueprint">
                      <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition <?php echo $bp['qbb_enabled'] ? 'translate-x-5' : 'translate-x-0.5'; ?>"></span>
                    </button>
                    <span class="text-[10px] font-black <?php echo $bp['qbb_enabled'] ? 'text-emerald-700' : 'text-slate-500'; ?>"><?php echo $bp['qbb_enabled'] ? 'ON' : 'OFF'; ?></span>
                  </form>
                <?php else: ?>
                  <span class="text-[10px] text-slate-400 font-bold">Publish first</span>
                <?php endif; ?>
              </td>
              <td class="p-3 text-slate-500"><?php echo qbb_h((string)$bp['updated_at']); ?></td>
              <td class="p-3 text-right">
                <a href="?edit_id=<?php echo (int)$bp['id']; ?>" class="inline-flex rounded-lg bg-indigo-50 text-indigo-700 px-3 py-1.5 font-black mr-1">Edit</a>
                <?php if ($bp['status'] === 'DRAFT'): ?>
                  <form method="post" class="inline" onsubmit="return confirm('Publish this Question Bank Blueprint for this course?');">
                    <input type="hidden" name="csrf" value="<?php echo qbb_h($csrf); ?>">
                    <input type="hidden" name="action" value="publish">
                    <input type="hidden" name="id" value="<?php echo (int)$bp['id']; ?>">
                    <button class="rounded-lg bg-emerald-600 text-white px-3 py-1.5 font-black">Publish</button>
                  </form>
                <?php elseif ($bp['status'] === 'PUBLISHED'): ?>
                  <form method="post" class="inline" onsubmit="return confirm('Archive this blueprint? Staff will no longer see it.');">
                    <input type="hidden" name="csrf" value="<?php echo qbb_h($csrf); ?>">
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="id" value="<?php echo (int)$bp['id']; ?>">
                    <button class="rounded-lg bg-slate-100 text-slate-700 px-3 py-1.5 font-black">Archive</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php else: ?>
    <section class="bg-white rounded-[28px] border border-stone-200 shadow-sm p-6 space-y-4">
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <div>
          <h2 class="font-black text-slate-900">Published Course Question Bank Blueprints</h2>
          <p class="text-[11px] text-slate-500">Only blueprints explicitly published by the HOD are shown. Your access is based on timetablefaculty course allocation.</p>
        </div>
        <?php if ($staffCourses): ?>
          <form method="get">
            <select name="course_code" onchange="this.form.submit()" class="rounded-xl border border-stone-300 bg-stone-50 p-2 text-xs font-bold min-w-[260px]">
              <option value="">All My Assigned Courses</option>
              <?php foreach ($staffCourses as $c): ?>
                <option value="<?php echo qbb_h((string)$c['coursecode']); ?>" <?php echo $filterCourse === strtoupper((string)$c['coursecode']) ? 'selected' : ''; ?>>
                  <?php echo qbb_h($c['coursecode'] . ' — ' . $c['coursetitle']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <?php if (!$publishedForStaff): ?>
          <div class="lg:col-span-2 rounded-2xl border border-dashed border-stone-300 p-10 text-center text-slate-500 text-xs font-semibold">
            No published Question Bank Blueprint is available for your assigned courses yet.
          </div>
        <?php endif; ?>

        <?php foreach ($publishedForStaff as $bp): ?>
          <?php $m = json_decode((string)$bp['matrix_json'], true); $m = is_array($m) ? $m : []; ?>
          <article class="rounded-2xl border border-emerald-200 bg-emerald-50/40 p-5">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-[10px] uppercase tracking-wider font-black text-emerald-700">Published Question Bank Blueprint</div>
                <h3 class="font-black text-slate-900 mt-1"><?php echo qbb_h($bp['paper_code']); ?></h3>
                <p class="text-xs text-slate-600"><?php echo qbb_h($bp['course_title']); ?></p>
              </div>
              <span class="rounded-full bg-emerald-600 text-white px-2.5 py-1 text-[10px] font-black">PUBLISHED</span>
            </div>

            <div class="grid grid-cols-3 gap-2 mt-4 text-center text-[10px]">
              <div class="rounded-xl bg-white border p-2"><div class="text-slate-500">Target</div><strong><?php echo (int)$bp['total_questions']; ?></strong></div>
              <div class="rounded-xl bg-white border p-2"><div class="text-slate-500">Rows</div><strong><?php echo count($m); ?></strong></div>
              <div class="rounded-xl bg-white border p-2"><div class="text-slate-500">Marks</div><strong><?php echo (int)$bp['total_marks']; ?></strong></div>
            </div>

            <div class="mt-4 overflow-x-auto">
              <table class="w-full text-[10px] bg-white rounded-xl overflow-hidden">
                <thead class="bg-slate-900 text-white">
                  <tr><th class="p-2">Sec</th><th class="p-2">Unit</th><th class="p-2">Type</th><th class="p-2">K</th><th class="p-2">Marks</th><th class="p-2">Count</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($m as $r): ?>
                    <tr class="border-b border-stone-100">
                      <td class="p-2 font-black"><?php echo qbb_h((string)$r['section']); ?></td>
                      <td class="p-2"><?php echo qbb_h($r['sub_unit']); ?></td>
                      <td class="p-2"><?php echo qbb_h($r['question_type']); ?></td>
                      <td class="p-2"><?php echo qbb_h($r['k_level']); ?></td>
                      <td class="p-2"><?php echo (int)$r['marks']; ?></td>
                      <td class="p-2 font-black"><?php echo (int)$r['required_count']; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

</main>

<script>
const initialQbbRows = <?php echo json_encode($matrix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function qbbEsc(v) {
  return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function qbbRowHtml(r, i) {
  const sections = ['A','B','C','D'];
  const types = ['MCQ','MATCH','ASSERTION_REASON','VSA','PARAGRAPH','ESSAY','EITHER_OR','PASSAGE'];
  const ks = ['K1','K2','K3','K4','K5','K6'];
  const choices = ['ALL','VSA_ALL','ONE_OF_PAIR','ANY','COMPULSORY'];
  const choiceLabels = {ALL:'Answer ALL the questions:',VSA_ALL:'II Answer the following in one or two sentences each:',ONE_OF_PAIR:'Answer FIVE questions, selecting one from each set:',ANY:'Answer any TWO questions:',COMPULSORY:'Answer the following Question (COMPULSORY):'};

  return '<tr class="border-b border-stone-100" data-qbb-row="'+i+'">' +
    '<td class="p-2"><select data-f="section" class="qbb-field w-20 rounded-lg border p-2 text-xs font-black">' +
      sections.map(x=>'<option '+(String(r.section||'A')===x?'selected':'')+'>'+x+'</option>').join('') + '</select></td>' +
    '<td class="p-2"><select data-f="unit" class="qbb-field w-full min-w-[80px] rounded-lg border p-2 text-xs">'+qbbUnitOptions(r.unit)+'</select></td>' +
    '<td class="p-2"><select data-f="sub_unit" class="qbb-field w-full min-w-[90px] rounded-lg border p-2 text-xs">'+qbbSubUnitOptions(r.sub_unit)+'</select></td>' +
    '<td class="p-2"><select data-f="question_type" class="qbb-field w-36 rounded-lg border p-2 text-xs">'+types.map(x=>'<option '+(String(r.question_type||'MCQ')===x?'selected':'')+'>'+x+'</option>').join('')+'</select></td>' +
    '<td class="p-2"><select data-f="k_level" class="qbb-field w-20 rounded-lg border p-2 text-xs">'+ks.map(x=>'<option '+(String(r.k_level||'K1')===x?'selected':'')+'>'+x+'</option>').join('')+'</select></td>' +
    '<td class="p-2"><input data-f="marks" type="number" min="1" max="100" value="'+Number(r.marks||1)+'" class="qbb-field w-20 rounded-lg border p-2 text-xs"></td>' +
    '<td class="p-2"><input data-f="required_count" type="number" min="1" max="999" value="'+Number(r.required_count||1)+'" class="qbb-field w-24 rounded-lg border p-2 text-xs font-black"></td>' +
    '<td class="p-2 min-w-[260px]"><select data-f="choice_mode" class="qbb-field w-full min-w-[240px] rounded-lg border p-2 text-xs">'+choices.map(x=>'<option value="'+x+'" '+(String(r.choice_mode||'ALL')===x?'selected':'')+'>'+qbbEsc(choiceLabels[x])+'</option>').join('')+'</select></td>' +
    '<td class="p-2 text-center"><input data-f="compulsory" type="checkbox" '+(r.compulsory?'checked':'')+' class="qbb-field w-4 h-4"></td>' +
    '<td class="p-2"><input data-f="instruction" value="'+qbbEsc(r.instruction||'')+'" placeholder="Optional instruction" class="qbb-field w-52 rounded-lg border p-2 text-xs"></td>' +
    '<td class="p-2"><button type="button" onclick="this.closest(\'tr\').remove(); refreshQbbTotal();" class="text-rose-600 font-black px-2">×</button></td>' +
  '</tr>';
}

let qbbPool = {found:false, total:0, units:{}, sub_units:{}};
function qbbUnitOptions(selected){
  const units = Object.keys(qbbPool.units||{}).sort((a,b)=>Number(a)-Number(b));
  const vals = units.length ? units : ['1','2','3','4','5'];
  return vals.map(x=>`<option value="${x}" ${String(selected||1)===String(x)?'selected':''}>Unit ${x} (${qbbPool.units?.[x]??0})</option>`).join('');
}
function qbbSubUnitOptions(selected){
  const vals = Object.keys(qbbPool.sub_units||{}).sort((a,b)=>a.localeCompare(b,undefined,{numeric:true}));
  const safe = vals.length ? vals : [String(selected||'1.1')];
  return safe.map(x=>`<option value="${x}" ${String(selected||'1.1')===String(x)?'selected':''}>${x} (${qbbPool.sub_units?.[x]??0})</option>`).join('');
}
function renderQbbPoolInventory(){
  const box=document.getElementById('qbbPoolInventory'); const body=document.getElementById('qbbPoolInventoryBody'); const count=document.getElementById('qbbPoolInventoryCount');
  if(!box||!body) return;
  const vals=Object.entries(qbbPool.sub_units||{}).sort((a,b)=>a[0].localeCompare(b[0],undefined,{numeric:true}));
  box.classList.toggle('hidden', vals.length===0);
  if(count) count.textContent=vals.length+' sub-units';
  body.innerHTML=vals.map(([su,n])=>`<div class="bg-white border border-emerald-100 rounded-xl px-2 py-2 text-[10px]"><div class="font-black text-slate-900">${qbbEsc(su)}</div><div class="text-slate-500">${n} questions</div></div>`).join('');
}
async function syncQbbPool(){
  const course=document.getElementById('qbbCourse')?.value||'';
  if(!course){ Swal.fire('Course Required','Select a course before syncing uploaded units.','warning'); return; }
  const btn=document.getElementById('qbbSyncBtn'); const old=btn?.innerHTML||'';
  if(btn){btn.disabled=true;btn.innerHTML='Syncing…';}
  try{
    const semester=document.querySelector('[name="semester"]')?.value||'';
    const academicYear=document.querySelector('[name="academic_year"]')?.value||'';
    const examType=document.querySelector('[name="exam_type"]')?.value||'';
    const r=await fetch('<?php echo $baseUrl; ?>/api/get_bank_units.php?paper_code='+encodeURIComponent(course)+'&semester='+encodeURIComponent(semester)+'&academic_year='+encodeURIComponent(academicYear)+'&exam_type='+encodeURIComponent(examType));
    const data=await r.json();
    if(!data.found){ qbbPool={found:false,total:0,units:{},sub_units:{}}; Swal.fire('No Uploaded Pool','No submitted/verified question bank exists for '+course+'.','warning'); return; }
    qbbPool={found:true,total:Number(data.total_questions||0),units:Object.fromEntries((data.units||[]).map(x=>[String(x.unit),Number(x.count||0)])),sub_units:Object.fromEntries((data.sub_units||[]).map(x=>[String(x.sub_unit),Number(x.count||0)]))};
    // Preserve entered rows, but snap unit/sub-unit options to the real uploaded structure.
    const rows=collectQbbRows();
    document.getElementById('qbbRows').innerHTML='';
    rows.forEach(r=>addQbbRow(r));
    Swal.fire({toast:true,position:'top-end',icon:'success',title:`Synced ${qbbPool.total} cumulative questions`,text:`${Object.keys(qbbPool.sub_units).length} uploaded sub-units loaded`,timer:2200,showConfirmButton:false});
  }catch(e){ Swal.fire('Sync Failed',e.message||'Could not read uploaded question pool.','error'); }
  finally{ if(btn){btn.disabled=false;btn.innerHTML=old;} }
}

function addQbbRow(row={}) {
  const body = document.getElementById('qbbRows');
  if (!body) return;
  const index = body.children.length;
  body.insertAdjacentHTML('beforeend', qbbRowHtml(row, index));
  refreshQbbTotal();
}

function collectQbbRows() {
  const out = [];
  document.querySelectorAll('#qbbRows tr[data-qbb-row]').forEach(tr => {
    const get = f => tr.querySelector('[data-f="'+f+'"]');
    out.push({
      section: get('section')?.value || 'A',
      unit: parseInt(get('unit')?.value || '1', 10) || 1,
      sub_unit: get('sub_unit')?.value || '1.1',
      question_type: get('question_type')?.value || 'MCQ',
      k_level: get('k_level')?.value || 'K1',
      marks: parseInt(get('marks')?.value || '1', 10) || 1,
      required_count: parseInt(get('required_count')?.value || '0', 10) || 0,
      choice_mode: get('choice_mode')?.value || 'ALL',
      compulsory: !!get('compulsory')?.checked,
      instruction: get('instruction')?.value || ''
    });
  });
  return out;
}

function refreshQbbTotal() {
  const rows = collectQbbRows();
  const total = rows.reduce((s,r)=>s + (Number(r.required_count)||0), 0);
  const el = document.getElementById('qbbTotal');
  if (el) el.textContent = total;
  return total;
}

function prepareQbbSubmit() {
  const target = parseInt(document.querySelector('[name="total_questions"]')?.value || '0', 10) || 0;
  const rows = collectQbbRows();
  const total = rows.reduce((s,r)=>s + (Number(r.required_count)||0), 0);

  if (!rows.length) {
    alert('Add at least one blueprint row.');
    return false;
  }
  if (total !== target) {
    alert('Blueprint total must equal Target Questions. Target: '+target+', matrix total: '+total+'.');
    return false;
  }
  if (qbbPool.found) {
    const usedSub={}; const usedUnit={}; const errors=[];
    rows.forEach((r,i)=>{ const c=Number(r.required_count)||0; const su=String(r.sub_unit||''); const u=String(r.unit||''); usedSub[su]=(usedSub[su]||0)+c; usedUnit[u]=(usedUnit[u]||0)+c; if(!(su in qbbPool.sub_units)) errors.push(`Row ${i+1}: ${su} is not present in the uploaded pool.`); });
    Object.entries(usedSub).forEach(([su,c])=>{const a=Number(qbbPool.sub_units[su]||0); if(c>a) errors.push(`Sub-Unit ${su}: requested ${c}, but it totally contains only ${a}.`);});
    Object.entries(usedUnit).forEach(([u,c])=>{const a=Number(qbbPool.units[u]||0); if(c>a) errors.push(`Unit ${u}: requested ${c}, but it totally contains only ${a}.`);});
    if(errors.length){ Swal.fire({icon:'error',title:'Question Pool Shortage',html:'<div class=\"text-left text-xs space-y-1\">'+errors.slice(0,8).map(qbbEsc).join('<br>')+'</div>'}); return false; }
  }

  document.getElementById('matrix_json').value = JSON.stringify(rows);
  return true;
}

document.addEventListener('input', e => {
  if (e.target.closest('#qbbRows')) refreshQbbTotal();
});

if (document.getElementById('qbbRows')) {
  const rows = Array.isArray(initialQbbRows) && initialQbbRows.length
    ? initialQbbRows
    : [
        {section:'A',unit:1,sub_unit:'1.1',question_type:'MCQ',k_level:'K1',marks:1,required_count:5,choice_mode:'ALL',compulsory:1,instruction:'Answer ALL the questions:'},
        {section:'B',unit:1,sub_unit:'1.1',question_type:'VSA',k_level:'K2',marks:2,required_count:5,choice_mode:'ALL',compulsory:1,instruction:''},
        {section:'C',unit:1,sub_unit:'1.1',question_type:'PARAGRAPH',k_level:'K3',marks:5,required_count:5,choice_mode:'ANY',compulsory:0,instruction:''},
        {section:'D',unit:1,sub_unit:'1.1',question_type:'ESSAY',k_level:'K4',marks:10,required_count:5,choice_mode:'ALL',compulsory:1,instruction:''}
      ];
  rows.forEach(addQbbRow);
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
