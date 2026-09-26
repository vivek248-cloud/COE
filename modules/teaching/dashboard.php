<?php
/**
 * Faculty Teaching Dashboard
 * Holy Cross College (Autonomous) - Examination System
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
} catch (Exception $e) {
    // ignore
}

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
} catch (Exception $e) {
    // ignore
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Welcome Banner -->
  <div class="bg-gradient-to-r from-indigo-800 via-indigo-700 to-blue-800 rounded-2xl p-6 text-white shadow-xl relative overflow-hidden">
    <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <div class="inline-flex items-center gap-2 bg-white/15 text-white border border-white/20 text-xs px-3 py-1 rounded-full font-bold">
          <i data-lucide="award" class="w-3.5 h-3.5 text-amber-300"></i>
          <span>Faculty Teaching Dashboard</span>
        </div>
        <h2 class="text-2xl sm:text-3xl font-extrabold mt-2">Welcome, <?php echo htmlspecialchars($user['name']); ?></h2>
        <p class="text-indigo-100 text-xs sm:text-sm mt-1 max-w-2xl leading-relaxed">
          Department of <?php echo htmlspecialchars($user['department']); ?> (Staff Code: <code class="bg-black/30 px-1.5 py-0.5 rounded font-mono text-amber-300 font-bold"><?php echo htmlspecialchars($user['staff_code']); ?></code>). Upload your question bank, attach diagrams, write formulas, and submit for semester examinations.
        </p>
      </div>
      <div class="flex gap-2">
        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php" class="bg-amber-400 hover:bg-amber-300 text-slate-950 font-bold px-4 py-2.5 rounded-xl shadow-lg flex items-center space-x-2 transition text-xs">
          <i data-lucide="plus-circle" class="w-4 h-4"></i>
          <span>Upload Question Bank</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Assigned Courses Grid (from timetablefaculty) -->
  <div>
    <div class="flex items-center justify-between mb-4">
      <div class="flex items-center space-x-2">
        <i data-lucide="calendar-check" class="w-5 h-5 text-indigo-600"></i>
        <h3 class="text-lg font-bold text-slate-800">My Assigned Courses (from timetablefaculty)</h3>
      </div>
      <span class="text-xs text-slate-500 font-medium"><?php echo count($assignedCourses); ?> Course(s) Allocated</span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php if (empty($assignedCourses)): ?>
        <div class="col-span-full bg-white rounded-2xl p-8 border border-slate-200 text-center text-slate-500 text-xs">
          No courses currently assigned in timetable schedule for staff code <strong><?php echo htmlspecialchars($user['staff_code']); ?></strong>.
        </div>
      <?php else: ?>
        <?php foreach ($assignedCourses as $c): ?>
          <?php 
            $examInfo = hcc_course_exam_info($c);
            $paperMarks = $examInfo['exam_marks'];
            $marksBadgeClass = $examInfo['is_practical'] ? 'bg-amber-50 text-amber-900 border-amber-200' : 'bg-indigo-50 text-indigo-900 border-indigo-200';
          ?>
          <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition flex flex-col justify-between space-y-4">
            <div>
              <div class="flex items-center justify-between mb-2">
                <span class="bg-indigo-100 text-indigo-800 text-[11px] font-bold px-2 py-0.5 rounded font-mono"><?php echo htmlspecialchars($c['papercode']); ?></span>
                <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded"><?php echo htmlspecialchars($c['degree'] ?: $c['level'] ?: 'UG'); ?></span>
              </div>
              <h4 class="font-extrabold text-slate-900 text-sm mb-1"><?php echo htmlspecialchars($c['coursetitle']); ?></h4>
              <p class="text-xs text-slate-500 mb-1">
                <?php echo htmlspecialchars($c['dept_name'] ?: $c['dept_code']); ?> • Credits: <strong><?php echo htmlspecialchars($c['credit'] ?: '4'); ?></strong>
              </p>
              <div class="flex items-center gap-2 mt-2 flex-wrap">
                <span class="<?php echo $marksBadgeClass; ?> border text-[11px] font-bold px-2 py-0.5 rounded-lg">
                  Exam Marks: <?php echo htmlspecialchars($examInfo['marks_label']); ?>
                </span>
                <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded-lg">
                  <?php echo htmlspecialchars($examInfo['type_label']); ?>
                </span>
              </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
              <span class="text-[11px] text-indigo-700 font-bold">Regulation: 2024 (OBE)</span>
              <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php?paper_code=<?php echo urlencode($c['papercode']); ?>&course_title=<?php echo urlencode($c['coursetitle']); ?>&dept_code=<?php echo urlencode($c['dept_code']); ?>&max_marks=<?php echo $paperMarks; ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3.5 py-1.5 rounded-xl flex items-center space-x-1 shadow transition">
                <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                <span>Upload Bank</span>
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Question Banks History -->
  <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 border-b border-slate-100 pb-4">
      <div class="flex items-center space-x-2">
        <i data-lucide="folder-kanban" class="w-5 h-5 text-indigo-600"></i>
        <h3 class="text-lg font-bold text-slate-800">My Question Banks Repository</h3>
      </div>
      <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php" class="text-xs text-indigo-600 hover:underline font-bold">+ New Question Bank</a>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-semibold border-y border-slate-200">
          <tr>
            <th class="py-3 px-4">Paper Code & Title</th>
            <th class="py-3 px-4">Dept / Degree</th>
            <th class="py-3 px-4">Semester & Year</th>
            <th class="py-3 px-4">Total Qs / Marks</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (empty($questionBanks)): ?>
            <tr>
              <td colspan="6" class="py-8 text-center text-slate-400 text-xs">
                No uploaded question banks yet. Use the upload button above to submit a question bank.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($questionBanks as $b): ?>
              <tr class="hover:bg-slate-50 transition">
                <td class="py-3 px-4 font-bold text-slate-800">
                  <div class="font-mono text-indigo-700"><?php echo htmlspecialchars($b['paper_code']); ?></div>
                  <div class="text-xs text-slate-600 font-normal"><?php echo htmlspecialchars($b['course_title']); ?></div>
                </td>
                <td class="py-3 px-4 text-slate-600 text-xs"><?php echo htmlspecialchars($b['dept_name'] ?: $b['dept_code']); ?> • <?php echo htmlspecialchars($b['degree_level'] ?: 'UG'); ?></td>
                <td class="py-3 px-4 text-slate-600 text-xs"><?php echo htmlspecialchars($b['semester']); ?> (<?php echo htmlspecialchars($b['academic_year']); ?>)</td>
                <td class="py-3 px-4 font-semibold text-slate-700 text-xs"><?php echo htmlspecialchars($b['total_questions'] ?: 30); ?> Qs • <?php echo htmlspecialchars($b['max_marks'] ?: 75); ?> M</td>
                <td class="py-3 px-4">
                  <?php if ($b['status'] === 'Submitted'): ?>
                    <span class="bg-amber-100 text-amber-800 text-xs px-2.5 py-0.5 rounded-full font-bold">Submitted to COE</span>
                  <?php elseif ($b['status'] === 'Approved'): ?>
                    <span class="bg-emerald-100 text-emerald-800 text-xs px-2.5 py-0.5 rounded-full font-bold">Approved</span>
                  <?php else: ?>
                    <span class="bg-slate-100 text-slate-700 text-xs px-2.5 py-0.5 rounded-full font-bold">Draft</span>
                  <?php endif; ?>
                </td>
                <td class="py-3 px-4 text-right">
                  <div class="flex items-center justify-end space-x-2">
                    <?php if ($b['status'] === 'Draft' || $b['status'] === 'Rejected'): ?>
                      <a href="<?php echo getBaseUrl(); ?>/modules/teaching/upload.php?bank_id=<?php echo $b['id']; ?>" class="bg-indigo-50 text-indigo-700 px-2.5 py-1 rounded-lg text-xs font-bold hover:bg-indigo-100">Edit Draft</a>
                    <?php else: ?>
                      <span class="text-xs text-slate-400 font-semibold">Locked (COE)</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
