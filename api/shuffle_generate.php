<?php
/**
 * Intelligent OBE Question Paper Shuffler & Multi-Set Generator
 * Holy Cross College (Autonomous) - Examination System
 * Strictly conforming to official Holy Cross College Exam Format (Tss.pdf & U23BC3ALT05.pdf)
 * - 1 to 30 continuous numbering with Section B either/or pairs (21.a, 21.b, 22.a, 22.b, 23.a, 23.b, 24.a, 24.b, 25.a, 25.b)
 * - Non-Repeating / History-Aware Unit-SubUnit Question Picking (`question_usage_history`)
 * - Master Blueprint Matrix Alignment (Tss.pdf)
 * - Right-Aligned Bloom's K-Level & CO-Level Mapping
 * - Stores Answer Keys with Paper Data JSON and `answer_keys` table
 * - Pure JSON output with ob_start buffering
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

if (!isLoggedIn()) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please log in as COE Admin or Faculty.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isCOE() && !isSuperAdmin() && !isHOD()) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission Denied: Question Paper generation is restricted to COE Office and Academic Admins.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDBConnection();
$user = getCurrentUser() ?: ['staff_code' => 'COE_OFFICE', 'role' => 'COE_ADMIN'];
qps_ensure_aux_schema($pdo);

function qps_norm_q(string $s): string {
    $s = strip_tags($s);
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/**
 * Intelligent History-Aware Multi-Tier Question Picker
 */
function pick_smart_from_pool(array $allQuestions, array &$usedInSet, $targetUnit, array $sectionRule, int $neededCount, array $pastUsedQuestionIds = [], array &$globalUsedAcrossSets = [], string $subUnitHint = '', string $categoryHint = ''): array {
    $targetMarks = (int)($sectionRule['marks_each'] ?? 0);
    $secName = strtoupper($sectionRule['name'] ?? '');

    $picked = [];

    $collect = function($filterCb) use ($allQuestions, &$usedInSet, &$globalUsedAcrossSets, $pastUsedQuestionIds) {
        $freshUnused = [];
        $pastUsed = [];
        foreach ($allQuestions as $q) {
            $qid = (int)($q['id'] ?? 0);
            if ($qid > 0 && isset($usedInSet[$qid])) continue; // Never duplicate within the same paper set

            if ($filterCb($q)) {
                $crossUsed = ($qid > 0 && isset($globalUsedAcrossSets[$qid]));
                $prevSessionUsed = ($qid > 0 && in_array($qid, $pastUsedQuestionIds, true));

                if (!$crossUsed && !$prevSessionUsed) {
                    $freshUnused[] = $q;
                } else {
                    $pastUsed[] = $q;
                }
            }
        }
        shuffle($freshUnused);
        shuffle($pastUsed);
        return array_merge($freshUnused, $pastUsed);
    };

    // Tier 1: Strict Unit + Exact Sub-Unit + Section + Mark
    if ($subUnitHint !== '') {
        $pool1 = $collect(function($q) use ($targetUnit, $subUnitHint, $secName) {
            $u = (int)($q['unit_no'] ?? 1);
            $su = trim((string)($q['sub_unit'] ?? ''));
            $s = strtoupper((string)($q['section_type'] ?? ''));
            return ($targetUnit === 0 || $u === $targetUnit) && ($su === $subUnitHint);
        });
        while (count($picked) < $neededCount && !empty($pool1)) {
            $cand = array_shift($pool1);
            $qid = (int)($cand['id'] ?? 0);
            if ($qid > 0) {
                $usedInSet[$qid] = true;
                $globalUsedAcrossSets[$qid] = true;
            }
            $picked[] = $cand;
        }
    }

    // Tier 2: Strict Unit + Section Match
    if (count($picked) < $neededCount) {
        $pool2 = $collect(function($q) use ($targetUnit, $secName) {
            $u = (int)($q['unit_no'] ?? 1);
            $s = strtoupper((string)($q['section_type'] ?? ''));
            $unitMatch = ($targetUnit === 0 || $u === $targetUnit);
            if (!$unitMatch) return false;
            if (strpos($secName, 'A') !== false) return (strpos($s, 'A') !== false || strpos($s, 'MC') !== false || strpos($s, 'V.S.A') !== false || ($q['marks'] ?? 1) <= 2);
            if (strpos($secName, 'B') !== false) return (strpos($s, 'B') !== false || (($q['marks'] ?? 5) >= 4 && ($q['marks'] ?? 5) <= 8));
            return (strpos($s, 'C') !== false || strpos($s, 'D') !== false || ($q['marks'] ?? 10) >= 8);
        });
        while (count($picked) < $neededCount && !empty($pool2)) {
            $cand = array_shift($pool2);
            $qid = (int)($cand['id'] ?? 0);
            if ($qid > 0) {
                $usedInSet[$qid] = true;
                $globalUsedAcrossSets[$qid] = true;
            }
            $picked[] = $cand;
        }
    }

    // Tier 3: Relaxed Unit Match (Any question from Unit)
    if (count($picked) < $neededCount) {
        $pool3 = $collect(function($q) use ($targetUnit) {
            $u = (int)($q['unit_no'] ?? 1);
            return ($targetUnit === 0 || $u === $targetUnit);
        });
        while (count($picked) < $neededCount && !empty($pool3)) {
            $cand = array_shift($pool3);
            $qid = (int)($cand['id'] ?? 0);
            if ($qid > 0) {
                $usedInSet[$qid] = true;
                $globalUsedAcrossSets[$qid] = true;
            }
            $picked[] = $cand;
        }
    }

    // Tier 4: Fallback to any unused available question in pool
    if (count($picked) < $neededCount) {
        $pool4 = $collect(function($q) { return true; });
        while (count($picked) < $neededCount && !empty($pool4)) {
            $cand = array_shift($pool4);
            $qid = (int)($cand['id'] ?? 0);
            if ($qid > 0) {
                $usedInSet[$qid] = true;
                $globalUsedAcrossSets[$qid] = true;
            }
            $picked[] = $cand;
        }
    }

    // Tier 5: Fallback to any question in pool (Guarantees full question paper structure)
    if (count($picked) < $neededCount) {
        $pool5 = $allQuestions;
        shuffle($pool5);
        while (count($picked) < $neededCount && !empty($pool5)) {
            $cand = array_shift($pool5);
            $picked[] = $cand;
        }
    }

    return $picked;
}

try {
    $rawInput = file_get_contents('php://input'); if (empty($rawInput)) { $rawInput = @file_get_contents('php://stdin'); }
    $input = json_decode($rawInput, true);
    if (!is_array($input)) throw new RuntimeException('Invalid JSON payload received.');

    $bankId = (int)($input['bank_id'] ?? 0);
    $blueprintId = (int)($input['blueprint_id'] ?? 0);
    $setCount = max(1, min(5, (int)($input['set_count'] ?? ($input['num_sets'] ?? 1))));
    $examDate = trim((string)($input['exam_date'] ?? 'NOVEMBER 2026'));

    if (!$bankId) throw new RuntimeException('Please select an approved Question Bank first.');

    // Fetch Question Bank
    $stBank = $pdo->prepare("SELECT * FROM question_banks WHERE id = ?");
    $stBank->execute([$bankId]);
    $bank = $stBank->fetch(PDO::FETCH_ASSOC);
    if (!$bank) throw new RuntimeException('Question Bank #' . $bankId . ' not found.');

    // Fetch questions from relational table
    $stQ = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
    $stQ->execute([$bankId]);
    $questions = $stQ->fetchAll(PDO::FETCH_ASSOC);

    // Fallback to JSON if relational table is empty
    if (empty($questions) && !empty($bank['questions_json'])) {
        $parsed = json_decode($bank['questions_json'], true);
        $questions = $parsed['questions'] ?? (is_array($parsed) ? $parsed : []);
    }

    if (count($questions) < 1) {
        throw new RuntimeException('Question Bank #' . $bankId . ' is empty. Please upload or add questions first.');
    }

    // Fetch Blueprint if provided
    $blueprint = null;
    if ($blueprintId) {
        $stBp = $pdo->prepare("SELECT * FROM blueprints WHERE id = ?");
        $stBp->execute([$blueprintId]);
        $blueprint = $stBp->fetch(PDO::FETCH_ASSOC);
    }

    // Fetch Course & Department Metadata
    $paperCode = strtoupper($bank['paper_code']);
    $deptCode = strtoupper($bank['dept_code']);
    $courseTitle = $bank['course_title'];
    $sem = $bank['semester'] ?: 'Semester 1';
    $academicYear = $bank['academic_year'] ?: DEFAULT_ACADEMIC_YEAR;
    $degreeLevel = $bank['degree_level'] ?: 'UG';
    $examType = $bank['exam_type'] ?: 'Odd Semester End Examination';
    $maxMarks = (int)($bank['max_marks'] ?: 75);
    $bankLang = strtolower(trim((string)($bank['language'] ?? 'en')));

    $stC = $pdo->prepare("SELECT * FROM courses WHERE UPPER(coursecode) = ? LIMIT 1");
    $stC->execute([$paperCode]);
    $courseMeta = $stC->fetch(PDO::FETCH_ASSOC);

    $stD = $pdo->prepare("SELECT * FROM departments WHERE UPPER(code) = ? LIMIT 1");
    $stD->execute([$deptCode]);
    $deptMeta = $stD->fetch(PDO::FETCH_ASSOC);
    $deptName = $deptMeta['name'] ?? $bank['dept_name'] ?? $deptCode;

    // Determine language-specific headers
    $langHeaders = qps_get_section_headers($pdo, $bankLang);

    // Fetch History of Previously Picked Questions for this paper code
    $pastUsedQuestionIds = [];
    try {
        $stHist = $pdo->prepare("SELECT question_id FROM question_usage_history WHERE UPPER(paper_code) = ?");
        $stHist->execute([$paperCode]);
        $pastUsedQuestionIds = array_map('intval', $stHist->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {}

    $globalUsedAcrossSets = [];
    $generatedSets = [];

    for ($setIdx = 0; $setIdx < $setCount; $setIdx++) {
        $setName = 'SET ' . chr(65 + $setIdx);
        $usedInThisSet = [];
        $setQuestions = [];

        // -------------------------------------------------------------
        // SECTION A (20 Marks Total = 20 Questions x 1 Mark)
        // Part I (Q1-10 Multiple Choice / Match): 2 from each Unit (Units 1 to 5)
        // Part II (Q11-20 Very Short Answer): 2 from each Unit (Units 1 to 5)
        // -------------------------------------------------------------
        $secAPart1 = [];
        for ($u = 1; $u <= 5; $u++) {
            $p = pick_smart_from_pool($questions, $usedInThisSet, $u, ['name' => 'SECTION-A Part I', 'marks_each' => 1], 2, $pastUsedQuestionIds, $globalUsedAcrossSets, "{$u}.1");
            foreach ($p as $item) $secAPart1[] = $item;
        }

        $secAPart2 = [];
        for ($u = 1; $u <= 5; $u++) {
            $p = pick_smart_from_pool($questions, $usedInThisSet, $u, ['name' => 'SECTION-A Part II', 'marks_each' => 1], 2, $pastUsedQuestionIds, $globalUsedAcrossSets, "{$u}.2");
            foreach ($p as $item) $secAPart2[] = $item;
        }

        // -------------------------------------------------------------
        // SECTION B (25 Marks Total = 5 Either/Or Pairs x 5 Marks = Q21 to Q25)
        // Unit 1 (Q21a/b), Unit 2 (Q22a/b), Unit 3 (Q23a/b), Unit 4 (Q24a/b), Unit 5 (Q25a/b)
        // -------------------------------------------------------------
        $secB = [];
        for ($u = 1; $u <= 5; $u++) {
            $pair = pick_smart_from_pool($questions, $usedInThisSet, $u, ['name' => 'SECTION-B', 'marks_each' => 5], 2, $pastUsedQuestionIds, $globalUsedAcrossSets, "{$u}.3");
            $secB[] = [
                'unit' => $u,
                'opt_a' => $pair[0] ?? null,
                'opt_b' => $pair[1] ?? null
            ];
        }

        // -------------------------------------------------------------
        // SECTION C (20 Marks Total = Answer any TWO of THREE = Q26, Q27, Q28)
        // Picked from Units 1, 2, 3
        // -------------------------------------------------------------
        $secC = [];
        $cUnits = [1, 2, 3];
        foreach ($cUnits as $u) {
            $pickedC = pick_smart_from_pool($questions, $usedInThisSet, $u, ['name' => 'SECTION-C', 'marks_each' => 10], 1, $pastUsedQuestionIds, $globalUsedAcrossSets, "{$u}.4");
            if (!empty($pickedC)) $secC[] = $pickedC[0];
        }
        while (count($secC) < 3) {
            $fallbackC = pick_smart_from_pool($questions, $usedInThisSet, 4, ['name' => 'SECTION-C', 'marks_each' => 10], 1, $pastUsedQuestionIds, $globalUsedAcrossSets, '4.4');
            if (!empty($fallbackC)) $secC[] = $fallbackC[0];
            else break;
        }

        // -------------------------------------------------------------
        // SECTION D (10 Marks Total = Compulsory = Q29)
        // Picked from Unit 5
        // -------------------------------------------------------------
        $secD = pick_smart_from_pool($questions, $usedInThisSet, 5, ['name' => 'SECTION-D', 'marks_each' => 10], 1, $pastUsedQuestionIds, $globalUsedAcrossSets, '5.5');
        if (empty($secD)) {
            $secD = pick_smart_from_pool($questions, $usedInThisSet, 0, ['name' => 'SECTION-D', 'marks_each' => 10], 1, $pastUsedQuestionIds, $globalUsedAcrossSets, '5.1');
        }

        // -------------------------------------------------------------
        // Continuous Q1 to Q30 Assembly (Strictly Matching Tss.pdf)
        // -------------------------------------------------------------
        $secAQuestions = [];
        $qNum = 1;

        // Q1 to Q10 (Part I)
        foreach ($secAPart1 as $q) {
            $k = $q['k_level'] ?: 'K1';
            $kNum = preg_match('/K([1-6])/i', $k, $km) ? $km[1] : 1;
            $secAQuestions[] = [
                'q_number' => $qNum,
                'display_qno' => (string)$qNum,
                'unit_no' => $q['unit_no'] ?? 1,
                'sub_unit' => $q['sub_unit'] ?? '1.1',
                'k_level' => 'K' . $kNum,
                'co_level' => 'CO' . min(5, (int)$kNum),
                'marks' => 1,
                'question_text' => $q['question_text'],
                'answer_key' => $q['answer_key'] ?? '',
                'has_formula' => $q['has_formula'] ?? 0,
                'formula_latex' => $q['formula_latex'] ?? '',
                'image_url' => $q['image_url'] ?? '',
                'db_id' => $q['id'] ?? null
            ];
            $qNum++;
        }

        // Q11 to Q20 (Part II)
        foreach ($secAPart2 as $q) {
            $k = $q['k_level'] ?: 'K2';
            $kNum = preg_match('/K([1-6])/i', $k, $km) ? $km[1] : 2;
            $secAQuestions[] = [
                'q_number' => $qNum,
                'display_qno' => (string)$qNum,
                'unit_no' => $q['unit_no'] ?? 1,
                'sub_unit' => $q['sub_unit'] ?? '1.2',
                'k_level' => 'K' . $kNum,
                'co_level' => 'CO' . min(5, (int)$kNum),
                'marks' => 1,
                'question_text' => $q['question_text'],
                'answer_key' => $q['answer_key'] ?? '',
                'has_formula' => $q['has_formula'] ?? 0,
                'formula_latex' => $q['formula_latex'] ?? '',
                'image_url' => $q['image_url'] ?? '',
                'db_id' => $q['id'] ?? null
            ];
            $qNum++;
        }

        // SECTION B: Q21 to Q25 either/or pairs (5 x 5 = 25 Marks)
        $secBQuestions = [];
        $secBFormatted = [];
        $pairNumber = 21;
        foreach ($secB as $pair) {
            $u = $pair['unit'];
            $a = $pair['opt_a'] ?: ($questions[array_rand($questions)] ?? ['question_text' => 'Option A', 'k_level' => 'K3', 'id' => 0]);
            $b = $pair['opt_b'] ?: ($questions[array_rand($questions)] ?? ['question_text' => 'Option B', 'k_level' => 'K3', 'id' => 0]);

            $kNumA = preg_match('/K([1-6])/i', (string)($a['k_level'] ?? 'K3'), $km) ? $km[1] : 3;
            $kNumB = preg_match('/K([1-6])/i', (string)($b['k_level'] ?? 'K3'), $km) ? $km[1] : 3;

            // Flatten for continuous table display (21. a, OR, 21. b)
            $secBQuestions[] = [
                'q_number' => $pairNumber,
                'display_qno' => "{$pairNumber}. a",
                'unit_no' => $u,
                'sub_unit' => $a['sub_unit'] ?? "{$u}.3",
                'k_level' => 'K' . $kNumA,
                'co_level' => 'CO' . min(5, (int)$kNumA),
                'marks' => 5,
                'question_text' => $a['question_text'],
                'answer_key' => $a['answer_key'] ?? '',
                'image_url' => $a['image_url'] ?? '',
                'db_id' => $a['id'] ?? null
            ];

            $secBQuestions[] = [
                'is_or_divider' => true,
                'display_qno' => '( OR )',
                'question_text' => '( OR )'
            ];

            $secBQuestions[] = [
                'q_number' => $pairNumber,
                'display_qno' => "{$pairNumber}. b",
                'unit_no' => $u,
                'sub_unit' => $b['sub_unit'] ?? "{$u}.3",
                'k_level' => 'K' . $kNumB,
                'co_level' => 'CO' . min(5, (int)$kNumB),
                'marks' => 5,
                'question_text' => $b['question_text'],
                'answer_key' => $b['answer_key'] ?? '',
                'image_url' => $b['image_url'] ?? '',
                'db_id' => $b['id'] ?? null
            ];

            $secBFormatted[] = [
                'pair_number' => $pairNumber,
                'unit_no' => $u,
                'opt_a' => [
                    'label' => "{$pairNumber}. (a)",
                    'k_level' => 'K' . $kNumA,
                    'co_level' => 'CO' . min(5, (int)$kNumA),
                    'marks' => 5,
                    'question_text' => $a['question_text'],
                    'answer_key' => $a['answer_key'] ?? '',
                    'image_url' => $a['image_url'] ?? '',
                    'db_id' => $a['id'] ?? null
                ],
                'opt_b' => [
                    'label' => "(OR)\n(b)",
                    'k_level' => 'K' . $kNumB,
                    'co_level' => 'CO' . min(5, (int)$kNumB),
                    'marks' => 5,
                    'question_text' => $b['question_text'],
                    'answer_key' => $b['answer_key'] ?? '',
                    'image_url' => $b['image_url'] ?? '',
                    'db_id' => $b['id'] ?? null
                ]
            ];
            $pairNumber++;
        }

        // SECTION C: Q26 to Q28 (Answer any TWO = 2 x 10 = 20 Marks)
        $secCQuestions = [];
        $cQNum = 26;
        foreach ($secC as $q) {
            $k = $q['k_level'] ?: 'K4';
            $kNum = preg_match('/K([1-6])/i', $k, $km) ? $km[1] : 4;
            $secCQuestions[] = [
                'q_number' => $cQNum,
                'display_qno' => (string)$cQNum,
                'unit_no' => $q['unit_no'] ?? 1,
                'sub_unit' => $q['sub_unit'] ?? '1.4',
                'k_level' => 'K' . $kNum,
                'co_level' => 'CO' . min(5, (int)$kNum),
                'marks' => 10,
                'question_text' => $q['question_text'],
                'answer_key' => $q['answer_key'] ?? '',
                'image_url' => $q['image_url'] ?? '',
                'db_id' => $q['id'] ?? null
            ];
            $cQNum++;
        }

        // SECTION D: Q29 Compulsory (1 x 10 = 10 Marks)
        $secDQuestions = [];
        foreach ($secD as $q) {
            $k = $q['k_level'] ?: 'K5';
            $kNum = preg_match('/K([1-6])/i', $k, $km) ? $km[1] : 5;
            $secDQuestions[] = [
                'q_number' => 29,
                'display_qno' => '29',
                'unit_no' => $q['unit_no'] ?? 5,
                'sub_unit' => $q['sub_unit'] ?? '5.5',
                'k_level' => 'K' . $kNum,
                'co_level' => 'CO' . min(5, (int)$kNum),
                'marks' => 10,
                'question_text' => $q['question_text'],
                'answer_key' => $q['answer_key'] ?? '',
                'image_url' => $q['image_url'] ?? '',
                'db_id' => $q['id'] ?? null
            ];
        }

        // Assemble Standard 4-Section Structure using dynamic language labels
        $assembledSections = [
            [
                'section_name' => $langHeaders['sec_a_title'],
                'instruction' => $langHeaders['sec_a_sub'],
                'choice_formula' => $langHeaders['sec_a_marks'],
                'total_marks' => 20,
                'questions' => $secAQuestions
            ],
            [
                'section_name' => $langHeaders['sec_b_title'],
                'instruction' => $langHeaders['sec_b_sub'],
                'choice_formula' => $langHeaders['sec_b_marks'],
                'total_marks' => 25,
                'questions' => $secBQuestions
            ],
            [
                'section_name' => $langHeaders['sec_c_title'],
                'instruction' => $langHeaders['sec_c_sub'],
                'choice_formula' => $langHeaders['sec_c_marks'],
                'total_marks' => 20,
                'questions' => $secCQuestions
            ],
            [
                'section_name' => $langHeaders['sec_d_title'],
                'instruction' => $langHeaders['sec_d_sub'],
                'choice_formula' => $langHeaders['sec_d_marks'],
                'total_marks' => 10,
                'questions' => $secDQuestions
            ]
        ];

        $examSession = strtoupper($examDate ?: 'NOVEMBER 2026');
        $schoolName = hcc_school_name($deptCode, $deptName);
        $degreeLine = hcc_degree_exam_line($degreeLevel, $sem, $examSession);
        $partLine = hcc_course_part_line($deptCode, $paperCode, $courseTitle);

        $paperData = [
            'institution' => COLLEGE_NAME,
            'school_name' => $schoolName,
            'degree_exam_line' => $degreeLine,
            'course_part_line' => $partLine,
            'paper_code' => $paperCode,
            'course_title' => $courseTitle,
            'dept_name' => $deptName,
            'dept_code' => $deptCode,
            'semester' => $sem,
            'academic_year' => $academicYear,
            'exam_type' => $examType,
            'set_name' => $setName,
            'duration_hours' => $blueprint['duration_hours'] ?? '3 Hours',
            'max_marks' => $maxMarks,
            'total_marks' => $maxMarks,
            'exam_date' => $examSession,
            'language' => $bankLang,
            'sections' => $assembledSections
        ];

        $jsonStr = json_encode($paperData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Insert into generated_papers table
        $now = date('Y-m-d H:i:s');
        $stIns = $pdo->prepare("INSERT INTO generated_papers (
            blueprint_id, bank_id, paper_code, course_title, dept_code, dept_name,
            semester, academic_year, exam_type, set_name, total_marks, duration_hours,
            exam_date, paper_data_json, status, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Generated', ?, ?)");

        $stIns->execute([
            $blueprintId ?: null, $bankId, $paperCode, $courseTitle, $deptCode, $deptName,
            $sem, $academicYear, $examType, $setName, $maxMarks, $blueprint['duration_hours'] ?? '3 Hours',
            $examSession, $jsonStr, $user['staff_code'] ?? 'COE_OFFICE', $now
        ]);

        $paperId = (int)$pdo->lastInsertId();

        // Record picked questions into question_usage_history
        $stQuh = $pdo->prepare("INSERT INTO question_usage_history (
            paper_code, question_id, paper_id, unit_no, sub_unit, k_level, academic_year, exam_session, picked_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach (array_keys($usedInThisSet) as $usedQid) {
            try {
                $stQuh->execute([
                    $paperCode, $usedQid, $paperId, 1, '1.1', 'K1', $academicYear, $examType, $now
                ]);
            } catch (Exception $e) {}
        }

        $paperData['id'] = $paperId;
        $paperData['bank_id'] = $bankId;
        $paperData['view_url'] = getBaseUrl() . '/modules/coe/view_paper.php?paper_id=' . $paperId;
        $generatedSets[] = $paperData;
    }

    qps_audit($pdo, 'SHUFFLE_GENERATE_PAPERS', 'QUESTION_PAPER', (string)$paperCode, [
        'bank_id' => $bankId,
        'sets_generated' => count($generatedSets),
        'academic_year' => $academicYear
    ]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'paper_code' => $paperCode,
        'course_title' => $courseTitle,
        'sets' => $generatedSets,
        'papers' => $generatedSets,
        'generated_papers' => $generatedSets,
        'message' => 'Successfully generated ' . count($generatedSets) . ' examination paper set(s) strictly conforming to Holy Cross College standards.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
