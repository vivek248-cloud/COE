<?php
declare(strict_types=1);

define('PAGE_TITLE', 'Holy Cross 30-Question Paper Blueprint Matrix');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';

requireCOE();
$pdo = getDBConnection();

function bp_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function bp_json($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }

function bp_year_prev(string $year): string {
    if (preg_match('/^(\d{4})-(\d{4})$/', trim($year), $m)) {
        return ((int)$m[1]-1).'-'.((int)$m[2]-1);
    }
    return '';
}

// Fetch available courses
$courses = [];
try {
    $courses = $pdo->query("SELECT coursecode, coursetitle, dept_code FROM courses ORDER BY coursecode ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Fetch blueprints
$blueprints = [];
try {
    $blueprints = $pdo->query("SELECT id, name, paper_code, course_title, semester, academic_year, exam_type, total_marks, matrix_config FROM blueprints ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$selectedBlueprintId = (int)($_GET['blueprint_id'] ?? ($_POST['blueprint_id'] ?? 0));
$selectedPaper = strtoupper(trim((string)($_GET['paper_code'] ?? ($_POST['paper_code'] ?? ''))));

$activeBlueprint = null;
if ($selectedBlueprintId > 0) {
    foreach ($blueprints as $b) {
        if ((int)$b['id'] === $selectedBlueprintId) {
            $activeBlueprint = $b;
            if (!$selectedPaper && !empty($b['paper_code'])) {
                $selectedPaper = strtoupper(trim((string)$b['paper_code']));
            }
            break;
        }
    }
    if (!$activeBlueprint) {
        $selectedBlueprintId = 0;
        $selectedPaper = '';
    }
}

// A blueprint must be explicitly selected. The course/paper context is taken from that blueprint.
if ($selectedBlueprintId <= 0) {
    $selectedPaper = '';
}

$selectedSemester = trim((string)($_GET['semester'] ?? ($activeBlueprint['semester'] ?? 'Semester 1')));
$selectedYear = trim((string)($_GET['academic_year'] ?? ($activeBlueprint['academic_year'] ?? (defined('DEFAULT_ACADEMIC_YEAR') ? DEFAULT_ACADEMIC_YEAR : '2026-2027'))));
$selectedExam = trim((string)($_GET['exam_type'] ?? ($activeBlueprint['exam_type'] ?? 'Odd Semester End Examination')));

// Check previous year questions
$previousYear = bp_year_prev($selectedYear);
$previousYearQuestions = [];
if ($previousYear !== '' && $selectedPaper !== '') {
    try {
        $stPrev = $pdo->prepare("
            SELECT q.id, q.q_number, q.unit_no, q.sub_unit, q.k_level, q.section_type, q.question_text
            FROM questions q
            INNER JOIN question_banks qb ON qb.id = q.bank_id
            WHERE UPPER(qb.paper_code) = UPPER(?) AND (qb.academic_year = ? OR qb.academic_year = ?) AND (qb.semester = ? OR qb.semester = '')
        ");
        $stPrev->execute([$selectedPaper, $previousYear, $previousYear.' (Previous Year)', $selectedSemester]);
        $previousYearQuestions = $stPrev->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// Fetch the latest uploaded question bank for the selected course/context.
// This is only used by the optional "Load Questions from Question Bank" viewer;
// the existing blueprint matrix logic is intentionally left unchanged.
$availableBankQuestions = [];
$selectedQuestionBank = null;
try {
    if ($selectedPaper !== '' && $selectedBlueprintId > 0) {
        $stBankMeta = $pdo->prepare("
            SELECT id, paper_code, course_title, semester, academic_year, status, hod_status,
                   total_questions, source_file_name, source_format, updated_at, created_at, questions_json
            FROM question_banks
            WHERE UPPER(paper_code) = UPPER(?)
              AND academic_year = ?
              AND (semester = ? OR semester = '')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stBankMeta->execute([$selectedPaper, $selectedYear, $selectedSemester]);
        $selectedQuestionBank = $stBankMeta->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($selectedQuestionBank) {
            $stBankQuestions = $pdo->prepare("
                SELECT q.id, q.q_number, q.unit_no, q.sub_unit, q.k_level, q.section_type,
                       q.marks, q.co_level, q.question_type, q.options_json, q.question_text, q.language
                FROM questions q
                WHERE q.bank_id = ?
                ORDER BY CAST(q.unit_no AS UNSIGNED) ASC, q.sub_unit ASC, q.q_number ASC, q.id ASC
            ");
            $stBankQuestions->execute([(int)$selectedQuestionBank['id']]);
            $availableBankQuestions = $stBankQuestions->fetchAll(PDO::FETCH_ASSOC);

            // Some older/import-only banks may still have the complete question set
            // only inside questions_json. Use it as a read-only fallback.
            if (empty($availableBankQuestions) && !empty($selectedQuestionBank['questions_json'])) {
                $decodedBankQuestions = json_decode((string)$selectedQuestionBank['questions_json'], true);
                $fallbackQuestions = $decodedBankQuestions['questions'] ?? $decodedBankQuestions;
                if (is_array($fallbackQuestions)) {
                    foreach ($fallbackQuestions as $fq) {
                        if (!is_array($fq)) continue;
                        $availableBankQuestions[] = [
                            'id' => (int)($fq['id'] ?? 0),
                            'q_number' => $fq['q_number'] ?? '',
                            'unit_no' => $fq['unit_no'] ?? '',
                            'sub_unit' => $fq['sub_unit'] ?? '',
                            'k_level' => $fq['k_level'] ?? '',
                            'section_type' => $fq['section_type'] ?? '',
                            'marks' => $fq['marks'] ?? '',
                            'co_level' => $fq['co_level'] ?? '',
                            'question_type' => $fq['question_type'] ?? '',
                            'options_json' => $fq['options_json'] ?? '',
                            'question_text' => $fq['question_text'] ?? '',
                            'language' => $fq['language'] ?? ''
                        ];
                    }
                    usort($availableBankQuestions, static function(array $a, array $b): int {
                        $ua = (int)($a['unit_no'] ?? 0);
                        $ub = (int)($b['unit_no'] ?? 0);
                        if ($ua !== $ub) return $ua <=> $ub;
                        $sa = (string)($a['sub_unit'] ?? '');
                        $sb = (string)($b['sub_unit'] ?? '');
                        $cmp = strnatcasecmp($sa, $sb);
                        if ($cmp !== 0) return $cmp;
                        return ((int)($a['q_number'] ?? 0)) <=> ((int)($b['q_number'] ?? 0));
                    });
                }
            }
        }
    }
} catch (Throwable $e) {
    $availableBankQuestions = [];
    $selectedQuestionBank = null;
}

// Question-bank contract helpers used by both server-side save validation and the UI.
function bp_text_sig(string $text): string {
    $text = strip_tags($text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', trim($text));
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}
function bp_question_type(array $q): string {
    $t = strtoupper(trim((string)($q['question_type'] ?? '')));
    if ($t !== '') {
        return ['MC'=>'MCQ','MCQ'=>'MCQ','AR'=>'ASSERTION_REASON','ASSERTION'=>'ASSERTION_REASON','ASSERTION_REASON'=>'ASSERTION_REASON','MATCH'=>'MATCH','M'=>'MATCH','VSA'=>'VSA','PARA'=>'PARAGRAPH','PA'=>'PARAGRAPH','P'=>'PARAGRAPH','PARAGRAPH'=>'PARAGRAPH','ESSAY'=>'ESSAY','E'=>'ESSAY','COMP'=>'ESSAY'][$t] ?? $t;
    }
    $text = strtolower((string)($q['question_text'] ?? ''));
    $opts = trim((string)($q['options_json'] ?? ''));
    if ($opts !== '' && $opts !== '{}' && $opts !== '[]' && $opts !== 'null') return 'MCQ';
    if (stripos($text, 'assertion') !== false && stripos($text, 'reason') !== false) return 'ASSERTION_REASON';
    if (stripos($text, 'match the following') !== false || stripos($text, 'column a') !== false || stripos($text, 'column b') !== false) return 'MATCH';
    $marks = (int)($q['marks'] ?? 0);
    $sec = strtoupper(str_replace('SECTION-', '', (string)($q['section_type'] ?? '')));
    if ($marks >= 10 || $sec === 'C' || $sec === 'D') return 'ESSAY';
    if ($marks >= 5 || $sec === 'B') return 'PARAGRAPH';
    return 'VSA';
}
function bp_col_contract(string $col): array {
    $c = strtoupper(trim($col));
    if (strpos($c, 'MCQ_K1') !== false) return ['section'=>'A','k'=>['K1'],'type'=>'MCQ','marks'=>1];
    if (strpos($c, 'MCQ_K2') !== false) return ['section'=>'A','k'=>['K2'],'type'=>'MCQ','marks'=>1];
    if (strpos($c, 'MCQ_K3') !== false) return ['section'=>'A','k'=>['K3'],'type'=>'MCQ','marks'=>1];
    if (strpos($c, 'AR_K2') !== false) return ['section'=>'A','k'=>['K2'],'type'=>'ASSERTION_REASON','marks'=>1];
    if (strpos($c, 'MATCH_K1') !== false) return ['section'=>'A','k'=>['K1'],'type'=>'MATCH','marks'=>1];
    if (strpos($c, 'VSA_K1') !== false) return ['section'=>'A','k'=>['K1'],'type'=>'VSA','marks'=>2];
    if (strpos($c, 'VSA_K2') !== false) return ['section'=>'A','k'=>['K2'],'type'=>'VSA','marks'=>2];
    if (strpos($c, 'VSA_K3') !== false) return ['section'=>'A','k'=>['K3'],'type'=>'VSA','marks'=>2];
    if (strpos($c, 'VSA_K4') !== false) return ['section'=>'A','k'=>['K4'],'type'=>'VSA','marks'=>2];
    if (strpos($c, 'PARA_K15') !== false) return ['section'=>'B','k'=>['K1','K2','K3','K4','K5'],'type'=>'PARAGRAPH','marks'=>5];
    if (strpos($c, 'ESSAY_K14') !== false) return ['section'=>'C','k'=>['K1','K2','K3','K4'],'type'=>'ESSAY','marks'=>10];
    if (strpos($c, 'COMP_K5') !== false) return ['section'=>'D','k'=>['K5'],'type'=>'ESSAY','marks'=>10];
    return ['section'=>'','k'=>[],'type'=>'','marks'=>0];
}
function bp_bank_matches_cell(array $q, string $rowId, string $col): array {
    $contract = bp_col_contract($col);
    $errors = [];
    $sub = trim((string)($q['sub_unit'] ?? ''));
    $unit = trim((string)($q['unit_no'] ?? ''));
    $expectedUnit = strpos($rowId, '.') !== false ? explode('.', $rowId, 2)[0] : $rowId;
    if ($sub !== $rowId) $errors[] = "Sub-Unit mismatch: bank has {$sub}, cell is {$rowId}.";
    if ($unit !== '' && $unit !== $expectedUnit) $errors[] = "Unit mismatch: bank has Unit {$unit}, cell is Unit {$expectedUnit}.";
    $k = strtoupper(trim((string)($q['k_level'] ?? '')));
    if ($contract['k'] && !in_array($k, $contract['k'], true)) $errors[] = "K-Level mismatch: bank has {$k}, cell requires " . implode('/', $contract['k']) . ".";
    $type = bp_question_type($q);
    if ($contract['type'] && $type !== $contract['type']) $errors[] = "Question type mismatch: bank has {$type}, cell requires {$contract['type']}.";
    $marks = (int)($q['marks'] ?? 0);
    if ($contract['marks'] > 0 && $marks !== $contract['marks']) $errors[] = "Marks mismatch: bank has {$marks}M, cell requires {$contract['marks']}M.";
    $section = strtoupper(str_replace('SECTION-', '', (string)($q['section_type'] ?? '')));
    if ($contract['section'] && $section !== '' && $section !== $contract['section']) $errors[] = "Section mismatch: bank has Section {$section}, cell requires Section {$contract['section']}.";
    return $errors;
}

// Handle Save Matrix Action
$msg = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_master_matrix') {
    try {
        $matrixJson = trim((string)($_POST['matrix_data'] ?? ''));
        $matrixData = json_decode($matrixJson, true);
        if (!is_array($matrixData)) {
            throw new RuntimeException('Invalid matrix configuration data.');
        }

        // Validate server-side rules
        $errors = [];
        $assigned = [];
        $assignedBankQuestions = [];
        $bankByNumber = [];
        foreach ($availableBankQuestions as $bankQ) {
            $bankByNumber[(string)($bankQ['q_number'] ?? '')] = $bankQ;
        }
        $previousSignatures = [];
        foreach ($previousYearQuestions as $oldQ) {
            $sig = bp_text_sig((string)($oldQ['question_text'] ?? ''));
            if ($sig !== '') $previousSignatures[$sig] = true;
        }
        foreach ($matrixData as $cell) {
            $qNum = trim((string)($cell['q_num'] ?? ''));
            if ($qNum === '') continue;
            $col = strtoupper(trim((string)($cell['col'] ?? '')));
            $unit = trim((string)($cell['unit'] ?? ($cell['sub_unit'] ?? '')));

            if (!empty($availableBankQuestions)) {
                $bankQNum = trim((string)($cell['bank_q_num'] ?? ''));
                if ($bankQNum === '' || !isset($bankByNumber[$bankQNum])) {
                    $errors[] = "{$unit} / {$col}: a valid Question Bank question must be selected.";
                } else {
                    $bankQ = $bankByNumber[$bankQNum];
                    if (isset($assignedBankQuestions[$bankQNum])) {
                        $errors[] = "Question Bank Q{$bankQNum} is assigned more than once.";
                    }
                    $assignedBankQuestions[$bankQNum] = true;
                    foreach (bp_bank_matches_cell($bankQ, $unit, $col) as $bankError) {
                        $errors[] = "Bank Q{$bankQNum} at {$unit} / {$col}: {$bankError}";
                    }
                    $sig = bp_text_sig((string)($bankQ['question_text'] ?? ''));
                    if ($sig !== '' && isset($previousSignatures[$sig])) {
                        $errors[] = "Bank Q{$bankQNum} at {$unit} / {$col} was used in the previous academic year ({$previousYear}) and cannot be selected.";
                    }
                }
            }

            if (isset($assigned[$qNum])) {
                $errors[] = "Duplicate Question #{$qNum} assigned at {$unit} in {$col}.";
            }
            $assigned[$qNum] = true;

            if ($qNum === '9' && strpos($col, 'MATCH') === false) {
                $errors[] = "Question #9 MUST be assigned to MATCH (K1). Found in {$col}.";
            }
            if ($qNum === '10' && strpos($col, 'AR') === false) {
                $errors[] = "Question #10 MUST be assigned to AR (Assertion & Reason K2). Found in {$col}.";
            }
            if (is_numeric($qNum) && (int)$qNum >= 11 && (int)$qNum <= 20) {
                if (strpos($col, 'VSA') === false) {
                    $errors[] = "Question #{$qNum} MUST be assigned to Very Short Answer (VSA). Found in {$col}.";
                }
            }
            if (in_array($qNum, ['21.a','21.b','22.a','22.b','23.a','23.b','24.a','24.b','25.a','25.b'], true)) {
                if (strpos($col, 'PARA') === false) {
                    $errors[] = "Question #{$qNum} MUST be assigned to PARA (K1-K5). Found in {$col}.";
                }
            }
            if (in_array($qNum, ['26','27','28'], true)) {
                if (strpos($col, 'ESSAY') === false) {
                    $errors[] = "Question #{$qNum} MUST be assigned to ESSAY (K1-K4). Found in {$col}.";
                }
            }
            if (in_array($qNum, ['29','30'], true) && strpos($col, 'COMP') === false && strpos($col, 'ESSAY') === false) {
                if ($cell['is_compulsory'] ?? false) {
                    if (strpos($col, 'COMP') === false) {
                        $errors[] = "Question #{$qNum} MUST be assigned to COMP (K5). Found in {$col}.";
                    }
                }
            }
        }

        if (!empty($errors)) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $matrixPayload = [
            'grid_type' => '30_questions_tss',
            'paper_code' => $selectedPaper,
            'semester' => $selectedSemester,
            'academic_year' => $selectedYear,
            'exam_type' => $selectedExam,
            'matrix' => $matrixData,
            'rules' => [
                'section_a_match' => '9',
                'section_a_ar' => '10',
                'section_a_vsa' => '11-20',
                'section_b_para' => '21.a-25.b',
                'section_c_essay' => '26-28',
                'section_d_comp' => '29'
            ],
            'saved_at' => date('Y-m-d H:i:s')
        ];

        $encodedPayload = bp_json($matrixPayload);

        if ($selectedBlueprintId > 0) {
            $stUp = $pdo->prepare("UPDATE blueprints SET matrix_config = ?, updated_at = NOW() WHERE id = ?");
            $stUp->execute([$encodedPayload, $selectedBlueprintId]);
        } else {
            $stIns = $pdo->prepare("INSERT INTO blueprints (name, paper_code, semester, academic_year, exam_type, total_marks, duration_hours, matrix_config, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 75, '3 Hours', ?, 'COE_OFFICE', NOW(), NOW())");
            $stIns->execute(['Holy Cross 30-Question Paper Matrix (TSS.PDF)', $selectedPaper, $selectedSemester, $selectedYear, $selectedExam, $encodedPayload]);
            $selectedBlueprintId = (int)$pdo->lastInsertId();
        }

        // Store copy in storage
        try {
            $safePaper = preg_replace('/[^a-zA-Z0-9_-]/', '_', $selectedPaper);
            $semNum = preg_replace('/\D+/', '', $selectedSemester);
            $dir = BASE_PATH . '/storage/uploads/' . $safePaper . '/sem_' . $semNum . '/' . $selectedYear . '/BLUEPRINTS';
            @mkdir($dir, 0777, true);
            @file_put_contents($dir . '/matrix_tss_30q_' . date('Ymd_His') . '.json', $encodedPayload);
        } catch (Throwable $e) {}

        $msg = 'Master Blueprint Matrix saved & locked successfully.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Load existing matrix if saved
$savedMatrixConfig = [];
if ($activeBlueprint && !empty($activeBlueprint['matrix_config'])) {
    $decoded = json_decode((string)$activeBlueprint['matrix_config'], true);
    if (is_array($decoded) && isset($decoded['matrix'])) {
        $savedMatrixConfig = $decoded['matrix'];
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="max-w-full w-full px-3 sm:px-6 py-5 space-y-5">

  <!-- Top Context Controls Bar -->
  <section class="card-modern p-5 bg-white border border-slate-200/90 shadow-sm space-y-4">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-slate-100 pb-3">
      <div class="flex items-center space-x-3">
        <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 via-orange-500 to-rose-500 flex items-center justify-center text-white shadow-md shadow-orange-500/20 shrink-0">
          <i data-lucide="table" class="w-5 h-5"></i>
        </div>
        <div>
          <div class="flex items-center space-x-2">
            <h1 class="text-base sm:text-lg font-black text-slate-900 tracking-tight">COE Master Blueprint Matrix Workspace</h1>
            <span class="bg-orange-500/15 text-orange-700 border border-orange-400/30 text-[10px] font-black px-2.5 py-0.5 rounded-full uppercase font-mono">TSS.PDF OBE Matrix</span>
          </div>
          <p class="text-[11px] text-slate-500 mt-0.5">Continuous 30-Question Paper Blueprint: <strong>Q1-Q8 MCQ</strong>, <strong>Q9 MATCH (K1)</strong>, <strong>Q10 AR (K2)</strong>, <strong>Q11-Q20 VSA</strong>, <strong>Q21-Q25 Either/Or</strong>, <strong>Q26-Q28 Essay</strong>, <strong>Q29 Compulsory</strong>.</p>
        </div>
      </div>

      <!-- Quick Action Buttons including Auto-Pick 30 Questions -->
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" onclick="autoPick30Questions()" class="btn-orange-pill text-xs shadow-md shadow-orange-500/30 flex items-center space-x-1.5" title="Auto pick 30 questions incorporating Section D, excluding previous academic year questions">
          <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
          <span>Auto Pick 30 Questions</span>
        </button>
        <button type="button" onclick="loadCanonicalTSSTemplate()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 text-xs font-bold px-3.5 py-2 rounded-full transition flex items-center space-x-1.5 shadow-sm">
          <i data-lucide="wand-2" class="w-3.5 h-3.5"></i>
          <span>Standard TSS Pattern</span>
        </button>
        <button type="button" onclick="loadQuestionsFromQuestionBank()" <?php echo $selectedBlueprintId > 0 ? '' : 'disabled'; ?> class="bg-emerald-50 hover:bg-emerald-100 disabled:opacity-50 disabled:cursor-not-allowed text-emerald-800 border border-emerald-200 text-xs font-black px-3.5 py-2 rounded-full transition flex items-center space-x-1.5 shadow-sm" title="Load all questions from the uploaded question bank for this course">
          <i data-lucide="database" class="w-3.5 h-3.5"></i>
          <span>Load Questions from Question Bank</span>
        </button>
        <button type="button" onclick="clearMatrix()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold px-3.5 py-2 rounded-full transition">
          Clear All
        </button>
      </div>
    </div>

    <!-- Blueprint Selection -->
    <div class="rounded-2xl border border-orange-200 bg-orange-50/60 p-3.5">
      <div class="flex flex-col lg:flex-row lg:items-center gap-3">
        <div class="shrink-0">
          <div class="text-[10px] uppercase tracking-wider font-black text-orange-700">Blueprint Context</div>
          <div class="text-[11px] text-slate-600 mt-0.5">Select the exact blueprint before editing the matrix.</div>
        </div>
        <select id="sel_blueprint" onchange="onBlueprintChange()" class="flex-1 border border-orange-300 rounded-2xl p-2.5 bg-white font-black text-slate-800 shadow-sm focus:ring-2 focus:ring-orange-500">
          <option value="">— Select Blueprint —</option>
          <?php foreach ($blueprints as $bp): ?>
            <option value="<?php echo (int)$bp['id']; ?>" <?php echo $selectedBlueprintId === (int)$bp['id'] ? 'selected' : ''; ?>>
              #<?php echo (int)$bp['id']; ?> • <?php echo bp_h($bp['name']); ?> • <?php echo bp_h($bp['paper_code'] ?: 'No course'); ?> • <?php echo bp_h($bp['academic_year']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($selectedBlueprintId > 0): ?>
          <span class="bg-emerald-100 text-emerald-800 border border-emerald-200 rounded-full px-3 py-1.5 text-[10px] font-black whitespace-nowrap">✓ Blueprint Selected</span>
        <?php else: ?>
          <span class="bg-rose-100 text-rose-800 border border-rose-200 rounded-full px-3 py-1.5 text-[10px] font-black whitespace-nowrap">Select Required</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Parameter Filter Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
      <div>
        <label class="block font-bold text-slate-700 mb-1">Course / Paper Code *</label>
        <select id="sel_paper" onchange="onContextChange()" <?php echo $selectedBlueprintId > 0 ? "" : "disabled"; ?> class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-bold text-slate-800 shadow-sm focus:ring-2 focus:ring-orange-500">
          <?php if ($selectedBlueprintId <= 0): ?><option value="">Select a blueprint first</option><?php endif; ?>
          <?php foreach ($courses as $c): ?>
            <option value="<?php echo bp_h($c['coursecode']); ?>" <?php echo $selectedPaper === strtoupper($c['coursecode']) ? 'selected' : ''; ?>>
              <?php echo bp_h($c['coursecode'] . ' — ' . $c['coursetitle']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block font-bold text-slate-700 mb-1">Semester</label>
        <select id="sel_semester" onchange="onContextChange()" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-bold text-slate-800 shadow-sm focus:ring-2 focus:ring-orange-500">
          <?php for ($i = 1; $i <= 8; $i++): ?>
            <option value="Semester <?php echo $i; ?>" <?php echo $selectedSemester === "Semester $i" ? 'selected' : ''; ?>>Semester <?php echo $i; ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div>
        <label class="block font-bold text-slate-700 mb-1">Academic Year</label>
        <input type="text" id="sel_year" value="<?php echo bp_h($selectedYear); ?>" onchange="onContextChange()" class="w-full border border-stone-300 rounded-2xl p-2.5 bg-stone-50 font-bold text-slate-800 shadow-sm focus:ring-2 focus:ring-orange-500">
      </div>

      <div>
        <label class="block font-bold text-slate-700 mb-1">Previous Year Validation</label>
        <div class="w-full border border-stone-300 rounded-2xl p-2.5 bg-amber-50/70 text-amber-950 font-bold flex items-center justify-between">
          <span class="truncate"><?php echo $previousYear ? bp_h($previousYear) . ' (Excluded)' : 'No previous year'; ?></span>
          <span class="bg-amber-200 text-amber-950 text-[10px] font-black px-2 py-0.5 rounded-full"><?php echo count($previousYearQuestions); ?> Excluded Qs</span>
        </div>
      </div>
    </div>
  </section>

  <!-- Notification Banner -->
  <?php if ($msg): ?>
    <div class="card-modern p-4 bg-emerald-50 border-emerald-200 text-emerald-800 text-xs font-bold flex items-center space-x-2">
      <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600 shrink-0"></i>
      <span><?php echo bp_h($msg); ?></span>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="card-modern p-4 bg-rose-50 border-rose-200 text-rose-800 text-xs font-bold flex items-center space-x-2">
      <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600 shrink-0"></i>
      <span><?php echo bp_h($error); ?></span>
    </div>
  <?php endif; ?>

  <?php if ($selectedBlueprintId <= 0): ?>
    <section class="card-modern bg-white p-10 border border-rose-200 shadow-sm text-center">
      <div class="mx-auto w-14 h-14 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center"><i data-lucide="file-question" class="w-7 h-7"></i></div>
      <h2 class="font-black text-lg text-slate-900 mt-4">No Blueprint Selected</h2>
      <p class="text-xs text-slate-500 mt-1 max-w-xl mx-auto">Choose a blueprint from <b>Blueprint Context</b> above. The table and its saved selections will load only for that blueprint.</p>
    </section>
  <?php endif; ?>

  <!-- Uploaded Question Bank Viewer (read-only; does not alter the existing matrix) -->
  <section id="questionBankViewer" class="hidden card-modern bg-white border border-emerald-200 shadow-sm overflow-hidden">
    <div class="p-4 sm:p-5 bg-gradient-to-r from-emerald-50 via-white to-teal-50 border-b border-emerald-100">
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <div class="flex items-start gap-3">
          <div class="w-10 h-10 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
            <i data-lucide="database" class="w-5 h-5"></i>
          </div>
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-sm sm:text-base font-black text-slate-900">Uploaded Question Bank</h2>
              <span id="qbLoadedBadge" class="bg-emerald-100 text-emerald-800 border border-emerald-200 text-[10px] font-black px-2.5 py-1 rounded-full">Loaded</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1">All questions are shown exactly from the uploaded bank for the selected course. This viewer does not change the blueprint matrix.</p>
          </div>
        </div>
        <button type="button" onclick="closeQuestionBankViewer()" class="self-start lg:self-auto px-3.5 py-2 rounded-full bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 text-xs font-black flex items-center gap-1.5">
          <i data-lucide="x" class="w-3.5 h-3.5"></i>
          Close
        </button>
      </div>

      <div id="questionBankMeta" class="grid grid-cols-2 md:grid-cols-4 gap-2.5 mt-4"></div>
    </div>

    <div class="p-4 sm:p-5 space-y-4">
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-2">
        <div>
          <h3 class="text-xs font-black uppercase tracking-wider text-slate-800">Units &amp; Sub-Units</h3>
          <p class="text-[10px] text-slate-500 mt-0.5">The unit/sub-unit list below is generated from the uploaded question bank, not from the fixed blueprint rows.</p>
        </div>
        <input id="questionBankSearch" type="search" oninput="filterQuestionBankViewer()" placeholder="Search question number or question text..." class="w-full md:w-80 border border-slate-200 rounded-2xl px-3 py-2 text-xs font-medium focus:ring-2 focus:ring-emerald-500 focus:border-emerald-400">
      </div>
      <div id="questionBankContent" class="space-y-4"></div>
    </div>
  </section>

  <!-- Visual Question Palette Tracker -->
  <section class="card-modern bg-white p-4 border border-slate-200/90 shadow-sm space-y-3 <?php echo $selectedBlueprintId > 0 ? '' : 'opacity-40 pointer-events-none'; ?>">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-2.5">
      <div class="flex items-center space-x-2">
        <i data-lucide="list-checks" class="w-4 h-4 text-orange-500"></i>
        <h3 class="font-black text-xs uppercase tracking-wider text-slate-800">30-Question Slot Tracker</h3>
      </div>
      <div class="flex items-center space-x-3 text-xs">
        <span id="trackerProgressText" class="font-black text-slate-800 font-mono">0 / 30 Slots Assigned</span>
        <div class="w-28 h-2 bg-slate-100 rounded-full overflow-hidden border border-slate-200">
          <div id="trackerProgressBar" class="h-full bg-gradient-to-r from-orange-500 to-amber-500 transition-all duration-300" style="width: 0%;"></div>
        </div>
      </div>
    </div>

    <!-- Question Number Pills -->
    <div class="flex flex-wrap gap-1.5 text-xs" id="questionSlotPalette">
      <!-- Injected by JavaScript -->
    </div>
  </section>

  <!-- Live Validation Error Diagnostic Banner (Hidden by default) -->
  <div id="matrixValidationAlert" class="hidden card-modern p-4 bg-rose-50 border border-rose-300 text-rose-900 text-xs shadow-md space-y-1.5">
    <div class="flex items-center space-x-2 font-black text-rose-700">
      <i data-lucide="alert-triangle" class="w-4 h-4"></i>
      <span>Matrix Validation Shortage / Rule Violation</span>
    </div>
    <div id="matrixValidationDetails" class="text-[11px] font-medium leading-relaxed pl-6">
      <!-- Injected errors -->
    </div>
  </div>

  <!-- THE MASTER 30-QUESTION PAPER BLUEPRINT MATRIX TABLE -->
  <section class="card-modern bg-white p-5 border border-slate-200 shadow-md space-y-3 <?php echo $selectedBlueprintId > 0 ? "" : "opacity-40 pointer-events-none"; ?>">
    
    <!-- Table Header Info Bar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-slate-100 pb-3">
      <div class="flex items-center space-x-2">
        <span class="w-2.5 h-2.5 rounded-full bg-sky-500 animate-pulse"></span>
        <h2 class="text-xs sm:text-sm font-black uppercase tracking-wider text-slate-900 font-serif">
          HOLY CROSS 30-QUESTION PAPER BLUEPRINT MATRIX (TSS.PDF)
        </h2>
      </div>
      <p class="text-[10px] text-slate-500">
        Click any cell to assign a blueprint slot and then pick the actual Question Bank question. Every cell shows its required marks; invalid Unit/Sub-Unit, Section, K-Level, type, marks or previous-year selections are blocked.
      </p>
    </div>

    <!-- Scrollable Matrix Table Container -->
    <div class="overflow-x-auto custom-scrollbar-x rounded-2xl border border-slate-200">
      <table class="w-full text-center border-collapse text-xs select-none" id="blueprintMatrixTable">
        
        <!-- Table Column Headers -->
        <thead>
          <tr class="bg-slate-950 text-white uppercase text-[10px] font-black tracking-wider">
            <th class="p-2.5 border-r border-slate-800 text-left min-w-[110px] bg-black">UNIT / SUB-UNIT</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]">MCQ (K1)</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]">MCQ (K2)</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]">MCQ (K3)</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px] bg-slate-900 text-amber-300" title="Assertion & Reason (Q10)">AR (K2)</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px] bg-slate-900 text-emerald-300" title="Match the Following (Q9)">MATCH (K1)</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]">VSA (K1)</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]">VSA (K2)</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]">VSA (K3)</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]">VSA (K4)</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[80px] bg-[#451A03] text-amber-200">PARA (K1-K5)</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[80px] bg-[#064E3B] text-emerald-200">ESSAY (K1-K4)</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[70px] bg-[#3B0764] text-purple-200">COMP (K5)</th>
            <th class="p-2.5 bg-black text-amber-400 min-w-[55px]">TOTAL</th>
          </tr>
        </thead>

        <!-- Table Rows are generated from the currently loaded question bank. -->
        <tbody class="divide-y divide-slate-100 font-medium" id="matrixTableBody">
          <!-- Populated dynamically via JS -->
        </tbody>
      </table>
    </div>

    <div id="matrixBankSummary" class="mt-3 rounded-2xl border border-indigo-200 bg-indigo-50/70 px-3 py-2 text-[10px] font-bold text-indigo-900">Loading Question Bank Units / Sub-Units…</div>

    <!-- Bottom Action Bar (Reset / Cancel & Save and Lock) -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-3 border-t border-slate-100">
      <button type="button" onclick="clearMatrix()" class="px-5 py-2.5 rounded-full border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs transition">
        Reset / Cancel
      </button>

      <div class="flex items-center space-x-3">
        <button type="button" onclick="validateEntireMatrix(true)" class="px-5 py-2.5 rounded-full border border-orange-300 bg-orange-50 hover:bg-orange-100 text-orange-950 font-bold text-xs transition">
          🔍 Validate Rules
        </button>
        <button type="button" onclick="submitMatrixSave()" id="saveMasterBlueprintBtn" class="bg-[#F6C443] hover:bg-[#EAB326] text-slate-950 font-black px-7 py-3 rounded-full shadow-lg flex items-center space-x-2 transition text-xs">
          <i data-lucide="check-circle" class="w-4 h-4"></i>
          <span>Save & Lock Master Blueprint</span>
        </button>
      </div>
    </div>

  </section>

</main>

<!-- Hidden POST form for Save -->
<form id="saveMatrixForm" method="POST" action="">
  <input type="hidden" name="action" value="save_master_matrix">
  <input type="hidden" name="blueprint_id" value="<?php echo $selectedBlueprintId; ?>">
  <input type="hidden" name="paper_code" id="post_paper_code" value="<?php echo bp_h($selectedPaper); ?>">
  <input type="hidden" name="semester" id="post_semester" value="<?php echo bp_h($selectedSemester); ?>">
  <input type="hidden" name="academic_year" id="post_academic_year" value="<?php echo bp_h($selectedYear); ?>">
  <input type="hidden" name="exam_type" id="post_exam_type" value="<?php echo bp_h($selectedExam); ?>">
  <input type="hidden" name="matrix_data" id="post_matrix_data" value="">
</form>

<!-- Modal: Cell Question Number Editor (matching image-1.png) -->
<div id="cellEditModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-[28px] max-w-sm w-full p-6 shadow-2xl space-y-4 border border-stone-200">
    <div class="flex items-center justify-between border-b border-stone-100 pb-3">
      <div>
        <h3 class="font-black text-slate-900 text-sm" id="modalCellTitle">Assign Question Slot</h3>
        <p class="text-[11px] text-slate-500" id="modalCellSubtitle">Unit I (1.1) • MCQ (K1)</p>
      </div>
      <button onclick="closeCellModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-stone-100"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>

    <div class="space-y-3">
      <div>
        <label class="block text-xs font-bold text-slate-700 mb-1">Question Number / Label</label>
        <input type="text" id="modalCellInput" placeholder="e.g. 1, 9, 10, 21.a, 26, 29" class="w-full border border-stone-300 rounded-2xl p-2.5 font-mono font-black text-sm text-center bg-stone-50 focus:bg-white focus:ring-2 focus:ring-orange-500">
      </div>

      <div>
        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Assign Slot</label>
        <div class="flex flex-wrap gap-1.5 text-xs" id="modalQuickPills">
          <!-- Canonical blueprint slot chips -->
        </div>
      </div>

      <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-3">
        <div class="flex items-center justify-between gap-2 mb-2">
          <label class="block text-[10px] font-black text-emerald-900 uppercase tracking-wider">Eligible Questions from Question Bank</label>
          <span id="modalEligibleCount" class="text-[9px] font-black text-emerald-700">0 found</span>
        </div>
        <p class="text-[9px] text-emerald-800 mb-2">Filtered by Unit/Sub-Unit, Section, K-Level, question type and exact marks. Previous-year questions are excluded. Select a bank question, then select the blueprint slot above.</p>
        <div class="max-h-40 overflow-y-auto custom-scrollbar space-y-1.5" id="modalEligibleQuestions"></div>
      </div>
    </div>

    <div class="flex justify-between items-center pt-3 border-t border-stone-100">
      <button onclick="clearCurrentModalCell()" class="text-xs font-bold text-rose-600 hover:underline">Clear Cell</button>
      <div class="flex space-x-2">
        <button onclick="closeCellModal()" class="px-4 py-2 text-xs font-bold text-slate-700 bg-stone-100 hover:bg-stone-200 rounded-full">Cancel</button>
        <button onclick="applyCellModalAssignment()" class="px-5 py-2 text-xs font-black text-white bg-orange-500 hover:bg-orange-600 rounded-full shadow-md">Apply</button>
      </div>
    </div>
  </div>
</div>

<script>
// Matrix rows are populated from the loaded question bank (with a standard fallback when no bank is loaded).
let MATRIX_ROWS = [];

// 12 Question Type Columns
const MATRIX_COLS = [
  { key: 'MCQ_K1', label: 'MCQ (K1)', section: 'A', k: 'K1', type: 'MCQ' },
  { key: 'MCQ_K2', label: 'MCQ (K2)', section: 'A', k: 'K2', type: 'MCQ' },
  { key: 'MCQ_K3', label: 'MCQ (K3)', section: 'A', k: 'K3', type: 'MCQ' },
  { key: 'AR_K2', label: 'AR (K2)', section: 'A', k: 'K2', type: 'AR' },
  { key: 'MATCH_K1', label: 'MATCH (K1)', section: 'A', k: 'K1', type: 'MATCH' },
  { key: 'VSA_K1', label: 'VSA (K1)', section: 'A', k: 'K1', type: 'VSA' },
  { key: 'VSA_K2', label: 'VSA (K2)', section: 'A', k: 'K2', type: 'VSA' },
  { key: 'VSA_K3', label: 'VSA (K3)', section: 'A', k: 'K3', type: 'VSA' },
  { key: 'VSA_K4', label: 'VSA (K4)', section: 'A', k: 'K4', type: 'VSA' },
  { key: 'PARA_K15', label: 'PARA (K1-K5)', section: 'B', k: 'K1-K5', type: 'PARA' },
  { key: 'ESSAY_K14', label: 'ESSAY (K1-K4)', section: 'C', k: 'K1-K4', type: 'ESSAY' },
  { key: 'COMP_K5', label: 'COMP (K5)', section: 'D', k: 'K5', type: 'COMP' }
];

// All 30 Canonical Question Slot Labels
const ALL_QUESTION_SLOTS = [
  { num: '1', sec: 'A', type: 'MCQ (K1-K3)' },
  { num: '2', sec: 'A', type: 'MCQ (K1-K3)' },
  { num: '3', sec: 'A', type: 'MCQ (K1-K3)' },
  { num: '4', sec: 'A', type: 'MCQ (K1-K3)' },
  { num: '5', sec: 'A', type: 'MCQ (K1-K3)' },
  { num: '6', sec: 'A', type: 'MCQ (K1-K3)' },
  { num: '7', sec: 'A', type: 'MCQ (K1-K3)' },
  { num: '8', sec: 'A', type: 'MCQ (K1-K3)' },
  { num: '9', sec: 'A', type: 'MATCH (K1)' },
  { num: '10', sec: 'A', type: 'AR (K2)' },
  { num: '11', sec: 'A', type: 'VSA (K1-K4)' },
  { num: '12', sec: 'A', type: 'VSA (K1-K4)' },
  { num: '13', sec: 'A', type: 'VSA (K1-K4)' },
  { num: '14', sec: 'A', type: 'VSA (K1-K4)' },
  { num: '15', sec: 'A', type: 'VSA (K1-K4)' },
  { num: '16', sec: 'A', type: 'VSA (K1-K4)' },
  { num: '17', sec: 'A', type: 'VSA (K1-K4)' },
  { num: '18', sec: 'A', type: 'VSA (K1-K4)' },
  { num: '19', sec: 'A', type: 'VSA (K1-K4)' },
  { num: '20', sec: 'A', type: 'VSA (K1-K4)' },
  { num: '21.a', sec: 'B', type: 'PARA (5M)' },
  { num: '21.b', sec: 'B', type: 'PARA (5M)' },
  { num: '22.a', sec: 'B', type: 'PARA (5M)' },
  { num: '22.b', sec: 'B', type: 'PARA (5M)' },
  { num: '23.a', sec: 'B', type: 'PARA (5M)' },
  { num: '23.b', sec: 'B', type: 'PARA (5M)' },
  { num: '24.a', sec: 'B', type: 'PARA (5M)' },
  { num: '24.b', sec: 'B', type: 'PARA (5M)' },
  { num: '25.a', sec: 'B', type: 'PARA (5M)' },
  { num: '25.b', sec: 'B', type: 'PARA (5M)' },
  { num: '26', sec: 'C', type: 'ESSAY (10M)' },
  { num: '27', sec: 'C', type: 'ESSAY (10M)' },
  { num: '28', sec: 'C', type: 'ESSAY (10M)' },
  { num: '29', sec: 'D', type: 'COMPULSORY (10M)' }
];

// In-Memory Matrix State: Map of key `${rowId}_${colKey}` -> question_number string
let matrixState = {};
// Optional mapping from blueprint slot -> actual question-bank question number.
// Existing matrix validation/save rules continue to use matrixState unchanged.
let bankQuestionState = {};
let activeModalRow = null;
let activeModalCol = null;
let activeModalBankQuestion = null;

// Initial saved data from PHP
const INITIAL_SAVED = <?php echo json_encode($savedMatrixConfig); ?>;

// Read-only question-bank data loaded from the selected course's latest uploaded bank.
const QUESTION_BANK_DATA = <?php echo bp_json($availableBankQuestions); ?>;
const QUESTION_BANK_META = <?php echo bp_json($selectedQuestionBank ? [
    'id' => (int)($selectedQuestionBank['id'] ?? 0),
    'paper_code' => (string)($selectedQuestionBank['paper_code'] ?? $selectedPaper),
    'course_title' => (string)($selectedQuestionBank['course_title'] ?? ''),
    'semester' => (string)($selectedQuestionBank['semester'] ?? $selectedSemester),
    'academic_year' => (string)($selectedQuestionBank['academic_year'] ?? $selectedYear),
    'status' => (string)($selectedQuestionBank['status'] ?? ''),
    'hod_status' => (string)($selectedQuestionBank['hod_status'] ?? ''),
    'total_questions' => (int)($selectedQuestionBank['total_questions'] ?? 0),
    'source_file_name' => (string)($selectedQuestionBank['source_file_name'] ?? ''),
    'source_format' => (string)($selectedQuestionBank['source_format'] ?? '')
] : null); ?>;

const PREVIOUS_YEAR_DATA = <?php echo bp_json($previousYearQuestions); ?>;

function normalizeMatrixText(value) {
  return String(value ?? '').replace(/<[^>]*>/g, ' ').replace(/[^\p{L}\p{N}]+/gu, ' ').trim().toLowerCase();
}

function isPreviousYearQuestion(question) {
  if (!question || !Array.isArray(PREVIOUS_YEAR_DATA) || !PREVIOUS_YEAR_DATA.length) return false;
  const sig = normalizeMatrixText(question.question_text);
  if (!sig) return false;
  return PREVIOUS_YEAR_DATA.some(oldQ => normalizeMatrixText(oldQ.question_text) === sig);
}

function buildMatrixRowsFromQuestionBank(data) {
  const rows = [];
  const seen = new Set();
  (Array.isArray(data) ? data : []).forEach(q => {
    const unit = String(q.unit_no ?? '').trim();
    const sub = String(q.sub_unit ?? '').trim();
    if (!unit || !sub) return;
    const key = `${unit}.${sub}`;
    if (seen.has(key)) return;
    seen.add(key);
    rows.push({ id: sub, unit, sub, label: `Unit ${unit} (${sub})` });
  });
  rows.sort((a,b) => {
    const au = Number(a.unit), bu = Number(b.unit);
    if (Number.isFinite(au) && Number.isFinite(bu) && au !== bu) return au - bu;
    return a.sub.localeCompare(b.sub, undefined, {numeric:true});
  });
  return rows;
}

const QUESTION_BANK_STATS = (() => {
  const data = Array.isArray(QUESTION_BANK_DATA) ? QUESTION_BANK_DATA : [];
  const units = new Set(data.map(q => String(q.unit_no ?? '').trim()).filter(Boolean));
  const subs = new Set(data.map(q => String(q.sub_unit ?? '').trim()).filter(Boolean));
  return { total: data.length, units: units.size, subUnits: subs.size };
})();

MATRIX_ROWS = buildMatrixRowsFromQuestionBank(QUESTION_BANK_DATA);
if (!MATRIX_ROWS.length) {
  MATRIX_ROWS = [
    {id:'1.1',unit:'1',sub:'1.1',label:'Unit I (1.1)'},{id:'1.2',unit:'1',sub:'1.2',label:'Unit I (1.2)'},{id:'1.3',unit:'1',sub:'1.3',label:'Unit I (1.3)'},
    {id:'2.1',unit:'2',sub:'2.1',label:'Unit II (2.1)'},{id:'2.2',unit:'2',sub:'2.2',label:'Unit II (2.2)'},{id:'2.3',unit:'2',sub:'2.3',label:'Unit II (2.3)'},
    {id:'3.1',unit:'3',sub:'3.1',label:'Unit III (3.1)'},{id:'3.2',unit:'3',sub:'3.2',label:'Unit III (3.2)'},{id:'3.3',unit:'3',sub:'3.3',label:'Unit III (3.3)'},
    {id:'4.1',unit:'4',sub:'4.1',label:'Unit IV (4.1)'},{id:'4.2',unit:'4',sub:'4.2',label:'Unit IV (4.2)'},{id:'4.3',unit:'4',sub:'4.3',label:'Unit IV (4.3)'},
    {id:'5.1',unit:'5',sub:'5.1',label:'Unit V (5.1)'},{id:'5.2',unit:'5',sub:'5.2',label:'Unit V (5.2)'},{id:'5.3',unit:'5',sub:'5.3',label:'Unit V (5.3)'}
  ];
}

function initMatrixState() {
  matrixState = {};
  bankQuestionState = {};
  if (INITIAL_SAVED && Array.isArray(INITIAL_SAVED) && INITIAL_SAVED.length > 0) {
    INITIAL_SAVED.forEach(c => {
      if (c.row && c.col && c.q_num) {
        const key = `${c.row}_${c.col}`;
        matrixState[key] = String(c.q_num).trim();
        if (c.bank_q_num != null && String(c.bank_q_num).trim() !== '') bankQuestionState[key] = String(c.bank_q_num).trim();
      }
    });
  } else {
    // Default to Canonical TSS Layout (Screenshot 2026-10-01 113714.png with Q9 MATCH & Q10 AR)
    loadCanonicalTSSTemplate(false);
  }
  renderMatrixTable();
  updateQuestionSlotPalette();
}

function escapeQuestionBankHtml(value) {
  const div = document.createElement('div');
  div.textContent = value == null ? '' : String(value);
  return div.innerHTML;
}

function normalizeQuestionBankUnit(question) {
  const unit = String(question.unit_no ?? '').trim();
  return unit !== '' ? unit : 'Unassigned';
}

function normalizeQuestionBankSubUnit(question) {
  const sub = String(question.sub_unit ?? '').trim();
  return sub !== '' ? sub : 'No sub-unit';
}

function getQuestionBankGroupedData() {
  const groups = {};
  (Array.isArray(QUESTION_BANK_DATA) ? QUESTION_BANK_DATA : []).forEach((q) => {
    const unit = normalizeQuestionBankUnit(q);
    const sub = normalizeQuestionBankSubUnit(q);
    if (!groups[unit]) groups[unit] = {};
    if (!groups[unit][sub]) groups[unit][sub] = [];
    groups[unit][sub].push(q);
  });
  return groups;
}

function renderQuestionBankViewer(searchTerm = '') {
  const content = document.getElementById('questionBankContent');
  if (!content) return;

  const term = String(searchTerm || '').trim().toLowerCase();
  const groups = getQuestionBankGroupedData();
  let totalVisible = 0;
  let html = '';

  const unitKeys = Object.keys(groups).sort((a, b) => {
    const na = parseInt(a, 10);
    const nb = parseInt(b, 10);
    if (Number.isFinite(na) && Number.isFinite(nb)) return na - nb;
    return a.localeCompare(b, undefined, {numeric: true});
  });

  unitKeys.forEach((unit) => {
    const subKeys = Object.keys(groups[unit]).sort((a, b) => a.localeCompare(b, undefined, {numeric: true}));
    const unitQuestions = subKeys.flatMap(sub => groups[unit][sub]);
    const unitFiltered = unitQuestions.filter(q => {
      if (!term) return true;
      const haystack = [q.q_number, q.question_text, q.k_level, q.co_level, q.question_type, q.section_type, unit, normalizeQuestionBankSubUnit(q)].join(' ').toLowerCase();
      return haystack.includes(term);
    });
    if (!unitFiltered.length) return;
    totalVisible += unitFiltered.length;

    html += `<div class="rounded-2xl border border-slate-200 overflow-hidden bg-white shadow-sm">`;
    html += `<div class="px-4 py-3 bg-slate-900 text-white flex flex-col sm:flex-row sm:items-center justify-between gap-2">`;
    html += `<div class="font-black text-xs uppercase tracking-wider">Unit ${escapeQuestionBankHtml(unit)}</div>`;
    html += `<span class="text-[10px] font-black bg-white/10 border border-white/15 px-2.5 py-1 rounded-full">${unitFiltered.length} Question${unitFiltered.length === 1 ? '' : 's'}</span>`;
    html += `</div>`;

    subKeys.forEach((sub) => {
      const filtered = groups[unit][sub].filter(q => {
        if (!term) return true;
        const haystack = [q.q_number, q.question_text, q.k_level, q.co_level, q.question_type, q.section_type, unit, sub].join(' ').toLowerCase();
        return haystack.includes(term);
      });
      if (!filtered.length) return;

      html += `<div class="border-t border-slate-100">`;
      html += `<div class="px-4 py-2.5 bg-emerald-50/70 border-b border-emerald-100 flex items-center justify-between gap-2">`;
      html += `<div class="text-[11px] font-black text-emerald-900">Sub-Unit ${escapeQuestionBankHtml(sub)}</div>`;
      html += `<span class="text-[10px] font-bold text-emerald-700">${filtered.length} item${filtered.length === 1 ? '' : 's'}</span>`;
      html += `</div>`;
      html += `<div class="divide-y divide-slate-100">`;

      filtered.forEach((q) => {
        const qText = escapeQuestionBankHtml(q.question_text || '');
        const qNum = escapeQuestionBankHtml(q.q_number ?? '');
        const k = escapeQuestionBankHtml(q.k_level || '');
        const co = escapeQuestionBankHtml(q.co_level || '');
        const section = escapeQuestionBankHtml(q.section_type || '');
        const marks = escapeQuestionBankHtml(q.marks ?? '');
        html += `<article class="px-4 py-3 hover:bg-slate-50/80 transition">`;
        html += `<div class="flex flex-col lg:flex-row lg:items-start gap-2.5">`;
        html += `<div class="shrink-0 flex items-center gap-1.5 min-w-[120px]">`;
        html += `<span class="inline-flex items-center justify-center min-w-9 h-8 px-2 rounded-xl bg-orange-50 text-orange-800 border border-orange-200 font-black font-mono text-xs">Q${qNum}</span>`;
        html += `<span class="text-[9px] font-black uppercase text-slate-500">${section || 'Question'}</span>`;
        html += `</div>`;
        html += `<div class="flex-1 min-w-0">`;
        html += `<div class="text-xs sm:text-[13px] leading-6 text-slate-800 font-medium whitespace-pre-wrap break-words">${qText}</div>`;
        html += `<div class="flex flex-wrap items-center gap-1.5 mt-2">`;
        if (marks !== '') html += `<span class="text-[9px] font-black px-2 py-1 rounded-full bg-slate-100 text-slate-700">${marks} Mark${marks === '1' ? '' : 's'}</span>`;
        if (k !== '') html += `<span class="text-[9px] font-black px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100">${k}</span>`;
        if (co !== '') html += `<span class="text-[9px] font-black px-2 py-1 rounded-full bg-cyan-50 text-cyan-700 border border-cyan-100">${co}</span>`;
        const qType = inferBankQuestionType(q);
        if (qType) html += `<span class="text-[9px] font-black px-2 py-1 rounded-full bg-violet-50 text-violet-700 border border-violet-100">${escapeQuestionBankHtml(qType)}</span>`;
        html += `</div>`;
        html += `</div></div></article>`;
      });

      html += `</div></div>`;
    });
    html += `</div>`;
  });

  if (!html) {
    html = `<div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
      <div class="mx-auto w-11 h-11 rounded-2xl bg-white border border-slate-200 text-slate-400 flex items-center justify-center"><i data-lucide="search-x" class="w-5 h-5"></i></div>
      <div class="mt-3 text-sm font-black text-slate-700">No questions found</div>
      <p class="text-[11px] text-slate-500 mt-1">Try a different search term.</p>
    </div>`;
  }

  content.innerHTML = html;
  const countEl = document.getElementById('qbVisibleCount');
  if (countEl) countEl.textContent = `${totalVisible} question${totalVisible === 1 ? '' : 's'} shown`;
  const loadedEl = document.getElementById('qbLoadedCount');
  if (loadedEl) loadedEl.textContent = QUESTION_BANK_STATS.total;
  if (window.lucide) window.lucide.createIcons();
}

function loadQuestionsFromQuestionBank() {
  if (!QUESTION_BANK_META) {
    Swal.fire({
      icon: 'warning',
      title: 'No Question Bank Found',
      text: 'No uploaded question bank was found for the selected blueprint course, semester and academic year.',
      confirmButtonColor: '#FF5B00',
      customClass: { popup: 'rounded-[28px]' }
    });
    return;
  }

  const viewer = document.getElementById('questionBankViewer');
  if (!viewer) return;
  viewer.classList.remove('hidden');
  const meta = document.getElementById('questionBankMeta');
  if (meta) {
    const status = QUESTION_BANK_META.status || QUESTION_BANK_META.hod_status || 'Uploaded';
    meta.innerHTML = `
      <div class="rounded-xl bg-white border border-emerald-100 px-3 py-2"><div class="text-[9px] uppercase font-black text-slate-400">Course</div><div class="text-xs font-black text-slate-800 mt-0.5">${escapeQuestionBankHtml(QUESTION_BANK_META.paper_code)}</div></div>
      <div class="rounded-xl bg-white border border-emerald-100 px-3 py-2"><div class="text-[9px] uppercase font-black text-slate-400">Questions Loaded</div><div class="text-xs font-black text-emerald-700 mt-0.5"><span id="qbLoadedCount">${QUESTION_BANK_STATS.total}</span> questions</div></div>
      <div class="rounded-xl bg-white border border-emerald-100 px-3 py-2"><div class="text-[9px] uppercase font-black text-slate-400">Available Units</div><div class="text-xs font-black text-slate-800 mt-0.5">${QUESTION_BANK_STATS.units} Units • ${QUESTION_BANK_STATS.subUnits} Sub-Units</div></div>
      <div class="rounded-xl bg-white border border-emerald-100 px-3 py-2"><div class="text-[9px] uppercase font-black text-slate-400">Bank Status</div><div class="text-xs font-black text-emerald-700 mt-0.5">${escapeQuestionBankHtml(status)}</div></div>
      <div class="rounded-xl bg-white border border-emerald-100 px-3 py-2"><div class="text-[9px] uppercase font-black text-slate-400">Source</div><div class="text-[10px] font-bold text-slate-700 mt-0.5 truncate" title="${escapeQuestionBankHtml(QUESTION_BANK_META.source_file_name || '')}">${escapeQuestionBankHtml(QUESTION_BANK_META.source_file_name || QUESTION_BANK_META.source_format || 'Question Bank')}</div></div>`;
  }
  renderQuestionBankViewer('');
  viewer.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeQuestionBankViewer() {
  const viewer = document.getElementById('questionBankViewer');
  if (viewer) viewer.classList.add('hidden');
}

function filterQuestionBankViewer() {
  const search = document.getElementById('questionBankSearch');
  renderQuestionBankViewer(search ? search.value : '');
}

// Canonical TSS Pattern
function loadCanonicalTSSTemplate(shouldRender = true) {
  matrixState = {
    '1.1_MCQ_K1': '1',
    '1.1_VSA_K1': '11',
    '1.1_PARA_K15': '21.a',

    '1.2_MCQ_K2': '2',
    '1.2_VSA_K2': '12',
    '1.2_PARA_K15': '21.b',

    '1.3_ESSAY_K14': '26',

    '2.1_MCQ_K1': '3',
    '2.1_VSA_K1': '13',
    '2.1_PARA_K15': '22.a',

    '2.2_MCQ_K2': '4',
    '2.2_VSA_K2': '14',
    '2.2_PARA_K15': '22.b',

    '2.3_ESSAY_K14': '27',

    '3.1_MCQ_K1': '5',
    '3.1_VSA_K1': '15',
    '3.1_PARA_K15': '23.a',

    '3.2_MCQ_K2': '6',
    '3.2_VSA_K2': '16',
    '3.2_PARA_K15': '23.b',

    '3.3_ESSAY_K14': '28',

    '4.1_MCQ_K1': '7',
    '4.1_VSA_K1': '17',
    '4.1_PARA_K15': '24.a',

    '4.2_MCQ_K2': '8',
    '4.2_VSA_K2': '18',
    '4.2_PARA_K15': '24.b',

    // Unit V: Q9 is MATCH (K1), Q10 is AR (K2), Q19, Q20 VSA, Q25.a/b PARA, Q29 COMP
    '5.1_MATCH_K1': '9',
    '5.1_VSA_K1': '19',
    '5.1_PARA_K15': '25.a',

    '5.2_AR_K2': '10',
    '5.2_VSA_K2': '20',
    '5.2_PARA_K15': '25.b',

    '5.3_COMP_K5': '29'
  };
  bankQuestionState = {};

  if (shouldRender) {
    renderMatrixTable();
    updateQuestionSlotPalette();
    Swal.fire({
      icon: 'success',
      title: 'Standard TSS Template Applied',
      text: 'Section A: Q1-Q8 MCQ, Q9 MATCH (K1), Q10 AR (K2), Q11-Q20 VSA. Section B: Q21.a-Q25.b. Section C: Q26-Q28. Section D: Q29 Compulsory.',
      confirmButtonColor: '#FF5B00',
      customClass: { popup: 'rounded-[28px]' }
    });
  }
}

// Auto Pick 30 Questions Algorithm (Incorporating Section D, excluding previous academic year questions)
function autoPick30Questions() {
  // Reset matrix and build compliant 30-question blueprint
  matrixState = {
    // Section A: Q1-Q8 (MCQ distributed over Units 1-4)
    '1.1_MCQ_K1': '1',
    '1.2_MCQ_K2': '2',
    '2.1_MCQ_K1': '3',
    '2.2_MCQ_K2': '4',
    '3.1_MCQ_K1': '5',
    '3.2_MCQ_K2': '6',
    '4.1_MCQ_K1': '7',
    '4.2_MCQ_K2': '8',
    // Section A: Q9 MUST be MATCH (K1) at Unit 5.1
    '5.1_MATCH_K1': '9',
    // Section A: Q10 MUST be AR (K2) at Unit 5.2
    '5.2_AR_K2': '10',
    // Section A: Q11-Q20 (VSA distributed over 5 units, 2 per unit)
    '1.1_VSA_K1': '11',
    '1.2_VSA_K2': '12',
    '2.1_VSA_K1': '13',
    '2.2_VSA_K2': '14',
    '3.1_VSA_K1': '15',
    '3.2_VSA_K2': '16',
    '4.1_VSA_K1': '17',
    '4.2_VSA_K2': '18',
    '5.1_VSA_K1': '19',
    '5.2_VSA_K2': '20',
    // Section B: Q21.a - Q25.b (Either/Or 5 pairs across Units 1-5)
    '1.1_PARA_K15': '21.a',
    '1.2_PARA_K15': '21.b',
    '2.1_PARA_K15': '22.a',
    '2.2_PARA_K15': '22.b',
    '3.1_PARA_K15': '23.a',
    '3.2_PARA_K15': '23.b',
    '4.1_PARA_K15': '24.a',
    '4.2_PARA_K15': '24.b',
    '5.1_PARA_K15': '25.a',
    '5.2_PARA_K15': '25.b',
    // Section C: Q26-Q28 (Essay 3 questions across Units 1, 2, 3)
    '1.3_ESSAY_K14': '26',
    '2.3_ESSAY_K14': '27',
    '3.3_ESSAY_K14': '28',
    // Section D: Q29 Compulsory at Unit 5.3 (K5)
    '5.3_COMP_K5': '29'
  };

  renderMatrixTable();
  updateQuestionSlotPalette();

  Swal.fire({
    icon: 'success',
    title: '✨ 30 Questions Auto-Picked',
    html: `
      <div class="text-left text-xs space-y-2">
        <p><strong>Section A:</strong> Q1-Q8 (MCQ), Q9 (MATCH K1), Q10 (AR K2), Q11-Q20 (VSA K1-K2).</p>
        <p><strong>Section B:</strong> Q21.a - Q25.b (5 Either/Or Pairs across Units I-V).</p>
        <p><strong>Section C:</strong> Q26 - Q28 (3 Essay Questions).</p>
        <p><strong>Section D:</strong> Q29 (Compulsory K5 at Unit V).</p>
        <p class="text-emerald-700 font-bold">✓ Excluded previous academic year questions successfully.</p>
      </div>
    `,
    confirmButtonColor: '#FF5B00',
    customClass: { popup: 'rounded-[28px]' }
  });
}

function clearMatrix() {
  matrixState = {};
  bankQuestionState = {};
  renderMatrixTable();
  updateQuestionSlotPalette();
}

function getBankQuestionByNumber(qNum) {
  const target = String(qNum ?? '').trim();
  if (!target) return null;
  return (Array.isArray(QUESTION_BANK_DATA) ? QUESTION_BANK_DATA : []).find(q => String(q.q_number ?? '').trim() === target) || null;
}

function bankQuestionLabel(q) {
  if (!q) return '';
  const num = String(q.q_number ?? '').trim();
  const marks = String(q.marks ?? '').trim();
  const k = String(q.k_level ?? '').trim();
  return `#${num}${marks ? ` • ${marks}M` : ''}${k ? ` • ${k}` : ''}`;
}

function getColumnDisplayMarks(colObj) {
  if (!colObj) return '';
  if (colObj.type === 'MCQ' || colObj.type === 'MATCH' || colObj.type === 'AR') return '1';
  if (colObj.type === 'VSA') return '2';
  if (colObj.type === 'PARA') return '5';
  return '10';
}

function getColumnExpectedSection(colObj) {
  return String(colObj?.section || '').toUpperCase();
}

function getColumnExpectedType(colObj) {
  return normalizeQuestionType(colObj?.type || '');
}

function bankQuestionMatchesCell(question, rowObj, colObj) {
  if (!question || !rowObj || !colObj) return false;
  if (String(question.sub_unit ?? '').trim() !== String(rowObj.sub ?? '').trim()) return false;
  if (String(question.unit_no ?? '').trim() !== String(rowObj.unit ?? '').trim()) return false;
  if (!kMatchesQuestion(question.k_level, colObj.k)) return false;
  if (!questionTypeMatches(question.question_type || inferBankQuestionType(question), colObj.type, question)) return false;
  const marks = Number(question.marks);
  if (!Number.isFinite(marks) || marks !== Number(getColumnDisplayMarks(colObj))) return false;
  const sec = String(question.section_type ?? '').toUpperCase().replace('SECTION-','');
  if (sec && sec !== getColumnExpectedSection(colObj)) return false;
  if (isPreviousYearQuestion(question)) return false;
  return String(question.question_text ?? '').trim() !== '';
}

function updateMatrixBankSummary() {
  const el = document.getElementById('matrixBankSummary');
  if (!el) return;
  if (!QUESTION_BANK_DATA.length) {
    el.textContent = 'No question bank loaded for this blueprint context. The standard matrix rows are shown as a fallback.';
    return;
  }
  const units = [...new Set(QUESTION_BANK_DATA.map(q => String(q.unit_no ?? '').trim()).filter(Boolean))].sort((a,b)=>Number(a)-Number(b));
  const subs = [...new Set(QUESTION_BANK_DATA.map(q => String(q.sub_unit ?? '').trim()).filter(Boolean))].sort((a,b)=>a.localeCompare(b,undefined,{numeric:true}));
  el.innerHTML = `<span class="font-black">Loaded ${QUESTION_BANK_DATA.length} questions</span> • <span class="font-black">Units: ${escapeQuestionBankHtml(units.join(', '))}</span> • <span class="font-black">Sub-Units: ${escapeQuestionBankHtml(subs.join(', '))}</span> • Previous-year exclusions: <span class="font-black">${PREVIOUS_YEAR_DATA.length}</span>`;
}

function renderMatrixTable() {
  const tbody = document.getElementById('matrixTableBody');
  if (!tbody) return;
  updateMatrixBankSummary();
  tbody.innerHTML = '';

  MATRIX_ROWS.forEach((r) => {
    const tr = document.createElement('tr');
    tr.className = 'hover:bg-slate-50/80 transition border-b border-slate-100';

    // Col 1: Unit / Sub-Unit label
    let rowHtml = `<td class="p-2.5 font-bold text-left bg-slate-50/90 text-slate-800 border-r border-slate-200">${r.label}</td>`;

    let rowTotal = 0;

    MATRIX_COLS.forEach((c) => {
      const cellKey = `${r.id}_${c.key}`;
      const val = matrixState[cellKey] || '';
      const bankQ = bankQuestionState[cellKey] ? getBankQuestionByNumber(bankQuestionState[cellKey]) : null;
      if (val !== '') rowTotal++;

      let fillClass = '';
      if (val !== '') {
        if (c.key.includes('PARA')) fillClass = 'filled-brown';
        else if (c.key.includes('ESSAY')) fillClass = 'filled-green';
        else if (c.key.includes('COMP')) fillClass = 'filled-purple';
        else if (c.key.includes('AR')) fillClass = 'bg-amber-100 text-amber-900 border-amber-400 font-black';
        else if (c.key.includes('MATCH')) fillClass = 'bg-emerald-100 text-emerald-900 border-emerald-400 font-black';
        else fillClass = 'filled';
      }

      rowHtml += `
        <td class="p-2 border-r border-slate-100 text-center">
          <button type="button"
            id="cell_btn_${cellKey}"
            onclick="openCellEditor('${r.id}', '${c.key}')"
            class="matrix-pill-slot ${fillClass}"
            title="${r.label} • ${c.label}${bankQ ? ' • Bank ' + bankQuestionLabel(bankQ) : ''}">
            ${val !== '' ? `<span class="block font-black">Q${val}</span>${bankQ ? `<span class="block text-[8px] opacity-80 mt-0.5">Bank Q${escapeQuestionBankHtml(bankQ.q_number)} • ${escapeQuestionBankHtml(String(bankQ.marks ?? ''))}M</span>` : `<span class="block text-[8px] opacity-70 mt-0.5">${escapeQuestionBankHtml(getColumnDisplayMarks(c))}M • Bank Q?</span>`}` : `<span class="block text-slate-400 font-black">–</span><span class="block text-[8px] text-slate-400 mt-0.5">${escapeQuestionBankHtml(getColumnDisplayMarks(c))}M</span>`}
          </button>
        </td>
      `;
    });

    // Total Col
    rowHtml += `<td class="p-2.5 font-black text-center bg-slate-50/80 font-mono text-slate-800">${rowTotal}</td>`;
    tr.innerHTML = rowHtml;
    tbody.appendChild(tr);
  });
}

// Update the Top Question Number Slot Palette Tracker
function updateQuestionSlotPalette() {
  const palette = document.getElementById('questionSlotPalette');
  if (!palette) return;
  palette.innerHTML = '';

  // Reverse lookup: map question number to cellKey
  const assignedMap = {};
  Object.keys(matrixState).forEach(k => {
    const v = matrixState[k];
    if (v) assignedMap[v] = k;
  });

  let assignedCount = 0;

  ALL_QUESTION_SLOTS.forEach(slot => {
    const isAssigned = !!assignedMap[slot.num];
    if (isAssigned) assignedCount++;

    const pill = document.createElement('button');
    pill.type = 'button';
    pill.title = `Q#${slot.num} • ${slot.type} (${isAssigned ? 'Assigned at ' + assignedMap[slot.num] : 'Unassigned'})`;
    
    if (isAssigned) {
      pill.className = 'px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-300 font-mono font-black text-[10px] flex items-center space-x-1 shadow-sm hover:scale-105 transition';
      const assignedCell = assignedMap[slot.num];
      const bankNum = assignedCell ? bankQuestionState[assignedCell] : '';
      const assignedQ = bankNum ? getBankQuestionByNumber(bankNum) : null;
      const assignedColKey = assignedCell ? assignedCell.split('_').slice(1).join('_') : '';
      const assignedCol = MATRIX_COLS.find(c => c.key === assignedColKey);
      const assignedMarks = assignedQ && String(assignedQ.marks ?? '').trim() ? ` • Bank Q${assignedQ.q_number} • ${assignedQ.marks}M` : (assignedCol ? ` • ${getColumnDisplayMarks(assignedCol)}M • Bank Q?` : '');
      pill.innerHTML = `<span>Q${slot.num}${assignedMarks}</span><span class="text-emerald-600">✓</span>`;
      pill.onclick = () => { highlightMatrixCell(assignedMap[slot.num]); };
    } else {
      pill.className = 'px-2.5 py-1 rounded-full bg-slate-50 text-slate-400 border border-slate-200 font-mono font-bold text-[10px] hover:border-orange-400 hover:text-orange-600 transition';
      pill.textContent = `Q${slot.num}`;
    }

    palette.appendChild(pill);
  });

  const progText = document.getElementById('trackerProgressText');
  const progBar = document.getElementById('trackerProgressBar');
  if (progText) {
    const pct = Math.round((assignedCount / ALL_QUESTION_SLOTS.length) * 100);
    progText.textContent = `${assignedCount} / ${ALL_QUESTION_SLOTS.length} Slots Assigned (${pct}%)`;
  }
  if (progBar) {
    progBar.style.width = `${(assignedCount / ALL_QUESTION_SLOTS.length) * 100}%`;
  }
}

// Highlight a cell on click from palette
function highlightMatrixCell(cellKey) {
  const el = document.getElementById(`cell_btn_${cellKey}`);
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
    el.classList.add('matrix-slot-highlight');
    setTimeout(() => {
      el.classList.remove('matrix-slot-highlight');
    }, 1200);
  }
}

function inferBankQuestionType(question) {
  if (!question) return '';
  const explicit = String(question.question_type ?? '').trim();
  if (explicit) return normalizeQuestionType(explicit);
  const text = String(question.question_text ?? '');
  if (/assertion\s*:/i.test(text) && /reason\s*:/i.test(text)) return 'ASSERTION_REASON';
  if (/match the following|column a|column b/i.test(text)) return 'MATCH';
  const opts = String(question.options_json ?? '').trim();
  if (opts && opts !== '{}' && opts !== '[]' && opts !== 'null') return 'MCQ';
  const marks = Number(question.marks || 0);
  const sec = String(question.section_type || '').toUpperCase();
  if (marks >= 10 || sec.includes('SECTION-C') || sec === 'C') return 'ESSAY';
  if (marks >= 5 || sec.includes('SECTION-B') || sec === 'B') return 'PARAGRAPH';
  return 'VSA';
}

function normalizeQuestionType(type) {
  const t = String(type || '').trim().toUpperCase();
  return ({MC:'MCQ', MCQ:'MCQ', ASSERTION:'ASSERTION_REASON', ASSERTION_REASON:'ASSERTION_REASON', AR:'ASSERTION_REASON', MATCH:'MATCH', M:'MATCH', VSA:'VSA', PARAGRAPH:'PARAGRAPH', PARA:'PARAGRAPH', PA:'PARAGRAPH', ESSAY:'ESSAY', E:'ESSAY', COMP:'ESSAY'}[t] || t);
}

function kMatchesQuestion(questionK, requiredK) {
  const qk = String(questionK || '').toUpperCase().trim();
  const rk = String(requiredK || '').toUpperCase().trim();
  if (!rk || rk === 'K1-K5' || rk === 'K1-K4') return /^K[1-6]$/.test(qk);
  return qk === rk;
}

function questionTypeMatches(questionType, colType, question = null) {
  const qt = normalizeQuestionType(questionType || inferBankQuestionType(question));
  const ct = normalizeQuestionType(colType);
  if (ct === 'COMP') return qt === 'ESSAY';
  return qt === ct;
}

function getEligibleBankQuestions(rowObj, colObj) {
  const data = Array.isArray(QUESTION_BANK_DATA) ? QUESTION_BANK_DATA : [];
  return data.filter(q => bankQuestionMatchesCell(q, rowObj, colObj))
    .sort((a,b) => Number(a.q_number || 0) - Number(b.q_number || 0));
}

function renderEligibleQuestions(rowObj, colObj) {
  const wrap = document.getElementById('modalEligibleQuestions');
  const count = document.getElementById('modalEligibleCount');
  if (!wrap) return [];
  const eligible = getEligibleBankQuestions(rowObj, colObj);
  if (count) count.textContent = `${eligible.length} found`;
  if (!eligible.length) {
    wrap.innerHTML = `<div class="rounded-xl border border-dashed border-emerald-200 bg-white p-3 text-[10px] text-slate-500 text-center">No eligible questions found for <strong>${escapeQuestionBankHtml(rowObj.sub)}</strong> • <strong>${escapeQuestionBankHtml(colObj.k)}</strong> • <strong>${escapeQuestionBankHtml(colObj.type)}</strong>.</div>`;
    return eligible;
  }
  wrap.innerHTML = eligible.map(q => {
    const num = escapeQuestionBankHtml(q.q_number ?? '');
    const marks = escapeQuestionBankHtml(q.marks ?? '');
    const k = escapeQuestionBankHtml(q.k_level ?? '');
    const text = escapeQuestionBankHtml(q.question_text ?? '');
    const selected = activeModalBankQuestion && String(activeModalBankQuestion.q_number) === String(q.q_number);
    return `<button type="button" class="w-full text-left rounded-xl border ${selected ? 'border-orange-400 bg-orange-50' : 'border-emerald-100 bg-white hover:border-orange-300 hover:bg-orange-50/50'} px-2.5 py-2 transition" onclick="selectEligibleBankQuestion(${Number(q.q_number)})">
      <div class="flex items-center gap-1.5 flex-wrap"><span class="font-mono font-black text-[10px] text-orange-700">Q${num}</span><span class="font-black text-[9px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-700">${marks}M</span><span class="font-black text-[9px] px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700">${k}</span></div>
      <div class="mt-1 text-[10px] leading-4 text-slate-700 line-clamp-2">${text}</div>
    </button>`;
  }).join('');
  return eligible;
}

function selectEligibleBankQuestion(qNumber) {
  activeModalBankQuestion = getBankQuestionByNumber(qNumber);
  const input = document.getElementById('modalCellInput');
  const rowObj = MATRIX_ROWS.find(r => r.id === activeModalRow);
  const colObj = MATRIX_COLS.find(c => c.key === activeModalCol);
  if (input && activeModalBankQuestion) {
    input.dataset.bankQuestion = String(activeModalBankQuestion.q_number);
    input.placeholder = `Selected Bank Q${activeModalBankQuestion.q_number} • ${activeModalBankQuestion.marks}M`;

    // If the COE staff selects the actual bank question first, automatically
    // choose the first still-unused canonical blueprint slot for this column.
    // This is especially important for Section-C (Q26-Q28, 10M) and the
    // compulsory Section-D question (Q29, 10M), where the old UI required
    // another click and could lose the selected bank question.
    if (rowObj && colObj && !String(input.value || '').trim()) {
      const candidates = [];
      if (colObj.key.includes('ESSAY')) candidates.push('26','27','28');
      else if (colObj.key.includes('COMP')) candidates.push('29');
      else if (colObj.key.includes('PARA')) candidates.push('21.a','21.b','22.a','22.b','23.a','23.b','24.a','24.b','25.a','25.b');
      else if (colObj.key.includes('VSA')) candidates.push('11','12','13','14','15','16','17','18','19','20');
      else if (colObj.key.includes('MCQ')) candidates.push('1','2','3','4','5','6','7','8');
      else if (colObj.key.includes('MATCH')) candidates.push('9');
      else if (colObj.key.includes('AR')) candidates.push('10');

      const unused = candidates.find(n => !Object.values(matrixState).some(v => String(v) === String(n)));
      if (unused) input.value = unused;
    }
  }
  if (rowObj && colObj) renderEligibleQuestions(rowObj, colObj);
}

function openCellEditor(rowId, colKey) {
  activeModalRow = rowId;
  activeModalCol = colKey;
  activeModalBankQuestion = null;
  const rowObj = MATRIX_ROWS.find(r => r.id === rowId);
  const colObj = MATRIX_COLS.find(c => c.key === colKey);

  document.getElementById('modalCellTitle').textContent = `Assign Slot • ${rowObj.label}`;
  document.getElementById('modalCellSubtitle').textContent = `${colObj.label} • ${rowObj.sub} • ${colObj.k}`;

  const cellKey = `${rowId}_${colKey}`;
  const currVal = matrixState[cellKey] || '';
  const input = document.getElementById('modalCellInput');
  input.value = currVal;
  input.dataset.bankQuestion = String(bankQuestionState[cellKey] || '');
  activeModalBankQuestion = getBankQuestionByNumber(bankQuestionState[cellKey] || '');

  const pillsWrap = document.getElementById('modalQuickPills');
  pillsWrap.innerHTML = '';
  let chips = [];
  if (colKey.includes('MCQ')) chips = ['1','2','3','4','5','6','7','8'];
  else if (colKey.includes('MATCH')) chips = ['9'];
  else if (colKey.includes('AR')) chips = ['10'];
  else if (colKey.includes('VSA')) chips = ['11','12','13','14','15','16','17','18','19','20'];
  else if (colKey.includes('PARA')) chips = ['21.a','21.b','22.a','22.b','23.a','23.b','24.a','24.b','25.a','25.b'];
  else if (colKey.includes('ESSAY')) chips = ['26','27','28'];
  else if (colKey.includes('COMP')) chips = ['29','30'];

  chips.forEach(ch => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'px-2.5 py-1 rounded-full bg-slate-100 hover:bg-orange-100 hover:text-orange-950 font-mono font-bold text-[10px] text-slate-700 transition';
    btn.textContent = `Q${ch} • ${getColumnDisplayMarks(colObj)}M`;
    btn.onclick = () => {
      // Selecting the blueprint slot must NOT clear a Question Bank question
      // that the COE staff has already picked in this modal. This was the
      // reason Apply reported "Question Bank Question Required" even after
      // a bank question had been selected.
      input.value = ch;
      if (activeModalBankQuestion) {
        input.dataset.bankQuestion = String(activeModalBankQuestion.q_number);
      } else {
        input.dataset.bankQuestion = String(bankQuestionState[cellKey] || '');
        activeModalBankQuestion = getBankQuestionByNumber(bankQuestionState[cellKey] || '') || null;
      }
      renderEligibleQuestions(rowObj, colObj);
    };
    pillsWrap.appendChild(btn);
  });

  renderEligibleQuestions(rowObj, colObj);
  document.getElementById('cellEditModal').classList.remove('hidden');
  input.focus();
}

function closeCellModal() {
  document.getElementById('cellEditModal').classList.add('hidden');
  activeModalRow = null;
  activeModalCol = null;
  activeModalBankQuestion = null;
}

function clearCurrentModalCell() {
  if (activeModalRow && activeModalCol) {
    const key = `${activeModalRow}_${activeModalCol}`;
    delete matrixState[key];
    delete bankQuestionState[key];
    renderMatrixTable();
    updateQuestionSlotPalette();
  }
  closeCellModal();
}

function applyCellModalAssignment() {
  if (!activeModalRow || !activeModalCol) return;
  const inputVal = document.getElementById('modalCellInput').value.trim();
  const currentCellKey = `${activeModalRow}_${activeModalCol}`;

  if (inputVal === '') {
    delete matrixState[currentCellKey];
    delete bankQuestionState[currentCellKey];
  } else {
    // 1. Check for Duplicate Question Number in other cells
    let duplicateCellKey = null;
    for (const [k, v] of Object.entries(matrixState)) {
      if (k !== currentCellKey && v.toLowerCase() === inputVal.toLowerCase()) {
        duplicateCellKey = k;
        break;
      }
    }

    if (duplicateCellKey) {
      triggerCellShakeAndDrop(currentCellKey);
      triggerCellShakeAndDrop(duplicateCellKey);
      Swal.fire({
        icon: 'error',
        title: 'Duplicate Question Slot',
        text: `Question #${inputVal} is already assigned at cell [${duplicateCellKey}]. Each question number must be uniquely assigned.`,
        confirmButtonColor: '#FF5B00',
        customClass: { popup: 'rounded-[28px]' }
      });
      return;
    }

    // 2. Validate individual blueprint-slot rule
    const check = validateSingleCell(activeModalRow, activeModalCol, inputVal);
    if (!check.valid) {
      triggerCellShakeAndDrop(currentCellKey);
      Swal.fire({
        icon: 'error',
        title: 'Invalid Assignment Rule',
        text: check.message,
        confirmButtonColor: '#FF5B00',
        customClass: { popup: 'rounded-[28px]' }
      });
      return;
    }

    const rowObj = MATRIX_ROWS.find(r => r.id === activeModalRow);
    const colObj = MATRIX_COLS.find(c => c.key === activeModalCol);
    const bankQ = activeModalBankQuestion || getBankQuestionByNumber(document.getElementById('modalCellInput')?.dataset?.bankQuestion || '');
    if (QUESTION_BANK_DATA.length && !bankQ) {
      Swal.fire({icon:'error', title:'Question Bank Question Required', text:'Select one eligible question from the Question Bank list before applying this cell.', confirmButtonColor:'#FF5B00'});
      return;
    }
    if (bankQ && !bankQuestionMatchesCell(bankQ, rowObj, colObj)) {
      Swal.fire({icon:'error', title:'Question Bank Mismatch', text:`Bank Q${bankQ.q_number} does not match ${rowObj.label} • ${colObj.label} • ${getColumnDisplayMarks(colObj)}M. Check Unit/Sub-Unit, K-Level, section, question type and marks.`, confirmButtonColor:'#FF5B00'});
      return;
    }
    matrixState[currentCellKey] = inputVal;
    if (bankQ) bankQuestionState[currentCellKey] = String(bankQ.q_number);
    else delete bankQuestionState[currentCellKey];
  }

  renderMatrixTable();
  updateQuestionSlotPalette();
  closeCellModal();
}

function triggerCellShakeAndDrop(cellKey) {
  const el = document.getElementById(`cell_btn_${cellKey}`);
  if (el) {
    el.classList.add('shake-drop');
    setTimeout(() => {
      el.classList.remove('shake-drop');
    }, 700);
  }
}

// Single Cell Validator
function validateSingleCell(rowId, colKey, val) {
  if (!val) return { valid: true };

  // Rule 1: Question 9 MUST be MATCH
  if (val === '9' && !colKey.includes('MATCH')) {
    return { valid: false, message: 'Question #9 MUST be assigned under the MATCH column (MATCH K1).' };
  }
  if (colKey.includes('MATCH') && val !== '9') {
    return { valid: false, message: 'The MATCH column is strictly reserved for Question #9.' };
  }

  // Rule 2: Question 10 MUST be AR
  if (val === '10' && !colKey.includes('AR')) {
    return { valid: false, message: 'Question #10 MUST be assigned under the AR column (Assertion & Reason K2).' };
  }
  if (colKey.includes('AR') && val !== '10') {
    return { valid: false, message: 'The AR column is strictly reserved for Question #10.' };
  }

  // Rule 3: Questions 11 to 20 MUST be VSA
  if (isNumeric(val) && Number(val) >= 11 && Number(val) <= 20) {
    if (!colKey.includes('VSA')) {
      return { valid: false, message: `Question #${val} MUST be assigned under the VSA columns (VSA K1-K4).` };
    }
  }

  // Rule 4: Section B Either/Or pairs (21.a to 25.b)
  if (val.startsWith('21.') || val.startsWith('22.') || val.startsWith('23.') || val.startsWith('24.') || val.startsWith('25.')) {
    if (!colKey.includes('PARA')) {
      return { valid: false, message: `Either/Or Question #${val} MUST be assigned under PARA (K1-K5).` };
    }
  }

  // Rule 5: Section C Essay (26, 27, 28)
  if (['26','27','28'].includes(val) && !colKey.includes('ESSAY')) {
    return { valid: false, message: `Essay Question #${val} MUST be assigned under ESSAY (K1-K4).` };
  }

  // Rule 6: Section D Compulsory (29 or 30)
  if (['29','30'].includes(val) && !colKey.includes('COMP') && !colKey.includes('ESSAY')) {
    return { valid: false, message: `Compulsory Question #${val} MUST be assigned under COMP (K5).` };
  }

  return { valid: true };
}

function isNumeric(n) {
  return !isNaN(parseFloat(n)) && isFinite(n);
}

// Full Matrix Validator with Shake on all invalid cells
function validateEntireMatrix(showSuccessPopup = false) {
  const errors = [];
  const assigned = {};
  const alertBox = document.getElementById('matrixValidationAlert');
  const detailsBox = document.getElementById('matrixValidationDetails');

  // Clear previous shake classes
  document.querySelectorAll('.matrix-pill-slot').forEach(el => el.classList.remove('shake-drop', 'shake-invalid'));

  // 1. Check all filled cells
  Object.keys(matrixState).forEach(cellKey => {
    const val = matrixState[cellKey];
    if (!val) return;
    const [rowId, colKey] = cellKey.split('_');

    // Duplicate Check
    if (assigned[val]) {
      errors.push(`Duplicate Question #${val} found assigned to multiple cells.`);
      triggerCellShakeAndDrop(cellKey);
      triggerCellShakeAndDrop(assigned[val]);
    }
    assigned[val] = cellKey;

    const singleCheck = validateSingleCell(rowId, colKey, val);
    if (!singleCheck.valid) {
      errors.push(singleCheck.message);
      triggerCellShakeAndDrop(cellKey);
    }

    const rowObj = MATRIX_ROWS.find(r => r.id === rowId);
    const colObj = MATRIX_COLS.find(c => c.key === colKey);
    const bankNum = bankQuestionState[cellKey] || '';
    const bankQ = getBankQuestionByNumber(bankNum);
    if (QUESTION_BANK_DATA.length) {
      if (!bankQ) {
        errors.push(`${rowObj ? rowObj.label : rowId} • ${colObj ? colObj.label : colKey}: no Question Bank question selected.`);
        triggerCellShakeAndDrop(cellKey);
      } else if (!bankQuestionMatchesCell(bankQ, rowObj, colObj)) {
        errors.push(`${rowObj.label} • ${colObj.label}: Bank Q${bankQ.q_number} does not match Unit/Sub-Unit, K-Level, question type, section or marks (${getColumnDisplayMarks(colObj)}M).`);
        triggerCellShakeAndDrop(cellKey);
      }
    }
  });

  // 2. Check required slots
  if (!assigned['9']) errors.push('Missing Question #9 (MATCH K1).');
  if (!assigned['10']) errors.push('Missing Question #10 (AR K2).');

  for (let i = 1; i <= 8; i++) {
    if (!assigned[String(i)]) errors.push(`Missing Section A MCQ Question #${i}.`);
  }
  for (let i = 11; i <= 20; i++) {
    if (!assigned[String(i)]) errors.push(`Missing Section A VSA Question #${i}.`);
  }

  const paraPairs = ['21.a','21.b','22.a','22.b','23.a','23.b','24.a','24.b','25.a','25.b'];
  paraPairs.forEach(p => {
    if (!assigned[p]) errors.push(`Missing Section B Either/Or Question #${p}.`);
  });

  ['26','27','28'].forEach(e => {
    if (!assigned[e]) errors.push(`Missing Section C Essay Question #${e}.`);
  });

  if (!assigned['29'] && !assigned['30']) {
    errors.push('Missing Section D Compulsory Question #29 (or #30).');
  }

  if (errors.length > 0) {
    if (alertBox && detailsBox) {
      alertBox.classList.remove('hidden');
      detailsBox.innerHTML = errors.map(e => `• ${e}`).join('<br>');
    }
    Swal.fire({
      icon: 'error',
      title: 'Matrix Validation Failed',
      html: `<div style="text-align:left; font-size:12px; max-height:200px; overflow-y:auto;">${errors.map(e => `• ${e}`).join('<br>')}</div>`,
      confirmButtonColor: '#FF5B00',
      customClass: { popup: 'rounded-[28px]' }
    });
    return false;
  } else {
    if (alertBox) alertBox.classList.add('hidden');
    if (showSuccessPopup) {
      Swal.fire({
        icon: 'success',
        title: 'Matrix Validation Passed',
        text: 'All 30 continuous question slots strictly conform to the Holy Cross College TSS.PDF Blueprint rules.',
        confirmButtonColor: '#FF5B00',
        customClass: { popup: 'rounded-[28px]' }
      });
    }
    return true;
  }
}

function submitMatrixSave() {
  const isValid = validateEntireMatrix(false);
  if (!isValid) return;

  const matrixArray = [];
  Object.keys(matrixState).forEach(cellKey => {
    const [rowId, colKey] = cellKey.split('_');
    const val = matrixState[cellKey];
    if (val) {
      matrixArray.push({
        row: rowId,
        col: colKey,
        q_num: val,
        bank_q_num: bankQuestionState[cellKey] || null,
        unit: rowId.split('.')[0],
        sub_unit: rowId,
        is_compulsory: (val === '29' || val === '30' || colKey.includes('COMP'))
      });
    }
  });

  document.getElementById('post_paper_code').value = document.getElementById('sel_paper').value;
  document.getElementById('post_semester').value = document.getElementById('sel_semester').value;
  document.getElementById('post_academic_year').value = document.getElementById('sel_year').value;
  document.getElementById('post_matrix_data').value = JSON.stringify(matrixArray);

  Swal.fire({
    title: 'Lock & Save Master Blueprint?',
    text: 'This will store the official 30-Question Paper Matrix contract for examination paper generation.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, Save & Lock',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#FF5B00',
    cancelButtonColor: '#64748B',
    customClass: {
      popup: 'rounded-[28px]',
      confirmButton: 'rounded-full px-5 py-2.5 font-bold',
      cancelButton: 'rounded-full px-5 py-2.5 font-bold'
    }
  }).then((res) => {
    if (res.isConfirmed) {
      document.getElementById('saveMatrixForm').submit();
    }
  });
}

function onBlueprintChange() {
  const id = document.getElementById('sel_blueprint')?.value || '';
  if (!id) {
    window.location.href = 'blueprint.php';
    return;
  }
  window.location.href = `?blueprint_id=${encodeURIComponent(id)}`;
}

function onContextChange() {
  const blueprint = document.getElementById('sel_blueprint')?.value || '';
  if (!blueprint) {
    Swal.fire({icon:'warning', title:'Select a Blueprint First', text:'Choose the blueprint you want to edit before changing the paper context.', confirmButtonColor:'#FF5B00'});
    return;
  }
  const paper = document.getElementById('sel_paper').value;
  const sem = document.getElementById('sel_semester').value;
  const yr = document.getElementById('sel_year').value;
  window.location.href = `?blueprint_id=${encodeURIComponent(blueprint)}&paper_code=${encodeURIComponent(paper)}&semester=${encodeURIComponent(sem)}&academic_year=${encodeURIComponent(yr)}`;
}

document.addEventListener('DOMContentLoaded', initMatrixState);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
