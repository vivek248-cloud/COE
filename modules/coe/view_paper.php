<?php
/**
 * Official Question Paper View & Print Layout
 * Holy Cross College (Autonomous) - Examination System
 * Matching Holy Cross Format (U23BC3ALT05.pdf & Tss.pdf)
 */
define('PAGE_TITLE', 'Official Question Paper View');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';

requireAuth();
$pdo = getDBConnection();

$paperId = isset($_GET['paper_id']) ? intval($_GET['paper_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
$paper = null;

if ($paperId > 0) {
    try {
        $st = $pdo->prepare("SELECT gp.*, b.instructions as bp_instructions FROM generated_papers gp LEFT JOIN blueprints b ON b.id = gp.blueprint_id WHERE gp.id = ?");
        $st->execute([$paperId]);
        $paper = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

if (!$paper) {
    try {
        $st = $pdo->query("SELECT gp.*, b.instructions as bp_instructions FROM generated_papers gp LEFT JOIN blueprints b ON b.id = gp.blueprint_id ORDER BY gp.id DESC LIMIT 1");
        $paper = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
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
  header, nav, footer, .no-print, #qps-sidebar-drawer, #qps-global-loader { 
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

<main class="max-w-5xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6">

  <!-- Action Control Bar with Soft Corners & Crextio Style -->
  <div class="no-print bg-white rounded-3xl shadow-sm border border-stone-200/90 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div class="flex items-center space-x-3">
      <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php" class="bg-stone-100 hover:bg-stone-200 text-slate-800 text-xs font-bold px-4 py-2 rounded-full transition flex items-center space-x-1.5 shadow-xs">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>
        <span>Back to Shuffler</span>
      </a>
      <div>
        <span class="bg-[#1C1D21] text-amber-300 font-mono font-black text-xs px-3 py-1 rounded-full shadow-xs">
          <?php echo htmlspecialchars($paper['set_name'] ?? 'SET A'); ?>
        </span>
        <span class="text-xs font-black text-slate-900 ml-2">Paper #<?php echo $paper['id'] ?? 0; ?></span>
      </div>
    </div>

    <div class="flex items-center gap-2.5">
      <a href="<?php echo getBaseUrl(); ?>/api/export_docx.php?paper_id=<?php echo $paper['id'] ?? 0; ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black px-4 py-2.5 rounded-full shadow flex items-center space-x-1.5 transition">
        <i data-lucide="file-down" class="w-4 h-4 text-amber-300"></i>
        <span>Download Official DOCX</span>
      </a>

      <button onclick="window.print()" class="bg-[#1C1D21] hover:bg-slate-800 text-white text-xs font-black px-4 py-2.5 rounded-full shadow flex items-center space-x-1.5 transition">
        <i data-lucide="printer" class="w-4 h-4 text-amber-400"></i>
        <span>Print Question Paper</span>
      </button>
    </div>
  </div>

  <?php if (!$paper): ?>
    <div class="bg-white rounded-3xl p-12 text-center text-slate-500 border border-slate-200">
      <i data-lucide="file-question" class="w-12 h-12 mx-auto text-slate-400 mb-3"></i>
      <h3 class="font-bold text-base text-slate-800">No Generated Question Papers Found</h3>
      <p class="text-xs text-slate-500 mt-1">Please go to the Paper Shuffler to generate question paper sets.</p>
      <a href="<?php echo getBaseUrl(); ?>/modules/coe/shuffle.php" class="mt-4 inline-block bg-indigo-600 text-white text-xs font-bold px-4 py-2 rounded-xl">Go to Paper Shuffler</a>
    </div>
  <?php else: ?>

    <!-- Exact Holy Cross College Official Question Paper Sheet (Matching U23BC3ALT05.pdf) -->
    <div class="paper-page paper-font bg-white rounded-3xl shadow-xl border border-stone-300 p-8 sm:p-14 text-black space-y-6 leading-relaxed">

      <!-- Official College Examination Header -->
      <div class="text-center space-y-1">
        <h1 class="text-base sm:text-lg font-bold tracking-wide uppercase font-serif"><?php echo htmlspecialchars($collegeName); ?></h1>
        <h2 class="text-xs sm:text-sm font-bold uppercase"><?php echo htmlspecialchars($schoolName); ?></h2>
        <h3 class="text-xs sm:text-sm font-bold uppercase"><?php echo htmlspecialchars($degreeExamLine); ?></h3>
        <h4 class="text-xs sm:text-sm font-bold uppercase"><?php echo htmlspecialchars($coursePartLine); ?></h4>
        
        <div class="flex items-center justify-between pt-2">
          <div class="text-left font-bold text-xs uppercase font-serif"><?php echo htmlspecialchars($courseTitle); ?></div>
          <div class="text-right text-xs font-bold font-mono uppercase">Paper Code: <?php echo htmlspecialchars($courseCode); ?></div>
        </div>
      </div>

      <!-- Time & Max Marks Metadata Line -->
      <div class="flex items-center justify-between border-t border-b border-black py-1 text-xs font-bold">
        <div>Time: <?php echo htmlspecialchars($duration); ?></div>
        <div>Max. Marks: <?php echo htmlspecialchars($maxMarks); ?></div>
      </div>

      <!-- Sections & Questions Formatted Exactly as U23BC3ALT05.pdf -->
      <div class="space-y-6 pt-2">

        <?php 
        // Handle array of sections
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
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
