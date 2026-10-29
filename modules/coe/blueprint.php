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
                SELECT q.id, q.q_number, q.unit_no, q.sub_unit, q.section_type,
                       q.marks, q.k_level, q.co_level, q.question_type, q.options_json, q.question_text, q.language
                FROM questions q
                WHERE q.bank_id = ?
                ORDER BY CAST(q.unit_no AS UNSIGNED) ASC, q.sub_unit ASC, q.q_number ASC, q.id ASC
            ");
            $stBankQuestions->execute([(int)$selectedQuestionBank['id']]);
            $availableBankQuestions = $stBankQuestions->fetchAll(PDO::FETCH_ASSOC);

            // Fallback to questions_json if relational rows are empty
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

// Question-bank contract helpers (75 Marks Scheme: Section A 1*20=20M, Section B 5*5=25M, Section C 2*10=20M, Section D 1*10=10M - NO 2 MARKS)
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
    // All Section A questions are 1 Mark (No 2 Marks)
    if (strpos($c, 'MCQ_K1') !== false) return ['section'=>'A','k'=>['K1'],'type'=>'MCQ','marks'=>1];
    if (strpos($c, 'MCQ_K2') !== false) return ['section'=>'A','k'=>['K2'],'type'=>'MCQ','marks'=>1];
    if (strpos($c, 'MCQ_K3') !== false) return ['section'=>'A','k'=>['K3'],'type'=>'MCQ','marks'=>1];
    if (strpos($c, 'AR_K2') !== false) return ['section'=>'A','k'=>['K2'],'type'=>'ASSERTION_REASON','marks'=>1];
    if (strpos($c, 'MATCH_K1') !== false) return ['section'=>'A','k'=>['K1'],'type'=>'MATCH','marks'=>1];
    if (strpos($c, 'VSA_K1') !== false) return ['section'=>'A','k'=>['K1'],'type'=>'VSA','marks'=>1];
    if (strpos($c, 'VSA_K2') !== false) return ['section'=>'A','k'=>['K2'],'type'=>'VSA','marks'=>1];
    if (strpos($c, 'VSA_K3') !== false) return ['section'=>'A','k'=>['K3'],'type'=>'VSA','marks'=>1];
    if (strpos($c, 'VSA_K4') !== false) return ['section'=>'A','k'=>['K4'],'type'=>'VSA','marks'=>1];
    // Section B (5 Marks)
    if (strpos($c, 'PARA_K15') !== false) return ['section'=>'B','k'=>['K1','K2','K3','K4','K5'],'type'=>'PARAGRAPH','marks'=>5];
    // Section C & D (10 Marks)
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
    $type = bp_question_type($q);
    $marks = (int)($q['marks'] ?? 0);
    $isFlexibleComp = strpos($col, 'COMP_K5') !== false && $marks === 10 && in_array($k, ['K4','K5'], true) && $type === 'ESSAY';
    $requiredK = $isFlexibleComp ? ['K4','K5'] : $contract['k'];
    if ($requiredK && !in_array($k, $requiredK, true)) $errors[] = "K-Level mismatch: bank has {$k}, cell requires " . implode('/', $requiredK) . ".";
    if ($contract['type'] && $type !== $contract['type']) $errors[] = "Question type mismatch: bank has {$type}, cell requires {$contract['type']}.";
    if ($contract['marks'] > 0 && $marks !== $contract['marks']) $errors[] = "Marks mismatch: bank has {$marks}M, cell requires {$contract['marks']}M.";
    $section = strtoupper(preg_replace('/^SECTION\\s*[-:]?\\s*/', '', (string)($q['section_type'] ?? '')));
    if (!$isFlexibleComp && $contract['section'] && $section !== '' && $section !== $contract['section']) $errors[] = "Section mismatch: bank has Section {$section}, cell requires Section {$contract['section']}.";
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
            $unit = trim((string)($cell['sub_unit'] ?? ($cell['row'] ?? ($cell['unit'] ?? ''))));

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
                $errors[] = "Question #9 MUST be assigned to MATCH (K1, 1M). Found in {$col}.";
            }
            if ($qNum === '10' && strpos($col, 'AR') === false) {
                $errors[] = "Question #10 MUST be assigned to AR (Assertion & Reason K2, 1M). Found in {$col}.";
            }
            if (is_numeric($qNum) && (int)$qNum >= 1 && (int)$qNum <= 8) {
                if (strpos($col, 'MCQ') === false) {
                    $errors[] = "Question #{$qNum} MUST be assigned to MCQ (1M). Found in {$col}.";
                }
            }
            if (is_numeric($qNum) && (int)$qNum >= 11 && (int)$qNum <= 20) {
                if (strpos($col, 'VSA') === false) {
                    $errors[] = "Question #{$qNum} MUST be assigned to Section A Short Answer (VSA 1M). Found in {$col}.";
                }
            }
            if (in_array($qNum, ['21.a','21.b','22.a','22.b','23.a','23.b','24.a','24.b','25.a','25.b'], true)) {
                if (strpos($col, 'PARA') === false) {
                    $errors[] = "Question #{$qNum} MUST be assigned to Section B PARA (5M). Found in {$col}.";
                }
            }
            if (in_array($qNum, ['26','27','28'], true)) {
                if (strpos($col, 'ESSAY') === false) {
                    $errors[] = "Question #{$qNum} MUST be assigned to Section C ESSAY (10M). Found in {$col}.";
                }
            }
            if (in_array($qNum, ['29','30'], true) && strpos($col, 'COMP') === false && strpos($col, 'ESSAY') === false) {
                if ($cell['is_compulsory'] ?? false) {
                    if (strpos($col, 'COMP') === false) {
                        $errors[] = "Question #{$qNum} MUST be assigned to Section D COMP (10M). Found in {$col}.";
                    }
                }
            }
        }

        // Either/Or pair validation: Q21.a/Q21.b through Q25.a/Q25.b
        $bankQuestionForSlot = [];
        foreach ($matrixData as $cell) {
            $slot = trim((string)($cell['q_num'] ?? ''));
            $bankNum = trim((string)($cell['bank_q_num'] ?? ''));
            if ($slot !== '' && $bankNum !== '' && isset($bankByNumber[$bankNum])) {
                $bankQuestionForSlot[$slot] = $bankByNumber[$bankNum];
            }
        }
        foreach ([21,22,23,24,25] as $pairNo) {
            $a = $bankQuestionForSlot[$pairNo . '.a'] ?? null;
            $b = $bankQuestionForSlot[$pairNo . '.b'] ?? null;
            if ($a && $b) {
                $ka = strtoupper(trim((string)($a['k_level'] ?? '')));
                $kb = strtoupper(trim((string)($b['k_level'] ?? '')));
                if ($ka !== '' && $kb !== '' && $ka !== $kb) {
                    $errors[] = "Either/Or pair Q{$pairNo}.a and Q{$pairNo}.b must have the same K-Level; selected {$ka} and {$kb}.";
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
            'marks_scheme' => '75_marks_strict_1M_5M_10M',
            'matrix' => $matrixData,
            'rules' => [
                'section_a_1m' => 'Q1-Q20 (20 x 1M = 20M)',
                'section_b_5m' => 'Q21.a-Q25.b (5 x 5M = 25M)',
                'section_c_10m' => 'Q26-Q29 (choose 2 of 4; 2 x 10M = 20M)',
                'section_d_10m' => 'Q30 (1 x 10M = 10M)'
            ],
            'saved_at' => date('Y-m-d H:i:s')
        ];

        $encodedPayload = bp_json($matrixPayload);

        if ($selectedBlueprintId > 0) {
            $stUp = $pdo->prepare("UPDATE blueprints SET matrix_config = ?, total_marks = 75, updated_at = NOW() WHERE id = ?");
            $stUp->execute([$encodedPayload, $selectedBlueprintId]);
        } else {
            $stIns = $pdo->prepare("INSERT INTO blueprints (name, paper_code, semester, academic_year, exam_type, total_marks, duration_hours, matrix_config, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 75, '3 Hours', ?, 'COE_OFFICE', NOW(), NOW())");
            $stIns->execute(['Holy Cross 30-Question Paper Matrix (75 Marks)', $selectedPaper, $selectedSemester, $selectedYear, $selectedExam, $encodedPayload]);
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

        $msg = 'Master Blueprint Matrix (75 Marks: Section A 1×20=20M, Section B 5×5=25M, Section C 2×10=20M, Section D 1×10=10M) saved & locked successfully.';
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
            <span class="bg-orange-500/15 text-orange-700 border border-orange-400/30 text-[10px] font-black px-2.5 py-0.5 rounded-full uppercase font-mono">75 Marks Official OBE Matrix</span>
          </div>
          <p class="text-[11px] text-slate-500 mt-0.5">
            <strong>Section A (20×1M = 20M)</strong>: Q1–Q8 MCQ (1M), Q9 MATCH (1M), Q10 AR (1M), Q11–Q20 VSA (1M) • 
            <strong>Section B (5×5M = 25M)</strong>: Q21–Q25 Either/Or (5M) • 
            <strong>Section C (choose 2 of 4; 2×10M = 20M)</strong>: Q26–Q29 Essay (10M) •
            <strong>Section D (1×10M = 10M)</strong>: Q30 Compulsory (10M).
            <span class="text-emerald-700 font-black">Total: 75 Marks (No 2 Marks).</span>
          </p>
        </div>
      </div>

      <!-- Quick Action Buttons including Auto-Pick 30 Questions -->
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" onclick="autoPick30Questions()" class="btn-orange-pill text-xs shadow-md shadow-orange-500/30 flex items-center space-x-1.5" title="Auto pick 30 questions incorporating Section D (75 Marks), excluding previous academic year questions">
          <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
          <span>Auto Pick 30 Questions</span>
        </button>
        <button type="button" onclick="loadCanonicalTSSTemplate()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 text-xs font-bold px-3.5 py-2 rounded-full transition flex items-center space-x-1.5 shadow-sm">
          <i data-lucide="wand-2" class="w-3.5 h-3.5"></i>
          <span>Standard Pattern (75M)</span>
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
          <div class="text-[11px] text-slate-600 mt-0.5">Select the master blueprint before configuring question slots.</div>
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
          <span class="bg-emerald-100 text-emerald-800 border border-emerald-200 rounded-full px-3 py-1.5 text-[10px] font-black whitespace-nowrap">✓ Blueprint Active</span>
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
        <label class="block font-bold text-slate-700 mb-1">Question Bank & Previous Year</label>
        <div class="w-full border border-stone-300 rounded-2xl p-2.5 bg-amber-50/70 text-amber-950 font-bold flex items-center justify-between">
          <span class="truncate"><?php echo count($availableBankQuestions); ?> Bank Qs Loaded</span>
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
      <p class="text-xs text-slate-500 mt-1 max-w-xl mx-auto">Choose a blueprint from <b>Blueprint Context</b> above to load and edit its 30-question matrix.</p>
    </section>
  <?php endif; ?>

  <!-- Uploaded Question Bank Viewer (read-only) -->
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
            <p class="text-[11px] text-slate-500 mt-1">Questions displayed directly from the Question Bank repository.</p>
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
          <p class="text-[10px] text-slate-500 mt-0.5">The unit/sub-unit list is derived directly from the uploaded Question Bank.</p>
        </div>
        <input id="questionBankSearch" type="search" oninput="filterQuestionBankViewer()" placeholder="Search question number or question text..." class="w-full md:w-80 border border-slate-200 rounded-2xl px-3 py-2 text-xs font-medium focus:ring-2 focus:ring-emerald-500 focus:border-emerald-400">
      </div>
      <div id="questionBankContent" class="space-y-4"></div>
    </div>
  </section>

  <!-- Visual Question Palette Tracker (Circular Balls 1-30 with Gray Base & Orange Highlight for Questions with Live Unit Inspector) -->
  <section class="card-modern bg-white p-5 border border-slate-200/90 shadow-sm space-y-4 <?php echo $selectedBlueprintId > 0 ? '' : 'opacity-40 pointer-events-none'; ?>">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-3">
      <div class="flex items-center space-x-2">
        <div class="w-7 h-7 rounded-xl bg-orange-100 text-orange-700 flex items-center justify-center shrink-0">
          <i data-lucide="list-checks" class="w-4 h-4 text-orange-600"></i>
        </div>
        <div>
          <h3 class="font-black text-xs uppercase tracking-wider text-slate-900">30-Question Paper Slot Tracker</h3>
          <p class="text-[10px] text-slate-500">Click a Question Number ball (1–30), then assign an unused, matching question directly from the eligible-question panel.</p>
        </div>
      </div>
      <div class="flex items-center space-x-3 text-xs">
        <span id="trackerProgressText" class="font-black text-slate-800 font-mono">0 / 30 Slots Assigned</span>
        <div class="w-32 h-2.5 bg-slate-100 rounded-full overflow-hidden border border-slate-200">
          <div id="trackerProgressBar" class="h-full bg-gradient-to-r from-orange-500 to-amber-500 transition-all duration-300" style="width: 0%;"></div>
        </div>
      </div>
    </div>

    <!-- Palette Legend & Summary Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-[10px] font-bold text-slate-600 bg-slate-50/80 p-2.5 rounded-2xl border border-slate-200">
      <div class="flex flex-wrap items-center gap-3">
        <span class="inline-flex items-center gap-1.5"><i class="w-3 h-3 rounded-full bg-gradient-to-tr from-orange-500 to-amber-500 border border-orange-600"></i> Question available in bank (Orange)</span>
        <span class="inline-flex items-center gap-1.5"><i class="w-3 h-3 rounded-full bg-slate-100 border border-slate-300"></i> Unassigned / No bank question (Gray)</span>
        <span class="inline-flex items-center gap-1.5"><i class="w-3 h-3 rounded-full bg-amber-300 border border-amber-500"></i> Eligible Unit cells highlighted below</span>
      </div>
      <span class="text-orange-800 font-black text-xs" id="eligibleQuestionSummary" aria-live="polite">Click a question ball below</span>
    </div>

    <!-- Question Number Balls 1 to 30 Grid -->
    <div class="flex flex-wrap gap-2 text-xs py-1" id="questionSlotPalette" role="group" aria-label="Question numbers 1 to 30"></div>

    <!-- Interactive Unit Question Inspector Panel (Dynamically populated when clicking Question Balls) -->
    <div id="eligibleQuestionsInspector" class="hidden rounded-2xl border border-orange-200 bg-orange-50/50 p-4 space-y-3 transition-all duration-200">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-orange-200/80 pb-2.5">
        <div class="flex items-center space-x-2">
          <span class="w-2.5 h-2.5 rounded-full bg-orange-500 animate-ping"></span>
          <h4 class="font-black text-xs text-orange-950 uppercase tracking-wider" id="inspectorHeaderTitle">Eligible Questions for Selected Slot</h4>
        </div>
        <div class="text-[11px] font-bold text-orange-800" id="inspectorSubTitle">Found 0 matching questions in Question Bank</div>
      </div>
      <div id="inspectorQuestionsList" class="grid grid-cols-1 md:grid-cols-2 gap-3 max-h-72 overflow-y-auto custom-scrollbar-y pr-1"></div>
    </div>
  </section>

  <!-- Live Validation Error Diagnostic Banner (Hidden by default) -->
  <div id="matrixValidationAlert" class="hidden card-modern p-4 bg-rose-50 border border-rose-300 text-rose-900 text-xs shadow-md space-y-1.5">
    <div class="flex items-center space-x-2 font-black text-rose-700">
      <i data-lucide="alert-triangle" class="w-4 h-4"></i>
      <span>Matrix Validation Shortage / Rule Violation</span>
    </div>
    <div id="matrixValidationDetails" class="text-[11px] font-medium leading-relaxed pl-6"></div>
  </div>

  <!-- THE MASTER 30-QUESTION PAPER BLUEPRINT MATRIX TABLE (75 Marks Scheme: 1M, 5M, 10M - No 2 Marks) -->
  <section class="card-modern bg-white p-5 border border-slate-200 shadow-md space-y-3 <?php echo $selectedBlueprintId > 0 ? "" : "opacity-40 pointer-events-none"; ?>">
    
    <!-- Table Header Info Bar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-slate-100 pb-3">
      <div class="flex items-center space-x-2">
        <span class="w-2.5 h-2.5 rounded-full bg-sky-500 animate-pulse"></span>
        <h2 class="text-xs sm:text-sm font-black uppercase tracking-wider text-slate-900 font-serif">
          HOLY CROSS 30-QUESTION PAPER BLUEPRINT MATRIX (75 MARKS TOTAL)
        </h2>
      </div>
      <div class="flex items-center gap-2 text-[10px] font-bold text-slate-600">
        <span class="bg-sky-50 text-sky-800 border border-sky-200 px-2.5 py-0.5 rounded-full">Sec A: 20×1M = 20M</span>
        <span class="bg-amber-50 text-amber-800 border border-amber-200 px-2.5 py-0.5 rounded-full">Sec B: 5×5M = 25M</span>
        <span class="bg-emerald-50 text-emerald-800 border border-emerald-200 px-2.5 py-0.5 rounded-full">Sec C: 2×10M = 20M</span>
        <span class="bg-purple-50 text-purple-800 border border-purple-200 px-2.5 py-0.5 rounded-full">Sec D: 1×10M = 10M</span>
      </div>
    </div>

    <!-- Scrollable Matrix Table Container -->
    <div class="overflow-x-auto custom-scrollbar-x rounded-2xl border border-slate-200">
      <table class="w-full text-center border-collapse text-xs select-none" id="blueprintMatrixTable">
        
        <!-- Table Column Headers with 1M, 5M, 10M explicit marks (No 2 Marks) -->
        <thead>
          <tr class="bg-slate-950 text-white uppercase text-[10px] font-black tracking-wider">
            <th class="p-2.5 border-r border-slate-800 text-left min-w-[110px] bg-black">UNIT / SUB-UNIT</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]" title="MCQ K1 (1 Mark)">MCQ (K1) • 1M</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]" title="MCQ K2 (1 Mark)">MCQ (K2) • 1M</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]" title="MCQ K3 (1 Mark)">MCQ (K3) • 1M</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px] bg-slate-900 text-amber-300" title="Assertion & Reason K2 (Q10, 1 Mark)">AR (K2) • 1M</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px] bg-slate-900 text-emerald-300" title="Match the Following K1 (Q9, 1 Mark)">MATCH (K1) • 1M</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]" title="Short Answer / VSA K1 (1 Mark)">VSA (K1) • 1M</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]" title="Short Answer / VSA K2 (1 Mark)">VSA (K2) • 1M</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]" title="Short Answer / VSA K3 (1 Mark)">VSA (K3) • 1M</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[65px]" title="Short Answer / VSA K4 (1 Mark)">VSA (K4) • 1M</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[80px] bg-[#451A03] text-amber-200" title="Paragraph Question (Section B, 5 Marks)">PARA (K1-K5) • 5M</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[80px] bg-[#064E3B] text-emerald-200" title="Essay Question (Section C, 10 Marks)">ESSAY (K1-K4) • 10M</th>
            <th class="p-2.5 border-r border-slate-800 min-w-[70px] bg-[#3B0764] text-purple-200" title="Compulsory Question (Section D, 10 Marks)">COMP (K5) • 10M</th>
            <th class="p-2.5 bg-black text-amber-400 min-w-[55px]">TOTAL</th>
          </tr>
        </thead>

        <!-- Table Rows populated dynamically via JS -->
        <tbody class="divide-y divide-slate-100 font-medium" id="matrixTableBody"></tbody>
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

<!-- Modal: Cell Question Number Editor -->

<script>
// Matrix rows are populated from the loaded question bank (with a standard fallback when no bank is loaded).
let MATRIX_ROWS = [];

// 12 Question Type Columns (All Section A columns are 1M - No 2 Marks)
const MATRIX_COLS = [
  { key: 'MCQ_K1', label: 'MCQ (K1)', section: 'A', k: 'K1', type: 'MCQ', marks: 1 },
  { key: 'MCQ_K2', label: 'MCQ (K2)', section: 'A', k: 'K2', type: 'MCQ', marks: 1 },
  { key: 'MCQ_K3', label: 'MCQ (K3)', section: 'A', k: 'K3', type: 'MCQ', marks: 1 },
  { key: 'AR_K2', label: 'AR (K2)', section: 'A', k: 'K2', type: 'AR', marks: 1 },
  { key: 'MATCH_K1', label: 'MATCH (K1)', section: 'A', k: 'K1', type: 'MATCH', marks: 1 },
  { key: 'VSA_K1', label: 'VSA (K1)', section: 'A', k: 'K1', type: 'VSA', marks: 1 },
  { key: 'VSA_K2', label: 'VSA (K2)', section: 'A', k: 'K2', type: 'VSA', marks: 1 },
  { key: 'VSA_K3', label: 'VSA (K3)', section: 'A', k: 'K3', type: 'VSA', marks: 1 },
  { key: 'VSA_K4', label: 'VSA (K4)', section: 'A', k: 'K4', type: 'VSA', marks: 1 },
  { key: 'PARA_K15', label: 'PARA (K1-K5)', section: 'B', k: 'K1-K5', type: 'PARA', marks: 5 },
  { key: 'ESSAY_K14', label: 'ESSAY (K1-K4)', section: 'C', k: 'K1-K4', type: 'ESSAY', marks: 10 },
  { key: 'COMP_K5', label: 'COMP (K5)', section: 'D', k: 'K5', type: 'COMP', marks: 10 }
];

// All 30 Canonical Question Slot Labels (75 Marks total: Section A 20×1M=20M, Section B 5×5M=25M, Section C 2×10M=20M, Section D 1×10M=10M)
const ALL_QUESTION_SLOTS = [
  { num: '1', sec: 'A', type: 'MCQ (1M, K1-K3)', marks: 1, expectedType: 'MCQ' },
  { num: '2', sec: 'A', type: 'MCQ (1M, K1-K3)', marks: 1, expectedType: 'MCQ' },
  { num: '3', sec: 'A', type: 'MCQ (1M, K1-K3)', marks: 1, expectedType: 'MCQ' },
  { num: '4', sec: 'A', type: 'MCQ (1M, K1-K3)', marks: 1, expectedType: 'MCQ' },
  { num: '5', sec: 'A', type: 'MCQ (1M, K1-K3)', marks: 1, expectedType: 'MCQ' },
  { num: '6', sec: 'A', type: 'MCQ (1M, K1-K3)', marks: 1, expectedType: 'MCQ' },
  { num: '7', sec: 'A', type: 'MCQ (1M, K1-K3)', marks: 1, expectedType: 'MCQ' },
  { num: '8', sec: 'A', type: 'MCQ (1M, K1-K3)', marks: 1, expectedType: 'MCQ' },
  { num: '9', sec: 'A', type: 'MATCH (1M, K1)', marks: 1, expectedType: 'MATCH' },
  { num: '10', sec: 'A', type: 'AR (1M, K2)', marks: 1, expectedType: 'ASSERTION_REASON' },
  { num: '11', sec: 'A', type: 'VSA (1M, K1-K4)', marks: 1, expectedType: 'VSA' },
  { num: '12', sec: 'A', type: 'VSA (1M, K1-K4)', marks: 1, expectedType: 'VSA' },
  { num: '13', sec: 'A', type: 'VSA (1M, K1-K4)', marks: 1, expectedType: 'VSA' },
  { num: '14', sec: 'A', type: 'VSA (1M, K1-K4)', marks: 1, expectedType: 'VSA' },
  { num: '15', sec: 'A', type: 'VSA (1M, K1-K4)', marks: 1, expectedType: 'VSA' },
  { num: '16', sec: 'A', type: 'VSA (1M, K1-K4)', marks: 1, expectedType: 'VSA' },
  { num: '17', sec: 'A', type: 'VSA (1M, K1-K4)', marks: 1, expectedType: 'VSA' },
  { num: '18', sec: 'A', type: 'VSA (1M, K1-K4)', marks: 1, expectedType: 'VSA' },
  { num: '19', sec: 'A', type: 'VSA (1M, K1-K4)', marks: 1, expectedType: 'VSA' },
  { num: '20', sec: 'A', type: 'VSA (1M, K1-K4)', marks: 1, expectedType: 'VSA' },
  { num: '21.a', sec: 'B', type: 'PARA (5M)', marks: 5, expectedType: 'PARAGRAPH' },
  { num: '21.b', sec: 'B', type: 'PARA (5M)', marks: 5, expectedType: 'PARAGRAPH' },
  { num: '22.a', sec: 'B', type: 'PARA (5M)', marks: 5, expectedType: 'PARAGRAPH' },
  { num: '22.b', sec: 'B', type: 'PARA (5M)', marks: 5, expectedType: 'PARAGRAPH' },
  { num: '23.a', sec: 'B', type: 'PARA (5M)', marks: 5, expectedType: 'PARAGRAPH' },
  { num: '23.b', sec: 'B', type: 'PARA (5M)', marks: 5, expectedType: 'PARAGRAPH' },
  { num: '24.a', sec: 'B', type: 'PARA (5M)', marks: 5, expectedType: 'PARAGRAPH' },
  { num: '24.b', sec: 'B', type: 'PARA (5M)', marks: 5, expectedType: 'PARAGRAPH' },
  { num: '25.a', sec: 'B', type: 'PARA (5M)', marks: 5, expectedType: 'PARA' },
  { num: '25.b', sec: 'B', type: 'PARA (5M)', marks: 5, expectedType: 'PARA' },
  { num: '26', sec: 'C', type: 'ESSAY (10M)', marks: 10, expectedType: 'ESSAY' },
  { num: '27', sec: 'C', type: 'ESSAY (10M)', marks: 10, expectedType: 'ESSAY' },
  { num: '28', sec: 'C', type: 'ESSAY (10M)', marks: 10, expectedType: 'ESSAY' },
  { num: '29', sec: 'D', type: 'COMPULSORY (10M)', marks: 10, expectedType: 'ESSAY' }
];

// In-Memory Matrix State: Map of key `${rowId}_${colKey}` -> question_number string
let matrixState = {};
let bankQuestionState = {};
let activeModalRow = null;
let activeModalCol = null;
let activeModalBankQuestion = null;
let selectedEligibilityQuestion = null;

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
    // Default to Canonical TSS Layout (75 Marks: Section A 1×20=20M, Section B 5×5=25M, Section C 2×10=20M, Section D 1×10=10M)
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

// Canonical TSS Pattern (75 Marks Total: Section A 1×20=20M, Section B 5×5=25M, Section C 2×10=20M, Section D 1×10=10M - No 2M)
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
    '4.3_ESSAY_K14': '29',

    '4.1_MCQ_K1': '7',
    '4.1_VSA_K1': '17',
    '4.1_PARA_K15': '24.a',

    '4.2_MCQ_K2': '8',
    '4.2_VSA_K2': '18',
    '4.2_PARA_K15': '24.b',

    // Unit V: Q9 is MATCH (1M), Q10 is AR (1M), Q19, Q20 VSA (1M), Q25.a/b PARA (5M), Q30 COMP (10M)
    '5.1_MATCH_K1': '9',
    '5.1_VSA_K1': '19',
    '5.1_PARA_K15': '25.a',

    '5.2_AR_K2': '10',
    '5.2_VSA_K2': '20',
    '5.2_PARA_K15': '25.b',

    '5.3_COMP_K5': '30'
  };
  bankQuestionState = {};

  if (shouldRender) {
    renderMatrixTable();
    updateQuestionSlotPalette();
    Swal.fire({
      icon: 'success',
      title: 'Standard 75-Marks Pattern Applied',
      text: 'Section A: Q1-Q8 MCQ (1M), Q9 MATCH (1M), Q10 AR (1M), Q11-Q20 VSA (1M). Section B: Q21.a-Q25.b (5M). Section C: Q26-Q29 (10M; answer any 2). Section D: Q30 compulsory (10M). Total: 75 Marks.',
      confirmButtonColor: '#FF5B00',
      customClass: { popup: 'rounded-[28px]' }
    });
  }
}

// Auto Pick 30 Questions Algorithm (75 Marks Total, excluding previous academic year questions)
function autoPick30Questions() {
  matrixState = {
    // Section A: Q1-Q8 (MCQ 1M distributed over Units 1-4)
    '1.1_MCQ_K1': '1',
    '1.2_MCQ_K2': '2',
    '2.1_MCQ_K1': '3',
    '2.2_MCQ_K2': '4',
    '3.1_MCQ_K1': '5',
    '3.2_MCQ_K2': '6',
    '4.1_MCQ_K1': '7',
    '4.2_MCQ_K2': '8',
    // Section A: Q9 MUST be MATCH (1M, K1) at Unit 5.1
    '5.1_MATCH_K1': '9',
    // Section A: Q10 MUST be AR (1M, K2) at Unit 5.2
    '5.2_AR_K2': '10',
    // Section A: Q11-Q20 (VSA 1M distributed over 5 units, 2 per unit)
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
    // Section B: Q21.a - Q25.b (5 Either/Or Pairs across Units 1-5, 5M each)
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
    // Section C: Q26-Q29 (four available essay questions; answer any two)
    '1.3_ESSAY_K14': '26',
    '2.3_ESSAY_K14': '27',
    '3.3_ESSAY_K14': '28',
    '4.3_ESSAY_K14': '29',
    // Section D: Q30 Compulsory at Unit 5.3 (10M, K5)
    '5.3_COMP_K5': '30'
  };

  renderMatrixTable();
  updateQuestionSlotPalette();

  Swal.fire({
    icon: 'success',
    title: '✨ 30 Questions Auto-Picked (75 Marks)',
    html: `
      <div class="text-left text-xs space-y-2">
        <p><strong>Section A (20×1M = 20M):</strong> Q1-Q8 (MCQ 1M), Q9 (MATCH 1M), Q10 (AR 1M), Q11-Q20 (VSA 1M).</p>
        <p><strong>Section B (5×5M = 25M):</strong> Q21.a - Q25.b (5 Either/Or Pairs across Units I-V).</p>
        <p><strong>Section C (2×10M = 20M):</strong> Q26 - Q29 (4 Essay Questions, choose 2).</p>
        <p><strong>Section D (1×10M = 10M):</strong> Q30 (Compulsory 10M at Unit V).</p>
        <p class="text-emerald-700 font-bold">✓ Excluded previous academic year questions. Total: 75 Marks.</p>
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
  if (!colObj) return '1';
  if (colObj.type === 'MCQ' || colObj.type === 'MATCH' || colObj.type === 'AR' || colObj.type === 'VSA') return '1';
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
  // Use the same effective-marks normalization as the suggestion list.
  // Legacy imports can store 1 while Section B/C/D display their effective 5M/10M value.
  const marks = getEffectiveBankQuestionMarks(question);
  if (!Number.isFinite(marks) || marks !== Number(getColumnDisplayMarks(colObj))) return false;
  const sec = String(question.section_type ?? '').toUpperCase().replace('SECTION-','');
  if (sec && sec !== getColumnExpectedSection(colObj)) return false;
  if (isPreviousYearQuestion(question)) return false;
  return String(question.question_text ?? '').trim() !== '';
}

// Get all matching questions in bank for a specific question number slot (e.g. Q1, Q9, Q21.a)
function getEffectiveBankQuestionMarks(question) {
  const explicit = Number(question?.marks || question?.mark || 0);
  const type = normalizeQuestionType(question?.question_type || question?.type || inferBankQuestionType(question));
  const section = normalizeBankSection(question?.section_type || question?.section || question?.section_name || '');
  // This CSV format has no marks column. Older imports may also default long-form marks to 1.
  if (type === 'PARAGRAPH' && (!Number.isFinite(explicit) || explicit <= 0 || explicit === 1 || section === 'B')) return 5;
  if ((type === 'ESSAY' || type === 'COMP') && (!Number.isFinite(explicit) || explicit <= 0 || explicit === 1 || section === 'C' || section === 'D')) return 10;
  if (Number.isFinite(explicit) && explicit > 0) return explicit;
  if (type === 'PARAGRAPH' || section === 'B') return 5;
  if (type === 'ESSAY' || type === 'COMP' || section === 'C' || section === 'D') return 10;
  return 1;
}

function getSlotForBall(slotNum) {
  const n = String(slotNum ?? '').trim();
  const exact = ALL_QUESTION_SLOTS.find(s => s.num === n);
  if (exact) return exact;
  const number = parseInt(n, 10);
  if (!Number.isFinite(number)) return null;
  if (number >= 21 && number <= 25) return { num:n, sec:'B', type:'PARAGRAPH (5M)', marks:5, expectedType:'PARAGRAPH' };
  if (number >= 26 && number <= 28) return { num:n, sec:'C', type:'ESSAY (10M)', marks:10, expectedType:'ESSAY' };
  if (number >= 29 && number <= 30) return { num:n, sec:'D', k:'K5', type:'COMPULSORY / ESSAY (10M)', marks:10, expectedType:'ESSAY' };
  return null;
}

function normalizeBankSection(value) {
  const raw = String(value ?? '').trim().toUpperCase();
  if (!raw) return '';
  const match = raw.match(/(?:SECTION\s*[-:]?\s*)?([A-D])(?:\b|$)/);
  return match ? match[1] : raw.replace(/^SECTION\s*[-:]?\s*/, '');
}

function getEligibleQuestionsForSlot(slotNum) {
  const slotObj = getSlotForBall(slotNum);
  if (!slotObj) return [];
  const data = Array.isArray(QUESTION_BANK_DATA) ? QUESTION_BANK_DATA : [];
  const usedBankNumbers = new Set(Object.values(bankQuestionState || {}).map(v => String(v ?? '').trim()).filter(Boolean));
  const expectedType = normalizeQuestionType(slotObj.expectedType);
  return data.filter(q => {
    const qNum = String(q.q_number ?? q.id ?? '').trim();
    if (!qNum || usedBankNumbers.has(qNum)) return false;
    if (isPreviousYearQuestion(q)) return false;
    if (!String(q.question_text ?? '').trim()) return false;
    const qType = normalizeQuestionType(q.question_type || q.type || inferBankQuestionType(q));
    const marks = getEffectiveBankQuestionMarks(q);
    const sec = normalizeBankSection(q.section_type || q.section || q.section_name || '');
    if (marks !== Number(slotObj.marks)) return false;
    if (sec && sec !== normalizeBankSection(slotObj.sec)) return false;
    if (slotObj.k && !kMatchesQuestion(q.k_level, slotObj.k)) return false;
    if (expectedType === 'PARAGRAPH' && qType !== 'PARAGRAPH') return false;
    else if (expectedType === 'ESSAY' && qType !== 'ESSAY') return false;
    else if (expectedType === 'COMP' && !['ESSAY','COMP'].includes(qType)) return false;
    else if (!['PARAGRAPH','ESSAY','COMP'].includes(expectedType) && qType !== expectedType) return false;

    // A question is only shown if its own Unit + Sub-Unit exists in the matrix
    // and a column for this slot can accept its K-level and question type.
    const row = MATRIX_ROWS.find(r => String(r.unit).trim() === String(q.unit_no ?? '').trim()
      && String(r.sub).trim() === String(q.sub_unit ?? '').trim());
    if (!row) return false;
    const col = getTargetColumnForSlotQuestion(slotObj, q);
    if (!col) return false;
    return getBankQuestionMismatchReasons(q, row, col).length === 0;
  }).sort((a,b) => Number(a.q_number || a.id || 0) - Number(b.q_number || b.id || 0));
}

function getTargetColumnForSlotQuestion(slotObj, bankQ) {
  if (!slotObj || !bankQ) return null;
  const section = String(slotObj.sec || '').toUpperCase();
  const expectedType = normalizeQuestionType(slotObj.expectedType);
  const k = String(bankQ.k_level || 'K1').toUpperCase().trim();
  if (section === 'D') return MATRIX_COLS.find(c => c.key === 'COMP_K5') || null;
  if (section === 'C') return MATRIX_COLS.find(c => c.key === 'ESSAY_K14') || null;
  if (section === 'B') return MATRIX_COLS.find(c => c.key === 'PARA_K15') || null;
  if (expectedType === 'MATCH') return MATRIX_COLS.find(c => c.key.includes('MATCH') && kMatchesQuestion(bankQ.k_level, c.k)) || null;
  if (expectedType === 'ASSERTION_REASON') return MATRIX_COLS.find(c => c.key.includes('AR') && kMatchesQuestion(bankQ.k_level, c.k)) || null;
  if (expectedType === 'MCQ') return MATRIX_COLS.find(c => c.key.includes('MCQ') && c.k === k) || null;
  if (expectedType === 'VSA') return MATRIX_COLS.find(c => c.key.includes('VSA') && c.k === k) || null;
  return null;
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
  el.innerHTML = `<span class="font-black">Loaded ${QUESTION_BANK_DATA.length} questions</span> • <span class="font-black">Units: ${escapeQuestionBankHtml(units.join(', '))}</span> • <span class="font-black">Sub-Units: ${escapeQuestionBankHtml(subs.join(', '))}</span> • Marks Rules: <span class="font-black text-emerald-800">1M (Sec A), 5M (Sec B), 10M (Sec C & D)</span> • Previous-year exclusions: <span class="font-black">${PREVIOUS_YEAR_DATA.length}</span>`;
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
            aria-label="${r.label} • ${c.label} • ${c.marks} marks"
            class="matrix-pill-slot ${fillClass} cursor-default"
            title="${r.label} • ${c.label} (${c.marks}M)${bankQ ? ' • Bank ' + bankQuestionLabel(bankQ) : ''}">
            ${val !== '' ? `<span class="block font-black">Q${val}</span>${bankQ ? `<span class="block text-[8px] opacity-80 mt-0.5">Bank Q${escapeQuestionBankHtml(bankQ.q_number)} • ${c.marks}M</span>` : `<span class="block text-[8px] opacity-70 mt-0.5">${c.marks}M • Bank Q?</span>`}` : `<span class="block text-slate-400 font-black">–</span><span class="block text-[8px] text-slate-400 mt-0.5">${c.marks}M</span>`}
          </button>
        </td>
      `;
    });

    // Total Col
    rowHtml += `<td class="p-2.5 font-black text-center bg-slate-50/80 font-mono text-slate-800">${rowTotal}</td>`;
    tr.innerHTML = rowHtml;
    tbody.appendChild(tr);
  });

  if (selectedEligibilityQuestion) {
    paintEligibleCellsForQuestionSlot(selectedEligibilityQuestion);
  }
}

// Update the Question Number Slot Palette Tracker (1-30 Balls: Gray Base & Orange Highlight for Questions with Live Unit Inspector)
function updateQuestionSlotPalette() {
  const palette = document.getElementById('questionSlotPalette');
  if (!palette) return;
  palette.innerHTML = '';

  const assignedMap = {};
  Object.keys(matrixState).forEach(k => {
    const v = matrixState[k];
    if (v) assignedMap[v] = k;
  });

  for (let number = 1; number <= 30; number++) {
    const slotNum = String(number);
    const slotObj = getSlotForBall(slotNum);
    const eligibleSlotQuestions = getEligibleQuestionsForSlot(slotNum);
    const assignedKey = Object.keys(assignedMap).find(k => String(k) === slotNum || String(k).startsWith(slotNum + '.'));
    const isAssigned = !!assignedKey;
    const hasAvailableQuestions = eligibleSlotQuestions.length > 0;

    const ball = document.createElement('button');
    ball.type = 'button';
    const isSelected = selectedEligibilityQuestion === slotNum;

    // Gray background for default, Orange highlighted background if question number has matching question(s) in bank
    ball.className = `question-number-ball ${isAssigned ? 'has-bank-question' : 'no-bank-question'}${isSelected ? ' is-selected' : ''}`;
    ball.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
    
    let tooltip = `Question #${slotNum} (${slotObj ? slotObj.type : ''}): `;
    if (eligibleSlotQuestions.length) {
      tooltip += `${eligibleSlotQuestions.length} eligible ${slotObj?.expectedType || 'question'} question(s) in bank. Click to view unused matching questions and assign directly from the panel.`;
    } else {
      tooltip += `No matching ${slotObj?.expectedType || ''} questions in bank.`;
    }
    if (isAssigned) tooltip += ` [Assigned at ${assignedMap[assignedKey]}]`;
    ball.title = tooltip;
    
    ball.textContent = slotNum;
    ball.onclick = () => selectEligibilityQuestion(slotNum);
    palette.appendChild(ball);
  }

  const progText = document.getElementById('trackerProgressText');
  const progBar = document.getElementById('trackerProgressBar');
  const assignedNums = new Set(Object.values(matrixState).filter(Boolean).map(value => String(value).split('.')[0]));
  const assignedCount = assignedNums.size;
  if (progText) {
    const pct = Math.round((assignedCount / 30) * 100);
    progText.textContent = `${assignedCount} / 30 Slots Assigned (${pct}%)`;
  }
  if (progBar) {
    progBar.style.width = `${Math.min(100, (assignedCount / 30) * 100)}%`;
  }
}

// When user clicks a question number ball circle (1-30)
function selectEligibilityQuestion(slotNum) {
  selectedEligibilityQuestion = String(slotNum);
  const slotObj = getSlotForBall(selectedEligibilityQuestion) || { num: selectedEligibilityQuestion, sec: 'A', marks: 1, type: 'Question', expectedType: 'Question' };
  const eligibleQuestions = getEligibleQuestionsForSlot(selectedEligibilityQuestion);
  const eligibleCellsCount = paintEligibleCellsForQuestionSlot(selectedEligibilityQuestion);

  updateQuestionSlotPalette();

  const summary = document.getElementById('eligibleQuestionSummary');
  if (summary) {
    summary.innerHTML = `<span class="bg-orange-500 text-white px-2 py-0.5 rounded-full font-mono text-[11px] mr-1">Q#${selectedEligibilityQuestion}</span> <span>${slotObj.type}</span> • <span class="text-orange-950 font-black">${eligibleQuestions.length} bank Qs across ${eligibleCellsCount} unit cells</span>`;
  }

  renderEligibleQuestionsInspector(selectedEligibilityQuestion, slotObj, eligibleQuestions);

  // Scroll smoothly to inspector/matrix view
  document.getElementById('eligibleQuestionsInspector')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Highlight all eligible unit cells in the matrix table for the selected question slot
function paintEligibleCellsForQuestionSlot(slotNum) {
  const slotObj = getSlotForBall(slotNum);
  if (!slotObj) return 0;
  let eligibleCount = 0;
  MATRIX_ROWS.forEach(row => {
    MATRIX_COLS.forEach(col => {
      const cellKey = `${row.id}_${col.key}`;
      const cellBtn = document.getElementById(`cell_btn_${cellKey}`);
      if (!cellBtn) return;
      const colType = normalizeQuestionType(col.type);
      const typeMatches = colType === normalizeQuestionType(slotObj.expectedType) ||
        (slotObj.expectedType === 'ESSAY' && colType === 'COMP');
      const marksMatch = Number(col.marks) === Number(slotObj.marks);
      const sectionMatch = String(col.section || '').toUpperCase() === String(slotObj.sec || '').toUpperCase();
      const hasBankCandidate = typeMatches && marksMatch && sectionMatch &&
        getEligibleBankQuestions(row, col).some(q =>
          normalizeQuestionType(q.question_type || inferBankQuestionType(q)) === normalizeQuestionType(slotObj.expectedType) ||
          (slotObj.expectedType === 'ESSAY' && normalizeQuestionType(q.question_type || inferBankQuestionType(q)) === 'ESSAY')
        );
      const isEligible = typeMatches && marksMatch && sectionMatch && hasBankCandidate;
      if (isEligible) {
        cellBtn.classList.add('eligible-question-cell');
        cellBtn.classList.remove('ineligible-question-cell');
        eligibleCount++;
      } else {
        cellBtn.classList.remove('eligible-question-cell');
        cellBtn.classList.add('ineligible-question-cell');
      }
    });
  });
  return eligibleCount;
}

// Render the rich interactive Unit Questions Inspector Panel
function renderEligibleQuestionsInspector(slotNum, slotObj, eligibleQuestions) {
  const inspector = document.getElementById('eligibleQuestionsInspector');
  const title = document.getElementById('inspectorHeaderTitle');
  const subTitle = document.getElementById('inspectorSubTitle');
  const list = document.getElementById('inspectorQuestionsList');
  if (!inspector || !list) return;

  inspector.classList.remove('hidden');

  if (title) title.textContent = `Eligible Questions for Question #${slotNum} • Type: ${slotObj.expectedType || slotObj.type} • ${slotObj.marks}M`;
  if (subTitle) subTitle.textContent = `${eligibleQuestions.length} eligible question(s) found • Question type: ${slotObj.expectedType || slotObj.type} • Section ${slotObj.sec} • ${slotObj.marks}M`;

  if (eligibleQuestions.length === 0) {
    list.innerHTML = `
      <div class="col-span-full rounded-2xl border border-dashed border-amber-300 bg-amber-50/80 p-6 text-center text-xs text-amber-900">
        <i data-lucide="help-circle" class="w-6 h-6 mx-auto text-amber-600 mb-1.5"></i>
        <div class="font-black">No questions in the Question Bank match Question #${slotNum} (${slotObj.type})</div>
        <p class="text-[11px] text-amber-700 mt-1">Please ensure questions with Section ${slotObj.sec}, ${slotObj.marks}M, and ${slotObj.expectedType} exist in the uploaded question bank.</p>
      </div>
    `;
    if (window.lucide) window.lucide.createIcons();
    return;
  }

  // Group by Sub-Unit
  const byUnit = {};
  eligibleQuestions.forEach(q => {
    const unit = String(q.unit_no || '').trim();
    const sub = String(q.sub_unit || '').trim();
    const groupKey = `${unit}|${sub}`;
    if (!byUnit[groupKey]) byUnit[groupKey] = { unit, sub, questions: [] };
    byUnit[groupKey].questions.push(q);
  });

  let html = '';
  Object.keys(byUnit).sort((a,b) => a.localeCompare(b, undefined, {numeric:true})).forEach(groupKey => {
    const group = byUnit[groupKey];
    const unitNo = group.unit;
    const subUnit = group.sub;
    const questionsInUnit = group.questions;
    html += `
      <div class="bg-white rounded-2xl p-3.5 border border-orange-200/90 shadow-sm space-y-2.5">
        <div class="flex items-center justify-between border-b border-orange-100 pb-2">
          <div class="flex items-center space-x-1.5">
            <span class="bg-orange-500 text-white font-mono font-black text-[10px] px-2 py-0.5 rounded-full">Unit ${escapeQuestionBankHtml(unitNo)} • Sub-Unit ${escapeQuestionBankHtml(subUnit)}</span>
            <span class="text-[11px] font-bold text-slate-800">${questionsInUnit.length} Question${questionsInUnit.length === 1 ? '' : 's'}</span>
          </div>
          <span class="text-[10px] font-black text-amber-800 bg-amber-100 px-2 py-0.5 rounded-full">${slotObj.marks} Mark${slotObj.marks === 1 ? '' : 's'}</span>
        </div>
        <div class="space-y-2 max-h-48 overflow-y-auto custom-scrollbar-y pr-1">
    `;

    questionsInUnit.forEach(q => {
      const qNum = escapeQuestionBankHtml(q.q_number || q.id);
      const k = escapeQuestionBankHtml(q.k_level || 'K1');
      const text = escapeQuestionBankHtml(q.question_text || '');
      const isMatch = normalizeQuestionType(q.question_type || inferBankQuestionType(q)) === 'MATCH';
      const optStr = isMatch ? renderMatchQuestionPreview(q) : (q.options_json ? renderOptionsPreview(q.options_json) : '');

      html += `
        <div class="rounded-xl border border-slate-100 bg-slate-50/70 p-2.5 text-xs hover:border-orange-300 transition space-y-1.5">
          <div class="flex items-center justify-between gap-1.5">
            <div class="flex items-center space-x-1.5">
              <span class="font-mono font-black text-[10px] text-orange-700 bg-orange-100 px-1.5 py-0.5 rounded">Bank Q#${qNum}</span>
              <span class="bg-indigo-50 text-indigo-700 font-black text-[9px] px-1.5 py-0.5 rounded border border-indigo-100">${k}</span>
              ${q.co_level ? `<span class="bg-cyan-50 text-cyan-700 font-bold text-[9px] px-1.5 py-0.5 rounded">${escapeQuestionBankHtml(q.co_level)}</span>` : ''}
            </div>
            <button type="button" onclick="quickAssignQuestionToCell('${escapeQuestionBankHtml(unitNo)}', '${escapeQuestionBankHtml(subUnit)}', '${slotNum}', '${qNum}')" class="px-2.5 py-1 rounded-full bg-orange-500 hover:bg-orange-600 text-white font-black text-[10px] shadow-xs flex items-center space-x-1 transition">
              <span>Assign Q#${slotNum}</span>
            </button>
          </div>
          <p class="text-slate-800 font-medium text-[11px] leading-relaxed line-clamp-2">${text}</p>
          ${optStr ? `<div class="text-[10px] text-slate-500 font-medium line-clamp-1">${optStr}</div>` : ''}
        </div>
      `;
    });

    html += `</div></div>`;
  });

  list.innerHTML = html;
  if (window.lucide) window.lucide.createIcons();
}

function renderOptionsPreview(optsJson) {
  try {
    const opts = typeof optsJson === 'string' ? JSON.parse(optsJson) : optsJson;
    if (Array.isArray(opts)) return opts.map((o,i) => `(${String.fromCharCode(65+i)}) ${o}`).join(' ');
    if (typeof opts === 'object') return Object.entries(opts).map(([k,v]) => `(${k}) ${v}`).join(' ');
  } catch(e) {}
  return '';
}

function renderMatchQuestionPreview(question) {
  let data = question?.options_json ?? question?.options ?? null;
  try { if (typeof data === 'string') data = JSON.parse(data); } catch (e) { data = null; }
  const pick = (obj, keys) => {
    if (!obj || typeof obj !== 'object') return null;
    for (const key of keys) if (obj[key] != null) return obj[key];
    return null;
  };
  const left = pick(data, ['column_a','columnA','left','A','a','column_1','column1']);
  const right = pick(data, ['column_b','columnB','right','B','b','column_2','column2']);
  const answer = question?.answer_key ?? pick(data, ['answer_key','answerKey','answers','key']) ?? '';
  const toEntries = value => {
    if (Array.isArray(value)) return value.map((v,i)=>[String(i+1), v]);
    if (value && typeof value === 'object') return Object.entries(value);
    if (typeof value === 'string' && value.trim()) return value.split(/\n|\r/).map((v,i)=>[String(i+1),v.trim()]).filter(x=>x[1]);
    return [];
  };
  const a = toEntries(left), b = toEntries(right);
  if (!a.length && !b.length) return '';
  const rows = Math.max(a.length,b.length);
  let html = '<div class="mt-2 overflow-x-auto"><table class="w-full border-collapse text-[11px]"><thead><tr><th class="border bg-slate-100 p-1 text-left">Column A (Left)</th><th class="border bg-slate-100 p-1 text-left">Column B (Right)</th></tr></thead><tbody>';
  for(let i=0;i<rows;i++){
    const av=a[i], bv=b[i];
    html += `<tr><td class="border p-1 align-top"><b>${escapeQuestionBankHtml(av?.[0] ?? '')}</b>. ${escapeQuestionBankHtml(av?.[1] ?? '')}</td><td class="border p-1 align-top"><b>${escapeQuestionBankHtml(bv?.[0] ?? '')}</b>. ${escapeQuestionBankHtml(bv?.[1] ?? '')}</td></tr>`;
  }
  html += '</tbody></table>';
  if (String(answer).trim()) html += `<div class="mt-1 rounded bg-emerald-50 p-1 text-emerald-800"><b>Answer key:</b> ${escapeQuestionBankHtml(answer)}</div>`;
  return html + '</div>';
}

// 1-Click Quick Assignment from Inspector into Matrix Cell
function quickAssignQuestionToCell(unitNo, subUnit, slotNum, bankQNum) {
  const rowObj = MATRIX_ROWS.find(r => String(r.unit).trim() === String(unitNo).trim()
    && String(r.sub).trim() === String(subUnit).trim());
  const slotObj = getSlotForBall(slotNum);
  const bankQ = getBankQuestionByNumber(bankQNum);
  if (!rowObj || !slotObj || !bankQ) {
    Swal.fire({ icon: 'error', title: 'Unable to assign question', text: 'The matching Unit/Sub-Unit, paper slot, or question-bank question could not be found.', confirmButtonColor: '#FF5B00' });
    return;
  }
  const alreadyUsedAt = Object.entries(bankQuestionState || {}).find(([key, value]) => String(value) === String(bankQ.q_number) && key !== `${rowObj.id}_${getTargetColumnForSlotQuestion(slotObj, bankQ)?.key || ''}`);
  if (alreadyUsedAt) {
    Swal.fire({ icon: 'info', title: 'Question already used', text: `Bank Q${bankQ.q_number} is already assigned. Choose an unused question from the list.`, confirmButtonColor: '#FF5B00' });
    return;
  }
  const targetCol = getTargetColumnForSlotQuestion(slotObj, bankQ);
  if (!targetCol) {
    Swal.fire({ icon: 'error', title: 'No matching matrix column', text: `No column is configured for Section ${slotObj.sec}, ${slotObj.expectedType}, ${slotObj.marks}M.`, confirmButtonColor: '#FF5B00' });
    return;
  }
  const mismatchReasons = getBankQuestionMismatchReasons(bankQ, rowObj, targetCol);
  if (mismatchReasons.length) {
    Swal.fire({ icon: 'error', title: 'Question not eligible', html: `<div class="text-left text-sm"><p class="mb-2">This question is not shown as eligible because:</p><ul class="list-disc pl-5 space-y-1">${mismatchReasons.map(reason => `<li>${escapeQuestionBankHtml(reason)}</li>`).join('')}</ul></div>`, confirmButtonColor: '#FF5B00' });
    return;
  }
  const cellKey = `${rowObj.id}_${targetCol.key}`;
  const currentValue = matrixState[cellKey];
  if (currentValue && String(currentValue) !== String(slotNum)) {
    Swal.fire({ icon: 'warning', title: 'Matrix cell already assigned', text: `Unit ${rowObj.unit} • Sub-Unit ${rowObj.sub} • ${targetCol.label} already contains Question #${currentValue}. Clear that assignment before assigning Question #${slotNum}.`, confirmButtonColor: '#FF5B00' });
    return;
  }
  const slotOccupied = Object.entries(matrixState).find(([key, value]) => key !== cellKey && String(value) === String(slotNum));
  if (slotOccupied) {
    Swal.fire({ icon: 'warning', title: 'Paper slot already assigned', text: `Question #${slotNum} is already assigned elsewhere. Clear its existing assignment before moving it.`, confirmButtonColor: '#FF5B00' });
    return;
  }
  matrixState[cellKey] = String(slotNum);
  bankQuestionState[cellKey] = String(bankQ.q_number);
  renderMatrixTable();
  updateQuestionSlotPalette();
  if (selectedEligibilityQuestion) selectEligibilityQuestion(selectedEligibilityQuestion);
  Swal.fire({ icon: 'success', title: `Assigned Question #${slotNum}`, text: `Bank Q#${bankQ.q_number} assigned to Unit ${rowObj.unit} • Sub-Unit ${rowObj.sub} • ${targetCol.label}.`, timer: 1600, showConfirmButton: false, customClass: { popup: 'rounded-[28px]' } });
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
  if (marks >= 10 || sec.includes('SECTION-C') || sec === 'C' || sec === 'D') return 'ESSAY';
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

function getBankQuestionMismatchReasons(question, rowObj, colObj) {
  const reasons = [];
  if (!question || !rowObj || !colObj) return reasons;
  const qUnit = String(question.unit_no ?? '').trim();
  const qSub = String(question.sub_unit ?? '').trim();
  const qK = String(question.k_level ?? '').trim().toUpperCase();
  const qMarks = getEffectiveBankQuestionMarks(question);
  const qType = normalizeQuestionType(question.question_type || inferBankQuestionType(question));
  const expectedType = normalizeQuestionType(colObj.type);
  const expectedMarks = Number(getColumnDisplayMarks(colObj));
  const qSection = String(question.section_type ?? '').toUpperCase().replace('SECTION-','');
  const expectedSection = getColumnExpectedSection(colObj);
  if (qUnit !== String(rowObj.unit ?? '').trim()) reasons.push(`Unit ${qUnit || 'blank'} (requires ${rowObj.unit})`);
  if (qSub !== String(rowObj.sub ?? '').trim()) reasons.push(`Sub-Unit ${qSub || 'blank'} (requires ${rowObj.sub})`);
  if (!kMatchesQuestion(qK, colObj.k)) reasons.push(`${qK || 'blank'} K-Level (requires ${colObj.k})`);
  if (qType !== expectedType && !(expectedType === 'ESSAY' && colObj.type === 'COMP' && qType === 'ESSAY')) reasons.push(`${qType || 'blank'} type (requires ${expectedType})`);
  if (!Number.isFinite(qMarks) || qMarks !== expectedMarks) reasons.push(`${Number.isFinite(qMarks) ? qMarks : 'blank'}M (requires ${expectedMarks}M)`);
  if (qSection && qSection !== expectedSection) reasons.push(`Section ${qSection} (requires ${expectedSection})`);
  if (isPreviousYearQuestion(question)) reasons.push('previous-year duplicate');
  if (!String(question.question_text ?? '').trim()) reasons.push('empty question text');
  return reasons;
}

function getEligibleBankDiagnostics(rowObj, colObj) {
  const data = Array.isArray(QUESTION_BANK_DATA) ? QUESTION_BANK_DATA : [];
  const exact = [];
  const reasons = {};
  data.forEach(q => {
    const r = getBankQuestionMismatchReasons(q, rowObj, colObj);
    if (!r.length) exact.push(q);
    else reasons[String(q.q_number ?? q.id ?? '')] = r;
  });
  exact.sort((a,b) => Number(a.q_number || 0) - Number(b.q_number || 0));
  return { exact, reasons };
}

function renderEligibleQuestions(rowObj, colObj) {
  const wrap = document.getElementById('modalEligibleQuestions');
  const count = document.getElementById('modalEligibleCount');
  if (!wrap) return [];
  const diagnostics = getEligibleBankDiagnostics(rowObj, colObj);
  const eligible = diagnostics.exact;
  if (count) count.textContent = `${eligible.length} found`;
  if (!eligible.length) {
    const sameSubUnit = (Array.isArray(QUESTION_BANK_DATA) ? QUESTION_BANK_DATA : [])
      .filter(q => String(q.sub_unit ?? '').trim() === String(rowObj.sub ?? '').trim())
      .sort((a,b) => Number(a.q_number || 0) - Number(b.q_number || 0));
    wrap.innerHTML = `<div class="rounded-xl border border-dashed border-emerald-200 bg-white p-3 text-[10px] text-slate-600">
      <div class="text-center font-black text-slate-700">No eligible questions found for <strong>${escapeQuestionBankHtml(rowObj.sub)}</strong> • <strong>${escapeQuestionBankHtml(colObj.k)}</strong> • <strong>${escapeQuestionBankHtml(colObj.type)}</strong> (${escapeQuestionBankHtml(getColumnDisplayMarks(colObj))}M).</div>
      <div class="mt-2 text-center text-[9px] text-slate-400">Questions must strictly match Unit, K-Level, Section, Question Type and ${getColumnDisplayMarks(colObj)}M.</div>
    </div>`;
    return eligible;
  }
  wrap.innerHTML = eligible.map(q => {
    const num = escapeQuestionBankHtml(q.q_number ?? '');
    const marks = escapeQuestionBankHtml(q.marks ?? '');
    const k = escapeQuestionBankHtml(q.k_level ?? '');
    const text = escapeQuestionBankHtml(q.question_text ?? '');
    const selected = activeModalBankQuestion && String(activeModalBankQuestion.q_number) === String(q.q_number);
    return `<button type="button" class="w-full text-left rounded-xl border ${selected ? 'border-orange-400 bg-orange-50' : 'border-emerald-100 bg-white hover:border-orange-300 hover:bg-orange-50/50'} px-2.5 py-2 transition" onclick="selectEligibleBankQuestion(${Number(q.q_number)})">
      <div class="flex items-center gap-1.5 flex-wrap"><span class="font-mono font-black text-[10px] text-orange-700">Q${num}</span><span class="font-black text-[9px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-700">${marks}M</span><span class="font-black text-[9px] px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700">${k}</span><span class="font-black text-[9px] px-1.5 py-0.5 rounded bg-orange-50 text-orange-800">${escapeQuestionBankHtml(normalizeQuestionType(q.question_type || inferBankQuestionType(q)))}</span></div>
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
  document.getElementById('modalCellSubtitle').textContent = `${colObj.label} (${colObj.marks}M) • ${rowObj.sub} • ${colObj.k}`;

  const cellKey = `${rowId}_${colKey}`;
  const currVal = matrixState[cellKey] || '';
  const input = document.getElementById('modalCellInput');
  input.value = currVal;

  const savedBankQuestion = getBankQuestionByNumber(bankQuestionState[cellKey] || '');
  if (savedBankQuestion && bankQuestionMatchesCell(savedBankQuestion, rowObj, colObj)) {
    activeModalBankQuestion = savedBankQuestion;
    input.dataset.bankQuestion = String(savedBankQuestion.q_number);
  } else {
    activeModalBankQuestion = null;
    input.dataset.bankQuestion = '';
    if (bankQuestionState[cellKey]) delete bankQuestionState[cellKey];
  }

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
      Swal.fire({icon:'error', title:'Question Bank Mismatch', text:`Bank Q${bankQ.q_number} does not match ${rowObj.label} • ${colObj.label} (${getColumnDisplayMarks(colObj)}M). Check Unit/Sub-Unit, K-Level, section, question type and marks.`, confirmButtonColor:'#FF5B00'});
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

// Single Cell Validator (75 Marks scheme rules)
function validateSingleCell(rowId, colKey, val) {
  if (!val) return { valid: true };

  // Rule 1: Question 9 MUST be MATCH (1M)
  if (val === '9' && !colKey.includes('MATCH')) {
    return { valid: false, message: 'Question #9 MUST be assigned under the MATCH column (MATCH K1, 1M).' };
  }
  if (colKey.includes('MATCH') && val !== '9') {
    return { valid: false, message: 'The MATCH column is strictly reserved for Question #9 (1M).' };
  }

  // Rule 2: Question 10 MUST be AR (1M)
  if (val === '10' && !colKey.includes('AR')) {
    return { valid: false, message: 'Question #10 MUST be assigned under the AR column (Assertion & Reason K2, 1M).' };
  }
  if (colKey.includes('AR') && val !== '10') {
    return { valid: false, message: 'The AR column is strictly reserved for Question #10 (1M).' };
  }

  // Rule 3: Questions 1 to 8 MUST be MCQ (1M)
  if (isNumeric(val) && Number(val) >= 1 && Number(val) <= 8) {
    if (!colKey.includes('MCQ')) {
      return { valid: false, message: `Question #${val} MUST be assigned under MCQ columns (1M).` };
    }
  }

  // Rule 4: Questions 11 to 20 MUST be Section A Short Answer (VSA 1M)
  if (isNumeric(val) && Number(val) >= 11 && Number(val) <= 20) {
    if (!colKey.includes('VSA')) {
      return { valid: false, message: `Question #${val} MUST be assigned under Section A VSA columns (1M).` };
    }
  }

  // Rule 5: Section B Either/Or pairs (21.a to 25.b - 5M)
  if (val.startsWith('21.') || val.startsWith('22.') || val.startsWith('23.') || val.startsWith('24.') || val.startsWith('25.')) {
    if (!colKey.includes('PARA')) {
      return { valid: false, message: `Either/Or Question #${val} MUST be assigned under Section B PARA (5M).` };
    }
  }

  // Rule 6: Section C Essay (26, 27, 28 - 10M)
  if (['26','27','28'].includes(val) && !colKey.includes('ESSAY')) {
    return { valid: false, message: `Essay Question #${val} MUST be assigned under Section C ESSAY (10M).` };
  }

  // Rule 7: Section D Compulsory (29 or 30 - 10M)
  if (['29','30'].includes(val) && !colKey.includes('COMP') && !colKey.includes('ESSAY')) {
    return { valid: false, message: `Compulsory Question #${val} MUST be assigned under Section D COMP (10M).` };
  }

  return { valid: true };
}

function isNumeric(n) {
  return !isNaN(parseFloat(n)) && isFinite(n);
}

// Full Matrix Validator
function validateEntireMatrix(showSuccessPopup = false) {
  const errors = [];
  const assigned = {};
  const alertBox = document.getElementById('matrixValidationAlert');
  const detailsBox = document.getElementById('matrixValidationDetails');

  document.querySelectorAll('.matrix-pill-slot').forEach(el => el.classList.remove('shake-drop', 'shake-invalid'));

  // 1. Check all filled cells
  Object.keys(matrixState).forEach(cellKey => {
    const val = matrixState[cellKey];
    if (!val) return;
    const [rowId, colKey] = cellKey.split('_');

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

  // Either/Or pairs must share the same K-Level
  [21,22,23,24,25].forEach(pairNo => {
    const aSlot = `${pairNo}.a`, bSlot = `${pairNo}.b`;
    const aBankNum = Object.keys(matrixState).find(k => matrixState[k] === aSlot);
    const bBankNum = Object.keys(matrixState).find(k => matrixState[k] === bSlot);
    const aQ = aBankNum ? getBankQuestionByNumber(bankQuestionState[aBankNum] || '') : null;
    const bQ = bBankNum ? getBankQuestionByNumber(bankQuestionState[bBankNum] || '') : null;
    if (aQ && bQ) {
      const ka = String(aQ.k_level || '').toUpperCase().trim();
      const kb = String(bQ.k_level || '').toUpperCase().trim();
      if (ka && kb && ka !== kb) {
        errors.push(`Either/Or pair Q${pairNo}.a and Q${pairNo}.b must have the same K-Level; selected ${ka} and ${kb}.`);
        if (aBankNum) triggerCellShakeAndDrop(aBankNum);
        if (bBankNum) triggerCellShakeAndDrop(bBankNum);
      }
    }
  });

  // 2. Check required slots
  if (!assigned['9']) errors.push('Missing Question #9 (MATCH K1, 1M).');
  if (!assigned['10']) errors.push('Missing Question #10 (AR K2, 1M).');

  for (let i = 1; i <= 8; i++) {
    if (!assigned[String(i)]) errors.push(`Missing Section A MCQ Question #${i} (1M).`);
  }
  for (let i = 11; i <= 20; i++) {
    if (!assigned[String(i)]) errors.push(`Missing Section A Short Answer (VSA) Question #${i} (1M).`);
  }

  const paraPairs = ['21.a','21.b','22.a','22.b','23.a','23.b','24.a','24.b','25.a','25.b'];
  paraPairs.forEach(p => {
    if (!assigned[p]) errors.push(`Missing Section B Either/Or Question #${p} (5M).`);
  });

  ['26','27','28'].forEach(e => {
    if (!assigned[e]) errors.push(`Missing Section C Essay Question #${e} (10M).`);
  });

  if (!assigned['29'] && !assigned['30']) {
    errors.push('Missing Section D Compulsory Question #29 (10M).');
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
        title: 'Matrix Validation Passed (75 Marks)',
        text: 'All 30 continuous question slots strictly conform to the Holy Cross College 75-Marks blueprint (Section A 20×1M=20M, Section B 5×5M=25M, Section C 2×10M=20M, Section D 1×10M=10M).',
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
    title: 'Lock & Save Master Blueprint (75 Marks)?',
    text: 'This will store the official 30-Question Paper Matrix contract (Section A 1×20=20M, Section B 5×5=25M, Section C 2×10=20M, Section D 1×10=10M = 75 Marks Total).',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, Save & Lock (75M)',
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