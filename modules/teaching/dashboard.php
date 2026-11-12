<?php
/**
 * Faculty Teaching Dashboard - Page 1: Allocated Courses
 * Holy Cross College (Autonomous) - COE Question Paper System
 * Shows courses assigned to faculty with 3 independent status chips per course
 * and appropriate action buttons for each item type.
 * Works with existing course_submissions table schema.
 */
define('PAGE_TITLE', 'Allocated Courses');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/workflow.php';

requireTeachingStaff();
$user = getCurrentUser();
$pdo = getDBConnection();

// Ensure schema exists
qps_ensure_aux_schema($pdo);

// Get current academic year from settings
$academicYear = qps_setting($pdo, 'default_academic_year', DEFAULT_ACADEMIC_YEAR);
$regulation = qps_setting($pdo, 'default_regulation', DEFAULT_REGULATION);

/**
 * Fetch courses assigned to this faculty from timetablefaculty
 * Joined with courses and departments for display info
 * Deduplicates by papercode to avoid multiple timetable entries per course
 */
$assignedCourses = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            tf.papercode AS course_code,
            COALESCE(c.coursetitle, tf.papercode) AS course_title,
            COALESCE(c.dept_code, tf.deptcode) AS dept_code,
            c.level,
            c.maxmark,
            c.qpattern,
            c.type AS course_type,
            c.credit,
            d.name AS dept_name,
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
} catch (Throwable $e) {
    error_log('[Teaching Dashboard] Error fetching assigned courses: ' . $e->getMessage());
}

/**
 * Fetch workflow status for each course from course_submissions table
 * Uses actual schema: question_bank_status, master_blueprint_status, final_blueprint_status columns
 * Returns array: [course_code] = [status columns + ids]
 */
$courseStatuses = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            course_code,
            question_bank_status,
            master_blueprint_status,
            final_blueprint_status,
            question_bank_draft_id,
            master_blueprint_id,
            final_blueprint_id,
            dean_template_id,
            version,
            updated_at
        FROM course_submissions
        WHERE staff_code = ?
          AND academic_year = ?
          AND regulation = ?
        ORDER BY updated_at DESC, id DESC
    ");
    $stmt->execute([$user['staff_code'], $academicYear, $regulation]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $code = strtoupper((string)$row['course_code']);
        // Use the newest row for each course (ordered by updated_at DESC)
        if (isset($courseStatuses[$code])) {
            continue;
        }
        $courseStatuses[$code] = [
            'question_bank' => [
                'status' => $row['question_bank_status'] ?? 'draft',
                'submission_id' => $row['question_bank_draft_id'],
                'version' => (int)($row['version'] ?? 1),
            ],
            'master_blueprint' => [
                'status' => $row['master_blueprint_status'] ?? 'draft',
                'submission_id' => $row['master_blueprint_id'],
                'version' => (int)($row['version'] ?? 1),
            ],
            'final_blueprint' => [
                'status' => $row['final_blueprint_status'] ?? 'draft',
                'submission_id' => $row['final_blueprint_id'],
                'version' => (int)($row['version'] ?? 1),
            ],
            'dean_template_id' => $row['dean_template_id'],
        ];
    }
} catch (Throwable $e) {
    error_log('[Teaching Dashboard] Error fetching course statuses: ' . $e->getMessage());
}

/**
 * Status display configuration
 */
$statusConfig = [
    'draft' => ['label' => 'Draft', 'class' => 'bg-slate-50 text-slate-600 border-slate-200', 'icon' => 'file-text'],
    'submitted_to_hod' => ['label' => 'Submitted to HOD', 'class' => 'bg-blue-50 text-blue-700 border-blue-200', 'icon' => 'send'],
    'hod_corrections' => ['label' => 'HOD Corrections', 'class' => 'bg-rose-50 text-rose-700 border-rose-200', 'icon' => 'alert-circle'],
    'sent_to_dean' => ['label' => 'Sent to Dean', 'class' => 'bg-indigo-50 text-indigo-700 border-indigo-200', 'icon' => 'send'],
    'dean_corrections' => ['label' => 'Dean Corrections', 'class' => 'bg-rose-50 text-rose-700 border-rose-200', 'icon' => 'alert-circle'],
    'sent_to_coe' => ['label' => 'Sent to COE', 'class' => 'bg-purple-50 text-purple-700 border-purple-200', 'icon' => 'send'],
    'approved' => ['label' => 'Approved', 'class' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'icon' => 'check-circle'],
];

$itemTypeConfig = [
    'question_bank' => ['label' => 'Question Bank', 'icon' => 'database', 'color' => 'orange'],
    'master_blueprint' => ['label' => 'Master Blueprint', 'icon' => 'layout-template', 'color' => 'purple'],
    'final_blueprint' => ['label' => 'Final Blueprint', 'icon' => 'file-check', 'color' => 'emerald'],
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Welcome Banner -->
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
          Department of <?php echo htmlspecialchars($user['department']); ?> (Staff Code: <code class="bg-black/40 px-2 py-0.5 rounded-full font-mono text-orange-300 font-bold"><?php echo htmlspecialchars($user['staff_code']); ?></code>).
          Manage your Question Bank, Master Blueprint, and Final Blueprint submissions for semester examinations.
        </p>
      </div>
      <div class="flex gap-2 shrink-0">
        <a href="#courses" class="bg-white/10 hover:bg-white/20 text-white px-4 py-2 rounded-lg text-sm font-bold shadow transition flex items-center gap-1.5 border border-white/20">
          <i data-lucide="arrow-down" class="w-4 h-4"></i>
          <span>View My Courses</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Context Badges -->
  <div class="flex flex-wrap items-center gap-2 text-xs">
    <span class="bg-orange-50 text-orange-700 border border-orange-200 px-3 py-1 rounded-full font-bold">
      <i data-lucide="calendar" class="w-3 h-3 inline mr-1"></i>
      Academic Year: <?php echo htmlspecialchars($academicYear); ?>
    </span>
    <span class="bg-purple-50 text-purple-700 border border-purple-200 px-3 py-1 rounded-full font-bold">
      <i data-lucide="book-open" class="w-3 h-3 inline mr-1"></i>
      Regulation: <?php echo htmlspecialchars($regulation); ?>
    </span>
    <span class="bg-slate-50 text-slate-700 border border-slate-200 px-3 py-1 rounded-full font-bold">
      <i data-lucide="user" class="w-3 h-3 inline mr-1"></i>
      Staff: <?php echo htmlspecialchars($user['staff_code']); ?>
    </span>
  </div>

  <!-- Assigned Courses Grid -->
  <div class="space-y-4" id="courses">
    <div class="flex items-center justify-between">
      <div class="flex items-center space-x-2">
        <div class="w-7 h-7 rounded-full bg-orange-100 text-orange-800 flex items-center justify-center font-bold text-xs">
          <i data-lucide="calendar-check" class="w-4 h-4"></i>
        </div>
        <h3 class="text-base sm:text-lg font-black text-slate-900">My Allocated Courses</h3>
      </div>
      <span class="text-xs text-slate-500 font-bold bg-white px-3 py-1 rounded-full border border-slate-200 shadow-sm">
        <?php echo count($assignedCourses); ?> Course(s) Allocated
      </span>
    </div>

    <?php if (empty($assignedCourses)): ?>
      <div class="card-modern p-10 text-center text-slate-500 text-xs">
        <i data-lucide="calendar-x" class="w-12 h-12 mx-auto text-slate-300 mb-3"></i>
        <p class="font-medium text-slate-700">No courses currently assigned</p>
        <p class="mt-1">No courses found in timetablefaculty for staff code <strong><?php echo htmlspecialchars($user['staff_code']); ?></strong>.</p>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($assignedCourses as $course):
          $courseCode = strtoupper((string)$course['course_code']);
          $statuses = $courseStatuses[$courseCode] ?? [];

          // Get exam info for display
          $examInfo = hcc_course_exam_info($course, $pdo);
          $marksBadgeClass = $examInfo['is_practical'] ? 'bg-amber-50 text-amber-900 border-amber-200' : 'bg-orange-50 text-orange-900 border-orange-200';
        ?>
          <div class="card-modern p-5 flex flex-col space-y-4 hover:border-orange-300 transition-all duration-200">
            <!-- Course Header -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <span class="bg-slate-100 text-slate-800 text-[11px] font-bold px-2.5 py-0.5 rounded-full font-mono">
                  <?php echo htmlspecialchars($courseCode); ?>
                </span>
                <span class="bg-orange-100 text-orange-800 text-[10px] font-extrabold px-2.5 py-0.5 rounded-full">
                  <?php echo htmlspecialchars($course['degree'] ?: $course['level'] ?: 'UG'); ?>
                </span>
              </div>
              <h4 class="font-black text-slate-900 text-sm mb-1 leading-snug"><?php echo htmlspecialchars($course['course_title']); ?></h4>
              <p class="text-xs text-slate-500 mb-1">
                <?php echo htmlspecialchars($course['dept_name'] ?: $course['dept_code']); ?>
                • Credits: <strong><?php echo htmlspecialchars($course['credit'] ?: '4'); ?></strong>
              </p>
              <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                <span class="<?php echo $marksBadgeClass; ?> border text-[10px] font-extrabold px-2.5 py-0.5 rounded-full">
                  Exam: <?php echo htmlspecialchars($examInfo['marks_label']); ?>
                </span>
                <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded-full">
                  <?php echo htmlspecialchars($examInfo['type_label']); ?>
                </span>
                <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded-full">
                  Paper: <?php echo htmlspecialchars(hcc_paper_types()[$course['qpattern']] ?? $course['qpattern']); ?>
                </span>
              </div>
            </div>

            <!-- Three Status Chips Row -->
            <div class="flex flex-col space-y-2 pt-1 border-t border-slate-100">
              <?php foreach ($itemTypeConfig as $itemType => $config):
                $statusData = $statuses[$itemType] ?? ['status' => 'draft', 'submission_id' => null, 'version' => 0];
                $status = $statusData['status'] ?? 'draft';
                $submissionId = $statusData['submission_id'];
                $version = $statusData['version'] ?? 0;
                $sc = $statusConfig[$status] ?? $statusConfig['draft'];
                $canEdit = in_array($status, ['draft', 'hod_corrections', 'dean_corrections'], true);
                $isSubmitted = !in_array($status, ['draft'], true);
                $isApproved = $status === 'approved';
              ?>
                <div class="flex items-center justify-between gap-2 p-2 bg-slate-50 rounded-lg">
                  <!-- Status Badge -->
                  <div class="flex items-center gap-2 min-w-0 flex-1">
                    <i data-lucide="<?php echo $sc['icon']; ?>" class="w-3.5 h-3.5 <?php echo str_replace(['bg-','text-','border-'], '', $sc['class']); ?> shrink-0"></i>
                    <div class="min-w-0">
                      <span class="rounded-full px-2 py-0.5 text-[10px] font-black border <?php echo $sc['class']; ?> whitespace-nowrap">
                        <?php echo $config['label']; ?>
                      </span>
                      <span class="block rounded-full px-2 py-0.5 text-[9px] font-bold border <?php echo $sc['class']; ?> whitespace-nowrap truncate">
                        <?php echo $sc['label']; ?>
                        <?php if ($version > 0): ?>
                          <span class="ml-1 text-[8px] opacity-70">v<?php echo $version; ?></span>
                        <?php endif; ?>
                      </span>
                    </div>
                  </div>

                  <!-- Action Buttons -->
                  <div class="flex items-center gap-1.5 shrink-0">
                    <?php if ($itemType === 'question_bank'): ?>
                      <!-- Question Bank Actions -->
                      <?php if ($status === 'draft' && $submissionId): ?>
                        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_draft.php?submission_id=<?php echo $submissionId; ?>"
                           class="btn-orange-pill text-xs py-1 px-3" title="Continue Draft">
                          <i data-lucide="edit" class="w-3 h-3"></i>
                          <span>Continue Draft</span>
                        </a>
                      <?php elseif ($status === 'draft' && !$submissionId): ?>
                        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_draft.php?course_code=<?php echo urlencode($courseCode); ?>"
                           class="btn-orange-pill text-xs py-1 px-3" title="Start New Draft">
                          <i data-lucide="plus-circle" class="w-3 h-3"></i>
                          <span>Start Draft</span>
                        </a>
                      <?php elseif ($canEdit && $submissionId): ?>
                        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_draft.php?submission_id=<?php echo $submissionId; ?>&correct=1"
                           class="bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200 text-xs py-1 px-3 rounded-full font-bold transition" title="Resolve Corrections">
                          <i data-lucide="alert-circle" class="w-3 h-3"></i>
                          <span>Resolve</span>
                        </a>
                      <?php endif; ?>

                      <?php if ($isSubmitted || $isApproved): ?>
                        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_blueprint.php?course_code=<?php echo urlencode($courseCode); ?>&item_type=question_bank"
                           class="bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 text-xs py-1 px-3 rounded-full font-bold transition" title="View Blueprint">
                          <i data-lucide="layout-template" class="w-3 h-3 text-purple-600"></i>
                          <span>View Blueprint</span>
                        </a>
                      <?php endif; ?>

                    <?php elseif ($itemType === 'master_blueprint'): ?>
                      <!-- Master Blueprint Actions -->
                      <?php if ($isApproved && $submissionId): ?>
                        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_blueprint.php?course_code=<?php echo urlencode($courseCode); ?>&item_type=master_blueprint"
                           class="bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 text-xs py-1 px-3 rounded-full font-bold transition" title="View Master Blueprint">
                          <i data-lucide="layout-template" class="w-3 h-3 text-purple-600"></i>
                          <span>View Blueprint</span>
                        </a>
                      <?php elseif ($isSubmitted && !$isApproved): ?>
                        <span class="bg-slate-100 text-slate-400 text-xs py-1 px-3 rounded-full font-bold cursor-not-allowed" title="Pending HOD/Dean approval">
                          <i data-lucide="clock" class="w-3 h-3"></i>
                          <span>Pending</span>
                        </span>
                      <?php else: ?>
                        <span class="bg-slate-100 text-slate-400 text-xs py-1 px-3 rounded-full font-bold cursor-not-allowed" title="Submit Question Bank first">
                          <i data-lucide="lock" class="w-3 h-3"></i>
                          <span>Locked</span>
                        </span>
                      <?php endif; ?>

                    <?php elseif ($itemType === 'final_blueprint'): ?>
                      <!-- Final Blueprint Actions -->
                      <?php if ($isApproved && $submissionId): ?>
                        <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_blueprint.php?course_code=<?php echo urlencode($courseCode); ?>&item_type=final_blueprint"
                           class="bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 text-xs py-1 px-3 rounded-full font-bold transition" title="View Final Blueprint">
                          <i data-lucide="file-check" class="w-3 h-3 text-emerald-600"></i>
                          <span>View Blueprint</span>
                        </a>
                      <?php elseif ($isSubmitted && !$isApproved): ?>
                        <span class="bg-slate-100 text-slate-400 text-xs py-1 px-3 rounded-full font-bold cursor-not-allowed" title="Pending COE approval">
                          <i data-lucide="clock" class="w-3 h-3"></i>
                          <span>Pending</span>
                        </span>
                      <?php else: ?>
                        <span class="bg-slate-100 text-slate-400 text-xs py-1 px-3 rounded-full font-bold cursor-not-allowed" title="Master Blueprint must be approved first">
                          <i data-lucide="lock" class="w-3 h-3"></i>
                          <span>Locked</span>
                        </span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <!-- Quick Actions Footer -->
            <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
              <a href="<?php echo getBaseUrl(); ?>/modules/teaching/question_bank_draft.php?course_code=<?php echo urlencode($courseCode); ?>"
                 class="btn-orange-pill text-xs py-1.5 px-3.5">
                <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i>
                <span>Work on Question Bank</span>
              </a>
              <a href="<?php echo getBaseUrl(); ?>/modules/teaching/corrections.php?course_code=<?php echo urlencode($courseCode); ?>"
                 class="text-slate-600 hover:text-slate-900 text-xs font-bold px-3 py-1.5 rounded-full bg-slate-100 hover:bg-slate-200 transition">
                <i data-lucide="message-square" class="w-3 h-3 inline mr-1"></i>
                Corrections
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Legend / Help -->
  <div class="card-modern p-4 bg-slate-50 border-slate-200">
    <h4 class="font-bold text-slate-900 text-sm mb-2 flex items-center gap-1.5">
      <i data-lucide="info" class="w-4 h-4 text-orange-500"></i>
      Status Flow Guide
    </h4>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
      <div class="flex items-center gap-1.5 p-2 bg-white rounded border">
        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold border bg-slate-50 text-slate-600 border-slate-200">Draft</span>
        <span class="text-slate-600">Faculty editing</span>
      </div>
      <div class="flex items-center gap-1.5 p-2 bg-white rounded border">
        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold border bg-blue-50 text-blue-700 border-blue-200">Submitted</span>
        <span class="text-slate-600">Sent to HOD</span>
      </div>
      <div class="flex items-center gap-1.5 p-2 bg-white rounded border">
        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold border bg-rose-50 text-rose-700 border-rose-200">Corrections</span>
        <span class="text-slate-600">Faculty resolves</span>
      </div>
      <div class="flex items-center gap-1.5 p-2 bg-white rounded border">
        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold border bg-emerald-50 text-emerald-700 border-emerald-200">Approved</span>
        <span class="text-slate-600">Final version</span>
      </div>
    </div>
    <p class="text-xs text-slate-500 mt-3">
      Each course has <strong>three independent workflows</strong>: Question Bank → Master Blueprint → Final Blueprint.
      Master Blueprint unlocks after Question Bank is approved. Final Blueprint unlocks after Master Blueprint is approved.
    </p>
  </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>