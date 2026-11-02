<?php
/**
 * Print Question Paper • Official Examination System
 * Holy Cross College (Autonomous) - Examination System
 * Matching Holy Cross Format (U23BC3ALT05.pdf & Tss.pdf)
 * Features:
 * - Course Code & Academic Year Filter
 * - Multi-Set Switching (SET A, SET B, SET C)
 * - Pagination across all generated examination papers
 * - Direct Print (window.print()) & Official Word (.DOCX) Export
 */
define('PAGE_TITLE', 'Print Question Paper');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';

requireAuth();
$pdo = getDBConnection();

$paperId = isset($_GET['paper_id']) ? intval($_GET['paper_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
$selectedCourse = strtoupper(trim((string)($_GET['course'] ?? '')));

// Load all distinct courses from generated papers for filtering
$courseList = [];
try {
    $stCourses = $pdo->query("SELECT DISTINCT paper_code, course_title FROM generated_papers WHERE paper_code IS NOT NULL AND paper_code != '' ORDER BY paper_code ASC");
    $courseList = $stCourses->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Pagination for generated papers list
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 8;
$offset = ($page - 1) * $perPage;

$whereClause = "WHERE 1=1";
$params = [];
if ($selectedCourse !== '') {
    $whereClause .= " AND UPPER(paper_code) = ?";
    $params[] = $selectedCourse;
}

$totalPapers = 0;
try {
    $stCount = $pdo->prepare("SELECT COUNT(*) FROM generated_papers $whereClause");
    $stCount->execute($params);
    $totalPapers = (int)$stCount->fetchColumn();
} catch (Throwable $e) {}
$totalPages = max(1, (int)ceil($totalPapers / $perPage));

$allPapers = [];
try {
    $stAll = $pdo->prepare("SELECT id, paper_code, course_title, semester, academic_year, set_name, total_marks, exam_date, created_at FROM generated_papers $whereClause ORDER BY id DESC LIMIT $perPage OFFSET $offset");
    $stAll->execute($params);
    $allPapers = $stAll->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Fetch active paper for rendering
$paper = null;
if ($paperId > 0) {
    try {
        $st = $pdo->prepare("SELECT gp.*, b.instructions as bp_instructions FROM generated_papers gp LEFT JOIN blueprints b ON b.id = gp.blueprint_id WHERE gp.id = ?");
        $st->execute([$paperId]);
        $paper = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

if (!$paper && !empty($allPapers)) {
    $firstId = (int)$allPapers[0]['id'];
    try {
        $st = $pdo->prepare("SELECT gp.*, b.instructions as bp_instructions FROM generated_papers gp LEFT JOIN blueprints b ON b.id = gp.blueprint_id WHERE gp.id = ?");
        $st->execute([$firstId]);
        $paper = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$paperData = [];
if ($paper && !empty($paper['paper_data_json'])) {
    $paperData = json_decode($paper['paper_data_json'], true) ?: [];
}

// Compute dynamic header fields
$collegeName = $paperData['institution'] ?? ($paperData['college_name'] ?? 'HOLY CROSS COLLEGE (AUTONOMOUS), TIRUCHIRAPPALLI – 620 002');
$schoolName = $paperData['school_name'] ?? hcc_school_name($paper['dept_code'] ?? '', $paper['dept_name'] ?? '');
$degreeExamLine = $paperData['degree_exam_line'] ?? hcc_degree_exam_line($paper['degree_level'] ?? 'UG', $paper['semester'] ?? 'Semester 1', $paper['exam_date'] ?? 'NOVEMBER 2026');
$coursePartLine = $paperData['course_part_line'] ?? hcc_course_part_line($paper['dept_code'] ?? '', $paper['paper_code'] ?? '', $paper['course_title'] ?? '');
$courseCode = strtoupper($paper['paper_code'] ?? ($paperData['paper_code'] ?? ''));
$courseTitle = $paper['course_title'] ?? ($paperData['course_title'] ?? '');
$duration = strtoupper($paper['duration_hours'] ?? ($paperData['duration_hours'] ?? '3 HOURS'));
$maxMarks = $paper['total_marks'] ?? ($paperData['total_marks'] ?? 75);
$rawSections = $paperData['sections'] ?? [];

// Find other sets for the same course / session
$siblingSets = [];
if ($paper && !empty($paper['paper_code'])) {
    try {
        $stSib = $pdo->prepare("SELECT id, set_name, paper_code, total_marks FROM generated_papers WHERE paper_code = ? AND semester = ? AND academic_year = ? ORDER BY set_name ASC, id ASC");
        $stSib->execute([$paper['paper_code'], $paper['semester'], $paper['academic_year']]);
        $siblingSets = $stSib->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<style>
@media print {
  @page {
    size: A4 portrait;
    margin: 12mm 15mm 12mm 15mm;
  }
  body { 
    background: white !important; 
    color: black !important; 
    font-family: 'Times New Roman', Times, serif !important; 
  }
  header, nav, footer, .no-print, #qps-sidebar-drawer, #qps-global-loader, .sidebar-link { 
    display: none !important; 
  }
  .paper-page { 
    box-shadow: none !important; 
    border: none !important; 
    margin: 0 !important; 
    width: 100% !important; 
    max-width: 100% !important; 
    padding: 0 !important; 
  }
}
.paper-font {
  font-family: 'Times New Roman', Times, serif;
}
</style>

<main class="max-w-6xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- ========================================================================= -->
  <!-- 1. TOP CONTROLS & COURSE FILTER BAR (No-Print)                            -->
  <!-- ========================================================================= -->
  <section class="no-print bg-white rounded-[28px] shadow-sm border border-stone-200/90 p-5 space-y-4">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-stone-100 pb-4">
      <div>
        <div class="inline-flex items-center gap-1.5 bg-orange-50 text-orange-700 border border-orange-200 rounded-full px-3 py-0.5 text-[10px] font-black uppercase tracking-wider">
          <i data-lucide="printer" class="w-3 h-3 text-[#FF5B00]"></i> EXAMINATION PRINT & DISPATCH
        </div>
        <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight mt-1.5">Print Question Paper</h1>
        <p class="text-xs text-slate-500">View, preview, and print official Holy Cross examination papers across multiple sets and course codes.</p>
      </div>

      <!-- Quick Action Buttons -->
      <div class="flex items-center gap-2">
        <?php if ($paper): ?>
          <a href="<?php echo getBaseUrl(); ?>/api/export_docx.php?paper_id=<?php echo $paper['id']; ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-4 py-2.5 rounded-full shadow flex items-center space-x-1.5 transition">
            <i data-lucide="file-down" class="w-4 h-4 text-amber-300"></i>
            <span>Export DOCX</span>
          </a>
          <button onclick="window.print()" class="bg-[#FF5B00] hover:bg-[#e05000] text-white text-xs font-black px-5 py-2.5 rounded-full shadow-lg shadow-orange-500/20 flex items-center space-x-1.5 transition">
            <i data-lucide="printer" class="w-4 h-4"></i>
            <span>Print Paper</span>
          </button>
        <?php endif; ?>
        <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php" class="bg-stone-100 hover:bg-stone-200 text-slate-700 text-xs font-bold px-4 py-2.5 rounded-full border border-stone-200 transition flex items-center space-x-1">
          <i data-lucide="shuffle" class="w-3.5 h-3.5"></i>
          <span>Shuffler</span>
        </a>
      </div>
    </div>

    <!-- Course Filter & Multi-Set Switcher -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-1">
      <form method="GET" class="flex flex-wrap items-center gap-2.5">
        <label class="text-xs font-bold text-slate-600">Filter Course:</label>
        <select name="course" onchange="this.form.submit()" class="bg-stone-50 border border-stone-300 rounded-full text-xs px-3.5 py-1.5 font-bold text-slate-800 shadow-sm focus:outline-none focus:ring-2 focus:ring-orange-500">
          <option value="">All Generated Courses</option>
          <?php foreach ($courseList as $c): ?>
            <option value="<?php echo htmlspecialchars($c['paper_code']); ?>" <?php echo ($selectedCourse === strtoupper($c['paper_code'])) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c['paper_code'] . ' — ' . $c['course_title']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>

      <!-- Sibling Sets Selector for Active Paper -->
      <?php if (!empty($siblingSets) && count($siblingSets) > 1): ?>
        <div class="flex items-center space-x-1.5 overflow-x-auto no-scrollbar py-1">
          <span class="text-xs font-bold text-slate-500 mr-1">Sets:</span>
          <?php foreach ($siblingSets as $s): ?>
            <a href="?paper_id=<?php echo $s['id']; ?><?php echo $selectedCourse ? '&course=' . urlencode($selectedCourse) : ''; ?>" class="px-3 py-1 rounded-full text-xs font-black transition <?php echo ($paper && (int)$paper['id'] === (int)$s['id']) ? 'bg-[#1C1D21] text-amber-300 shadow-sm' : 'bg-stone-100 text-slate-700 hover:bg-stone-200 border border-stone-200'; ?>">
              <?php echo htmlspecialchars($s['set_name'] ?: 'SET A'); ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ========================================================================= -->
  <!-- 2. GENERATED PAPERS PAGINATED LIST (No-Print)                             -->
  <!-- ========================================================================= -->
  <section class="no-print bg-white rounded-[28px] shadow-sm border border-stone-200/90 p-5 space-y-3">
    <div class="flex items-center justify-between border-b border-stone-100 pb-2.5">
      <h3 class="font-black text-slate-900 text-xs sm:text-sm uppercase tracking-wider flex items-center space-x-2">
        <i data-lucide="layers" class="w-4 h-4 text-orange-500"></i>
        <span>Generated Question Papers Archive (<?php echo $totalPapers; ?> Total)</span>
      </h3>
      <span class="text-[11px] font-bold text-slate-400">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
      <?php foreach ($allPapers as $p): ?>
        <?php $isActive = $paper && (int)$paper['id'] === (int)$p['id']; ?>
        <div class="p-3.5 rounded-2xl border transition flex flex-col justify-between <?php echo $isActive ? 'border-orange-500 bg-orange-50/30 ring-1 ring-orange-500 shadow-sm' : 'border-stone-200 bg-stone-50/50 hover:bg-white hover:shadow-md'; ?>">
          <div>
            <div class="flex items-start justify-between gap-1">
              <span class="font-mono font-black text-indigo-950 text-xs"><?php echo htmlspecialchars($p['paper_code']); ?></span>
              <span class="px-2 py-0.5 rounded-full font-mono font-black text-[10px] <?php echo $isActive ? 'bg-[#FF5B00] text-white' : 'bg-slate-200 text-slate-800'; ?>">
                <?php echo htmlspecialchars($p['set_name'] ?: 'SET A'); ?>
              </span>
            </div>
            <div class="font-bold text-slate-800 text-[11px] mt-1.5 line-clamp-1" title="<?php echo htmlspecialchars($p['course_title']); ?>">
              <?php echo htmlspecialchars($p['course_title']); ?>
            </div>
            <div class="text-[10px] text-slate-500 mt-1 flex items-center justify-between">
              <span><?php echo htmlspecialchars($p['semester']); ?></span>
              <span class="font-bold text-amber-900"><?php echo $p['total_marks']; ?>M</span>
            </div>
          </div>
          <div class="flex items-center justify-between mt-3 pt-2.5 border-t border-stone-200/60">
            <span class="font-mono text-[9px] text-slate-400">#<?php echo $p['id']; ?></span>
            <a href="?paper_id=<?php echo $p['id']; ?><?php echo $selectedCourse ? '&course=' . urlencode($selectedCourse) : ''; ?>" class="text-[10px] font-bold px-2.5 py-1 rounded-full <?php echo $isActive ? 'bg-orange-600 text-white font-black' : 'bg-stone-200 hover:bg-stone-300 text-slate-800'; ?> transition">
              <?php echo $isActive ? 'Active Sheet' : 'View & Print'; ?>
            </a>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($allPapers)): ?>
        <div class="col-span-full p-8 text-center text-slate-400 text-xs">
          No generated question papers found matching your criteria.
        </div>
      <?php endif; ?>
    </div>

    <!-- Pagination Controls -->
    <?php if ($totalPages > 1): ?>
      <div class="flex items-center justify-center space-x-1.5 pt-2">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a href="?p=<?php echo $i; ?><?php echo $selectedCourse ? '&course=' . urlencode($selectedCourse) : ''; ?><?php echo $paperId ? '&paper_id=' . $paperId : ''; ?>" class="w-7 h-7 rounded-full text-xs font-bold flex items-center justify-center transition <?php echo ($page === $i) ? 'bg-[#FF5B00] text-white shadow-sm' : 'bg-stone-100 text-slate-700 hover:bg-stone-200 border border-stone-200'; ?>">
            <?php echo $i; ?>
          </a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- ========================================================================= -->
  <!-- 3. OFFICIAL QUESTION PAPER PRINT SHEET (Holy Cross College Format)        -->
  <!-- ========================================================================= -->
  <?php if (!$paper): ?>
    <div class="bg-white rounded-3xl p-12 text-center text-slate-500 border border-slate-200">
      <i data-lucide="file-question" class="w-12 h-12 mx-auto text-slate-400 mb-3"></i>
      <h3 class="font-bold text-base text-slate-800">No Question Paper Selected</h3>
      <p class="text-xs text-slate-500 mt-1">Please select an examination paper set above or generate new sets in the Shuffler.</p>
      <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php" class="mt-4 inline-block bg-orange-600 hover:bg-orange-700 text-white text-xs font-bold px-5 py-2.5 rounded-full transition shadow">Go to Paper Shuffler</a>
    </div>
  <?php else: ?>

    <div class="paper-page paper-font bg-white rounded-3xl shadow-xl border border-stone-300 p-8 sm:p-14 text-black space-y-6 leading-relaxed">

      <!-- Official College Examination Header -->
      <div class="text-center space-y-1">
        <h1 class="text-base sm:text-lg font-bold tracking-wide uppercase font-serif"><?php echo htmlspecialchars($collegeName); ?></h1>
        <h2 class="text-xs sm:text-sm font-bold uppercase"><?php echo htmlspecialchars($schoolName); ?></h2>
        <h3 class="text-xs sm:text-sm font-bold uppercase"><?php echo htmlspecialchars($degreeExamLine); ?></h3>
        <h4 class="text-xs sm:text-sm font-bold uppercase"><?php echo htmlspecialchars($coursePartLine); ?></h4>
        
        <div class="flex items-center justify-between pt-2">
          <div class="text-left font-bold text-xs uppercase font-serif"><?php echo htmlspecialchars($courseTitle); ?></div>
          <div class="text-right text-xs font-bold font-mono uppercase">Paper Code: <?php echo htmlspecialchars($courseCode); ?> (<?php echo htmlspecialchars($paper['set_name'] ?: 'SET A'); ?>)</div>
        </div>
      </div>

      <!-- Time & Max Marks Metadata Line -->
      <div class="flex items-center justify-between border-t border-b border-black py-1 text-xs font-bold">
        <div>Time: <?php echo htmlspecialchars($duration); ?></div>
        <div>Max. Marks: <?php echo htmlspecialchars($maxMarks); ?></div>
      </div>

      <!-- Sections & Questions Formatted Exactly as Holy Cross College Official Exam -->
      <div class="space-y-6 pt-2">

        <?php 
        if (is_array($rawSections) && isset($rawSections[0])): 
          foreach ($rawSections as $sec):
        ?>
            <div class="space-y-3 pt-2">
              <div class="flex items-center justify-between border-b border-black pb-1 text-xs font-bold">
                <span class="text-sm font-extrabold tracking-wider uppercase"><?php echo htmlspecialchars($sec['section_name'] ?? ''); ?></span>
                <span class="font-bold"><?php echo htmlspecialchars($sec['choice_formula'] ?? ($sec['total_marks'] . ' Marks')); ?></span>
              </div>
              
              <?php if (!empty($sec['instruction'])): ?>
                <div class="text-xs font-bold text-black italic mb-2">
                  <?php echo htmlspecialchars($sec['instruction']); ?>
                </div>
              <?php endif; ?>

              <div class="space-y-2">
                <?php foreach (($sec['questions'] ?? []) as $q): ?>
                  <?php if (!empty($q['is_or_divider'])): ?>
                    <div class="text-center font-bold text-xs py-1 tracking-widest uppercase">
                      ( OR )
                    </div>
                  <?php else: ?>
                    <div class="text-xs flex items-start justify-between gap-4 pl-1">
                      <div class="font-medium flex-1 leading-relaxed">
                        <strong><?php echo htmlspecialchars($q['display_qno'] ?? $q['q_number']); ?>.</strong> 
                        <?php echo nl2br(htmlspecialchars($q['question_text'])); ?>
                        <?php if (!empty($q['image_url'])): ?>
                          <div class="my-2"><img src="<?php echo htmlspecialchars($q['image_url']); ?>" class="max-h-36 border border-slate-300 rounded" /></div>
                        <?php endif; ?>
                      </div>
                      <div class="font-bold text-[11px] whitespace-nowrap text-right font-serif pl-2">
                        [<?php echo htmlspecialchars($q['k_level'] ?? 'K1'); ?>] [<?php echo htmlspecialchars($q['co_level'] ?? 'CO1'); ?>]
                      </div>
                    </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      </div>

    </div>
  <?php endif; ?>

</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
  if (window.MathJax && window.MathJax.typesetPromise) {
    window.MathJax.typesetPromise().catch(e => console.warn(e));
  }
  if (window.lucide) {
    lucide.createIcons();
  }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
