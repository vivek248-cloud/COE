<?php
/**
 * Faculty Teaching Dashboard
 * Holy Cross College (Autonomous) - Examination System
 * Crextio & Modern App Design System
 */
define('PAGE_TITLE', 'Faculty Dashboard');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';

requireAuth();
$user = getCurrentUser();
$pdo = getDBConnection();

// 1. Fetch assigned courses from timetablefaculty joined with courses and departments
$assignedCourses = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            tf.papercode, 
            COALESCE(c.coursetitle, tf.papercode) as coursetitle,
            COALESCE(c.dept_code, tf.deptcode) as dept_code,
            c.level,
            c.maxmark,
            c.qpattern,
            c.type as course_type,
            c.credit,
            d.name as dept_name,
            tf.degree,
            tf.stream,
            tf.acyear
        FROM timetablefaculty tf
        LEFT JOIN courses c ON UPPER(tf.papercode) = UPPER(c.coursecode)
        LEFT JOIN departments d ON (UPPER(tf.deptcode) = UPPER(d.code) OR UPPER(c.dept_code) = UPPER(d.code))
        WHERE UPPER(tf.fid) = UPPER(?)
        ORDER BY tf.papercode ASC
    ");
    $stmt->execute([$user['staff_code']]);
    $assignedCourses = $stmt->fetchAll();
} catch (Exception $e) {}

// 2. Fetch faculty's Question Banks
$questionBanks = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM question_banks 
        WHERE UPPER(staff_code) = UPPER(?) 
        ORDER BY id DESC
    ");
    $stmt->execute([$user['staff_code']]);
    $questionBanks = $stmt->fetchAll();
} catch (Exception $e) {}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Welcome Banner with Modern Crextio Warm Gradient -->
  <div class="bg-gradient-to-r from-slate-950 via-slate-900 to-[#121826] rounded-[28px] p-7 text-white shadow-xl border border-orange-500/20 relative overflow-hidden">
    <div class="absolute -right-10 -bottom-10 w-72 h-72 bg-orange-500/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <div class="inline-flex items-center gap-2 bg-orange-500/20 text-orange-300 border border-orange-500/30 text-xs px-3.5 py-1 rounded-full font-bold">
          <i data-lucide="award" class="w-3.5 h-3.5 text-orange-400"></i>
          <span>Faculty Teaching Portal • NAAC A++ (4th Cycle)</span>
        </div>
        <h2 class="text-2xl sm:text-3xl font-black mt-2 tracking-tight">Welcome, <?php echo htmlspecialchars($user['name']); ?></h2>
        <p class="text-slate-300 text-xs sm:text-sm mt-1 max-w-2xl leading-relaxed">
          Department of <?php echo htmlspecialchars($user['department']); ?> (Staff Code: <code class="bg-black/40 px-2 py-0.5 rounded-full font-mono text-orange-300 font-bold"><?php echo htmlspecialchars($user['staff_code']); ?></code>). Upload your question bank, attach diagrams, write formulas, and submit for semester examinations.
        </p>
      </div>
      <div class="flex gap-2 shrink-0">
        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php" class="btn-orange-pill text-xs shadow-md">
          <i data-lucide="upload-cloud" class="w-4 h-4"></i>
          <span>Upload Question Bank</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Quick Category Tabs (Matching Chegg UI Topology) -->
  <div class="flex items-center space-x-2 overflow-x-auto custom-scrollbar-x pb-1">
    <span class="text-xs font-bold text-slate-500 mr-2 shrink-0">Quick Actions:</span>
    <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php" class="px-4 py-2 rounded-full bg-orange-500 text-white font-bold text-xs shadow-sm shrink-0 flex items-center space-x-1.5">
      <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i>
      <span>Upload Bank</span>
    </a>
    <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_blueprint.php" class="px-4 py-2 rounded-full bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-bold text-xs shadow-sm shrink-0 flex items-center space-x-1.5 transition">
      <i data-lucide="layout-template" class="w-3.5 h-3.5 text-purple-600"></i>
      <span>Course Blueprint</span>
    </a>
    <a href="<?php echo getBaseUrl(); ?>/modules/teaching/view_banks.php" class="px-4 py-2 rounded-full bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-bold text-xs shadow-sm shrink-0 flex items-center space-x-1.5 transition">
      <i data-lucide="archive" class="w-3.5 h-3.5 text-emerald-600"></i>
      <span>My Question Banks</span>
    </a>
    <a href="<?php echo getBaseUrl(); ?>/modules/teaching/download_template.php" class="px-4 py-2 rounded-full bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-bold text-xs shadow-sm shrink-0 flex items-center space-x-1.5 transition">
      <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5 text-sky-600"></i>
      <span>Templates</span>
    </a>
  </div>

  <!-- Assigned Courses Grid (from timetablefaculty) -->
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div class="flex items-center space-x-2">
        <div class="w-7 h-7 rounded-full bg-orange-100 text-orange-800 flex items-center justify-center font-bold text-xs">
          <i data-lucide="calendar-check" class="w-4 h-4"></i>
        </div>
        <h3 class="text-base sm:text-lg font-black text-slate-900">My Assigned Courses (from timetablefaculty)</h3>
      </div>
      <span class="text-xs text-slate-500 font-bold bg-white px-3 py-1 rounded-full border border-slate-200 shadow-sm"><?php echo count($assignedCourses); ?> Course(s) Allocated</span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php if (empty($assignedCourses)): ?>
        <div class="col-span-full card-modern p-10 text-center text-slate-500 text-xs">
          No courses currently assigned in timetable schedule for staff code <strong><?php echo htmlspecialchars($user['staff_code']); ?></strong>.
        </div>
      <?php else: ?>
        <?php foreach ($assignedCourses as $c): ?>
          <?php 
            $examInfo = hcc_course_exam_info($c);
            $paperMarks = $examInfo['exam_marks'];
            $marksBadgeClass = $examInfo['is_practical'] ? 'bg-amber-50 text-amber-900 border-amber-200' : 'bg-orange-50 text-orange-900 border-orange-200';
          ?>
          <div class="card-modern p-5 flex flex-col justify-between space-y-4 hover:border-orange-300 transition">
            <div>
              <div class="flex items-center justify-between mb-2">
                <span class="bg-slate-100 text-slate-800 text-[11px] font-bold px-2.5 py-0.5 rounded-full font-mono"><?php echo htmlspecialchars($c['papercode']); ?></span>
                <span class="bg-orange-100 text-orange-800 text-[10px] font-extrabold px-2.5 py-0.5 rounded-full"><?php echo htmlspecialchars($c['degree'] ?: $c['level'] ?: 'UG'); ?></span>
              </div>
              <h4 class="font-black text-slate-900 text-sm mb-1 leading-snug"><?php echo htmlspecialchars($c['coursetitle']); ?></h4>
              <p class="text-xs text-slate-500 mb-1">
                <?php echo htmlspecialchars($c['dept_name'] ?: $c['dept_code']); ?> • Credits: <strong><?php echo htmlspecialchars($c['credit'] ?: '4'); ?></strong>
              </p>
              <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                <span class="<?php echo $marksBadgeClass; ?> border text-[10px] font-extrabold px-2.5 py-0.5 rounded-full">
                  Exam Marks: <?php echo htmlspecialchars($examInfo['marks_label']); ?>
                </span>
                <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded-full">
                  <?php echo htmlspecialchars($examInfo['type_label']); ?>
                </span>
              </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
              <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php?paper_code=<?php echo urlencode($c['papercode']); ?>" class="btn-orange-pill text-xs py-1.5 px-3.5">
                <i data-lucide="upload-cloud" class="w-3.5 h-3.5"></i>
                <span>Upload Bank</span>
              </a>
              <a href="<?php echo getBaseUrl(); ?>/modules/teaching/view_banks.php?paper_code=<?php echo urlencode($c['papercode']); ?>" class="text-slate-600 hover:text-slate-900 text-xs font-bold px-3 py-1.5 rounded-full bg-slate-100 hover:bg-slate-200 transition">
                View Banks
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Question Banks History Grid -->
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div class="flex items-center space-x-2">
        <div class="w-7 h-7 rounded-full bg-emerald-100 text-emerald-800 flex items-center justify-center font-bold text-xs">
          <i data-lucide="archive" class="w-4 h-4"></i>
        </div>
        <h3 class="text-base sm:text-lg font-black text-slate-900">Recently Stored Question Banks</h3>
      </div>
      <a href="<?php echo getBaseUrl(); ?>/modules/teaching/view_banks.php" class="text-xs text-orange-600 font-bold hover:underline">View All &rarr;</a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php if (empty($questionBanks)): ?>
        <div class="col-span-full card-modern p-10 text-center text-slate-500 text-xs">
          No question banks uploaded yet. Use the <strong>Upload Bank</strong> button above to upload Word or CSV files.
        </div>
      <?php else: ?>
        <?php foreach (array_slice($questionBanks, 0, 6) as $qb): ?>
          <div class="card-modern p-5 flex flex-col justify-between space-y-3 hover:border-emerald-300 transition">
            <div>
              <div class="flex items-center justify-between mb-1.5">
                <span class="bg-slate-100 text-slate-800 text-[11px] font-bold px-2.5 py-0.5 rounded-full font-mono">Bank #<?php echo $qb['id']; ?></span>
                <span class="bg-emerald-50 text-emerald-800 border border-emerald-200 text-[10px] font-extrabold px-2.5 py-0.5 rounded-full">
                  <?php echo htmlspecialchars($qb['status'] ?: 'Active'); ?>
                </span>
              </div>
              <h4 class="font-black text-slate-900 text-sm mb-1"><?php echo htmlspecialchars($qb['course_title'] ?: $qb['paper_code']); ?></h4>
              <p class="text-xs text-slate-500">
                Course: <strong><?php echo htmlspecialchars($qb['paper_code']); ?></strong> • Semester: <?php echo htmlspecialchars($qb['semester']); ?>
              </p>
              <div class="text-[11px] text-slate-400 mt-1">
                Total Questions: <strong class="text-slate-800"><?php echo htmlspecialchars((string)($qb['total_questions'] ?? '0')); ?></strong>
              </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
              <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php?bank_id=<?php echo $qb['id']; ?>" class="font-bold text-slate-700 hover:text-slate-900">
                Edit Bank
              </a>
              <a href="<?php echo getBaseUrl(); ?>/modules/teaching/view_banks.php?view_id=<?php echo $qb['id']; ?>" class="bg-emerald-50 text-emerald-700 hover:bg-emerald-100 px-3 py-1 rounded-full font-bold transition">
                Inspect Questions &rarr;
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
