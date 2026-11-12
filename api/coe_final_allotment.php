<?php
declare(strict_types=1);

/**
 * COE Final Question Allotment API
 *
 * Reads the existing qps_final_blueprints.slots_config and approved
 * question_banks/questions records. Allocations are stored separately in
 * qps_coe_question_allotments; source questions and blueprint slots are never
 * modified by this endpoint.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';
require_once __DIR__ . '/../includes/question_rules.php';
require_once __DIR__ . '/../includes/workflow.php';

function qps_coe_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isLoggedIn() || qps_workflow_role() !== 'COE') {
    qps_coe_json(['success' => false, 'message' => 'COE access required.'], 403);
}

$pdo = getDBConnection();
$actor = getCurrentUser();
qps_ensure_question_draft_schema($pdo);
qps_ensure_aux_schema($pdo);
qps_ensure_workflow_schema($pdo);

function qps_coe_table_columns(PDO $pdo, string $table): array
{
    // Table names passed here are internal constants, never request values.
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $st = $pdo->query('PRAGMA table_info("' . $table . '")');
        return array_map(static fn($r) => (string)$r['name'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    $st = $pdo->prepare(
        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$table]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function qps_coe_require_columns(PDO $pdo, string $table, array $required): void
{
    $actual = qps_coe_table_columns($pdo, $table);
    $missing = array_values(array_diff($required, $actual));
    if ($missing) {
        throw new RuntimeException(
            "Database table {$table} is missing required column(s): " . implode(', ', $missing) .
            '. Please verify the project migration/schema before retrying.'
        );
    }
}

function qps_coe_ensure_allotment_table(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS qps_coe_question_allotments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                final_blueprint_id INTEGER NOT NULL,
                slot_key VARCHAR(100) NOT NULL,
                slot_number INTEGER NOT NULL,
                section_type VARCHAR(40) NOT NULL,
                question_id INTEGER NOT NULL,
                paper_code VARCHAR(100) NOT NULL,
                academic_year VARCHAR(30) NULL,
                semester VARCHAR(50) NULL,
                assigned_by VARCHAR(100) NOT NULL,
                assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE(final_blueprint_id, slot_key),
                UNIQUE(final_blueprint_id, question_id)
            )"
        );
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS qps_coe_question_allotments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            final_blueprint_id BIGINT UNSIGNED NOT NULL,
            slot_key VARCHAR(100) NOT NULL,
            slot_number INT NOT NULL,
            section_type VARCHAR(40) NOT NULL,
            question_id BIGINT UNSIGNED NOT NULL,
            paper_code VARCHAR(100) NOT NULL,
            academic_year VARCHAR(30) NULL,
            semester VARCHAR(50) NULL,
            assigned_by VARCHAR(100) NOT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_qps_allot_slot (final_blueprint_id, slot_key),
            UNIQUE KEY uq_qps_allot_question (final_blueprint_id, question_id),
            KEY idx_qps_allot_paper (paper_code, academic_year, semester)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function qps_coe_slot_key(array $slot, int $index): string
{
    $key = $slot['slot_key'] ?? $slot['slot_id'] ?? $slot['id'] ?? null;
    if ($key !== null && trim((string)$key) !== '') {
        return trim((string)$key);
    }

    $section = (string)($slot['section_type'] ?? $slot['section'] ?? 'SECTION-A');
    $number = (int)($slot['slot_number'] ?? $slot['question_number'] ?? $slot['q_number'] ?? ($index + 1));
    return $section . '-' . $number;
}

function qps_coe_normalize_slots($raw): array
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }
        $raw = $decoded;
    }

    if (!is_array($raw)) {
        return [];
    }

    // Support both the current flat array and older {"slots":[...]} JSON.
    if (isset($raw['slots']) && is_array($raw['slots'])) {
        $raw = $raw['slots'];
    }

    $slots = [];
    $seen = [];
    foreach ($raw as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $section = strtoupper(trim((string)($row['section_type'] ?? $row['section'] ?? 'SECTION-A')));
        $number = (int)($row['slot_number'] ?? $row['question_number'] ?? $row['q_number'] ?? ($index + 1));
        $key = qps_coe_slot_key($row, (int)$index);

        // Duplicate keys make assignment persistence ambiguous. Do not silently
        // overwrite one slot with another.
        if ($key === '' || isset($seen[$key])) {
            throw new RuntimeException('The saved blueprint contains a missing or duplicate slot key: ' . $key);
        }
        $seen[$key] = true;

        $slots[] = [
            'slot_key' => $key,
            'slot_number' => $number > 0 ? $number : ((int)$index + 1),
            'section_type' => $section,
            'unit_no' => (int)($row['unit_no'] ?? $row['unit'] ?? 0),
            'sub_unit' => trim((string)($row['sub_unit'] ?? $row['subunit'] ?? '')),
            'question_type' => trim((string)($row['question_type'] ?? $row['type'] ?? '')),
            'k_level' => strtoupper(trim((string)($row['k_level'] ?? ''))),
            'co_level' => strtoupper(trim((string)($row['co_level'] ?? ''))),
            'marks' => (int)($row['marks'] ?? 0),
            'is_compulsory' => !empty($row['is_compulsory']) || !empty($row['compulsory']),
            'choice_group' => trim((string)($row['choice_group'] ?? '')),
            'option_label' => strtolower(trim((string)($row['option_label'] ?? ''))),
        ];
    }

    return $slots;
}

function qps_coe_approved_bank_statuses(): array
{
    return ['APPROVED', 'APPROVED_BY_COE', 'COE_APPROVED', 'VERIFIED', 'PUBLISHED'];
}

/** Resolve each slot's K/CO from question_rules, never from stale client JSON. */
function qps_coe_apply_dean_rules(PDO $pdo, array $blueprint, string $paperCode, array $slots): array
{
    $context = qps_rule_context_from_course(
        $pdo,
        $paperCode,
        (string)($blueprint['semester'] ?? ''),
        (string)($blueprint['academic_year'] ?? '')
    );
    $blueprintProgramme = trim((string)($blueprint['programme_year'] ?? $blueprint['programme_level'] ?? ''));
    if ($blueprintProgramme !== '') {
        $context['programme_year'] = $blueprintProgramme;
        $context['programme_level'] = $blueprintProgramme;
    }
    $blueprintMarks = (int)($blueprint['total_marks'] ?? 0);
    if ($blueprintMarks > 0) {
        $context['selected_total_marks'] = $blueprintMarks;
        $context['total_marks'] = $blueprintMarks;
    }
    $blueprintLanguage = strtoupper(trim((string)($blueprint['language'] ?? '')));
    if ($blueprintLanguage !== '') $context['language'] = $blueprintLanguage;
    $resolved = qps_rules_for_course($pdo, $context);
    foreach ($slots as &$slot) {
        $number = (int)($slot['slot_number'] ?? 0);
        $rule = $resolved['found'] ? ($resolved['rules'][$number] ?? null) : null;
        $slot['rule_available'] = is_array($rule);
        $slot['rule_message'] = is_array($rule) ? '' : ($resolved['message'] ?: "No Dean rule exists for Q{$number}.");
        // Clear any legacy/stale values if there is no matching rule row.
        $slot['k_level'] = is_array($rule) ? strtoupper((string)$rule['k_level']) : '';
        $slot['co_level'] = is_array($rule) ? strtoupper((string)$rule['co_level']) : '';
        $slot['allowed_units'] = [];
        $slot['allowed_subunits'] = [];
        $slot['allowed_subunits_by_unit'] = new stdClass();
    }
    unset($slot);
    return ['slots' => $slots, 'rules' => $resolved, 'context' => $context];
}

/** Attach distinct unit/sub-unit choices that actually contain a matching K/CO question. */
function qps_coe_attach_allowed_units(array $slots, array $pool): array
{
    foreach ($slots as &$slot) {
        if (empty($slot['rule_available'])) continue;
        $units = []; $subunits = []; $byUnit = [];
        foreach ($pool as $question) {
            if (strtoupper(trim((string)($question['k_level'] ?? ''))) !== strtoupper((string)$slot['k_level']) ||
                strtoupper(trim((string)($question['co_level'] ?? ''))) !== strtoupper((string)$slot['co_level'])) continue;
            $unit = (int)($question['unit_no'] ?? 0);
            if ($unit < 1) continue;
            $units[$unit] = $unit;
            $sub = trim((string)($question['sub_unit'] ?? ''));
            if ($sub !== '') { $subunits[$sub] = $sub; $byUnit[$unit][$sub] = $sub; }
        }
        $slot['allowed_units'] = array_values($units);
        sort($slot['allowed_units'], SORT_NUMERIC);
        $slot['allowed_subunits'] = array_values($subunits);
        sort($slot['allowed_subunits'], SORT_NATURAL);
        foreach ($byUnit as &$values) { $values = array_values($values); sort($values, SORT_NATURAL); }
        unset($values);
        $slot['allowed_subunits_by_unit'] = $byUnit;
    }
    unset($slot);
    return $slots;
}

function qps_coe_prior_usage(PDO $pdo, string $paper, array $question, array $blueprint): ?array
{
    if (!function_exists('qps_check_question_prior_usage')) {
        return null;
    }

    try {
        return qps_check_question_prior_usage(
            $pdo,
            $paper,
            (int)($question['id'] ?? 0),
            (int)($question['fixed_q_no'] ?? $question['q_number'] ?? 0),
            (string)($blueprint['academic_year'] ?? ''),
            (string)($blueprint['semester'] ?? '')
        );
    } catch (Throwable $e) {
        // The existing helper can encounter legacy history schemas. Do not
        // crash blueprint rendering; the save path still runs its validation.
        error_log('COE allotment prior-usage check: ' . $e->getMessage());
        return null;
    }
}

try {
    qps_coe_ensure_allotment_table($pdo);

    $action = trim((string)($_GET['action'] ?? ''));

    // Blueprint list: return a useful empty list instead of leaving the
    // browser dropdown stuck on "Loading blueprints...".
    if ($action === 'blueprints') {
        qps_coe_require_columns($pdo, 'qps_final_blueprints', [
            'id', 'paper_code', 'course_title', 'semester', 'academic_year',
            'exam_type', 'total_marks', 'programme_year', 'language', 'status', 'slots_config'
        ]);

        $st = $pdo->query(
            "SELECT id, paper_code, course_title, semester, academic_year,
                    exam_type, total_marks, programme_year, programme_level, language, status, slots_config
             FROM qps_final_blueprints
             WHERE slots_config IS NOT NULL AND TRIM(COALESCE(slots_config, '')) <> ''
               AND (
                 NOT EXISTS (SELECT 1 FROM qps_submission_packages p WHERE UPPER(p.paper_code)=UPPER(qps_final_blueprints.paper_code)
                   AND p.semester=qps_final_blueprints.semester AND p.academic_year=qps_final_blueprints.academic_year AND p.exam_type=qps_final_blueprints.exam_type)
                 OR EXISTS (SELECT 1 FROM qps_submission_packages p WHERE UPPER(p.paper_code)=UPPER(qps_final_blueprints.paper_code)
                   AND p.semester=qps_final_blueprints.semester AND p.academic_year=qps_final_blueprints.academic_year AND p.exam_type=qps_final_blueprints.exam_type
                   AND p.status IN ('COE_APPROVED','ALLOTTED','PAPER_GENERATED'))
               )
             ORDER BY id DESC"
        );

        $blueprints = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $slots = qps_coe_normalize_slots($row['slots_config'] ?? '');
            if (!$slots) {
                continue;
            }

            $blueprints[] = [
                'id' => (int)$row['id'],
                'paper_code' => (string)$row['paper_code'],
                'course_title' => (string)($row['course_title'] ?? ''),
                'semester' => (string)($row['semester'] ?? ''),
                'academic_year' => (string)($row['academic_year'] ?? ''),
                'exam_type' => (string)($row['exam_type'] ?? ''),
                'total_marks' => (int)($row['total_marks'] ?? 0),
                'programme_year' => (string)($row['programme_year'] ?? $row['programme_level'] ?? ''),
                'language' => (string)($row['language'] ?? 'EN'),
                'status' => (string)($row['status'] ?? ''),
                'slot_count' => count($slots),
            ];
        }

        qps_coe_json([
            'success' => true,
            'blueprints' => $blueprints,
            'message' => $blueprints
                ? count($blueprints) . ' active blueprint(s) available.'
                : 'No ACTIVE final blueprint with saved slots was found. Save the Teaching/HOD Master Blueprint Specification first.'
        ]);
    }

    $blueprintId = (int)($_GET['blueprint_id'] ?? 0);
    if ($blueprintId <= 0) {
        throw new RuntimeException('Select a valid final blueprint.');
    }

    qps_coe_require_columns($pdo, 'qps_final_blueprints', [
        'id', 'paper_code', 'course_title', 'semester', 'academic_year',
        'exam_type', 'total_marks', 'programme_year', 'language', 'status', 'slots_config'
    ]);

    $st = $pdo->prepare(
        "SELECT id, paper_code, course_title, semester, academic_year,
                exam_type, total_marks, programme_year, programme_level, language, status, slots_config
         FROM qps_final_blueprints
         WHERE id = ? AND slots_config IS NOT NULL
         LIMIT 1"
    );
    $st->execute([$blueprintId]);
    $blueprint = $st->fetch(PDO::FETCH_ASSOC);

    if (!$blueprint) {
        throw new RuntimeException('The selected blueprint is not ACTIVE or no longer exists. Refresh the page and select an active blueprint.');
    }
    $stWorkflow=$pdo->prepare("SELECT status FROM qps_submission_packages WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND exam_type=? ORDER BY id DESC LIMIT 1");
    $stWorkflow->execute([$blueprint['paper_code'],$blueprint['semester'],$blueprint['academic_year'],$blueprint['exam_type']]);
    $workflowStatus=$stWorkflow->fetchColumn();
    if($workflowStatus!==false && !in_array($workflowStatus,['COE_APPROVED','ALLOTTED','PAPER_GENERATED'],true)){
        throw new RuntimeException('This course is not yet approved by COE for final allotment.');
    }

    $slots = qps_coe_normalize_slots($blueprint['slots_config'] ?? '');
    if (!$slots) {
        throw new RuntimeException('This blueprint has no valid saved slots. Open Teaching/HOD Master Blueprint Specification and save the slot structure.');
    }

    $paperCode = strtoupper(trim((string)$blueprint['paper_code']));
    qps_refresh_question_is_used($pdo,$paperCode,(string)($blueprint['semester']??''),(string)($blueprint['academic_year']??''));
    if ($paperCode === '') {
        throw new RuntimeException('The selected blueprint has no paper/course code.');
    }

    $deanRuleContext = qps_coe_apply_dean_rules($pdo, $blueprint, $paperCode, $slots);
    $slots = $deanRuleContext['slots'];
    $deanRules = $deanRuleContext['rules'];
    $blueprint['programme_year'] = (string)($blueprint['programme_year'] ?: ($deanRuleContext['context']['programme_year'] ?? ''));
    $blueprint['programme_level'] = $blueprint['programme_year'];
    $blueprint['language'] = strtoupper((string)($blueprint['language'] ?: ($deanRuleContext['context']['language'] ?? 'EN')));

    if ($action === 'load') {
        qps_coe_require_columns($pdo, 'qps_coe_question_allotments', [
            'id', 'final_blueprint_id', 'slot_key', 'question_id', 'paper_code'
        ]);
        qps_coe_require_columns($pdo, 'questions', [
            'id', 'bank_id', 'q_number', 'question_text', 'unit_no', 'sub_unit',
            'question_type', 'marks', 'k_level', 'co_level', 'section_type', 'semester', 'academic_year', 'programme_year', 'total_marks', 'language'
        ]);
        qps_coe_require_columns($pdo, 'question_banks', [
            'id', 'paper_code', 'status', 'semester', 'academic_year', 'programme_year', 'total_marks', 'language'
        ]);

        $st = $pdo->prepare(
            "SELECT a.slot_key, a.question_id,
                    q.q_number, q.fixed_q_no, q.question_text, q.unit_no,
                    q.sub_unit, q.question_type, q.marks, q.k_level, q.co_level,
                    q.section_type, q.options_json, q.answer_key,
                    q.match_column_a, q.match_column_b, q.assertion_text,
                    q.reason_text, q.question_json, q.image_url, q.is_used, q.times_used, q.last_used_year, q.semester, q.academic_year, q.programme_year, q.total_marks, q.language
             FROM qps_coe_question_allotments a
             LEFT JOIN questions q ON q.id = a.question_id
             WHERE a.final_blueprint_id = ?"
        );
        $st->execute([$blueprintId]);
        $assignments = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assignments[(string)$row['slot_key']] = $row;
        }

        $statuses = qps_coe_approved_bank_statuses();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql =
            "SELECT q.id, q.bank_id, q.q_number, q.fixed_q_no, q.question_text,
                    q.unit_no, q.sub_unit, q.question_type, q.marks, q.k_level,
                    q.co_level, q.section_type, q.options_json, q.answer_key,
                    q.match_column_a, q.match_column_b, q.assertion_text,
                    q.reason_text, q.question_json, q.image_url, q.is_used, q.times_used, q.last_used_year,
                    COALESCE(NULLIF(q.semester,''),b.semester) AS context_semester,
                    COALESCE(NULLIF(q.academic_year,''),b.academic_year) AS context_academic_year,
                    COALESCE(NULLIF(q.programme_year,''),NULLIF(b.programme_year,''),b.programme_level) AS context_programme_year,
                    COALESCE(NULLIF(q.total_marks,0),b.total_marks) AS context_total_marks,
                    UPPER(COALESCE(NULLIF(q.language,''),NULLIF(b.language,''),'EN')) AS context_language
             FROM questions q
             INNER JOIN question_banks b ON b.id = q.bank_id
             WHERE UPPER(TRIM(b.paper_code)) = UPPER(?)
               AND UPPER(TRIM(COALESCE(b.status, ''))) IN ($statusPlaceholders)
               AND COALESCE(NULLIF(q.semester,''),b.semester) = ?
               AND COALESCE(NULLIF(q.academic_year,''),b.academic_year) = ?
               AND COALESCE(NULLIF(q.programme_year,''),NULLIF(b.programme_year,''),b.programme_level) = ?
               AND COALESCE(NULLIF(q.total_marks,0),b.total_marks) = ?
               AND UPPER(COALESCE(NULLIF(q.language,''),NULLIF(b.language,''),'EN')) = UPPER(?)
             ORDER BY q.unit_no, q.sub_unit, q.q_number, q.id";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$paperCode], $statuses, [(string)$blueprint['semester'],(string)$blueprint['academic_year'],(string)$blueprint['programme_year'],(int)$blueprint['total_marks'],(string)$blueprint['language']]));
        $pool = $st->fetchAll(PDO::FETCH_ASSOC);

        // Only retain approved questions which match at least one authoritative
        // Dean K/CO pair for a slot. The UI then applies that slot's exact rule.
        $allowedPairs = [];
        foreach ($slots as $slot) {
            if (empty($slot['rule_available'])) continue;
            $allowedPairs[strtoupper((string)$slot['k_level']) . '|' . strtoupper((string)$slot['co_level'])] = true;
        }
        if (!$deanRules['found'] || !$allowedPairs) {
            $pool = [];
        } else {
            $pool = array_values(array_filter($pool, static function ($question) use ($allowedPairs) {
                $pair = strtoupper(trim((string)($question['k_level'] ?? ''))) . '|' . strtoupper(trim((string)($question['co_level'] ?? '')));
                return isset($allowedPairs[$pair]);
            }));
        }
        $slots = qps_coe_attach_allowed_units($slots, $pool);

        // Attach a server-calculated eligibility summary. The client still
        // applies the slot's exact unit/subunit/marks/type/K/CO filters.
        foreach ($pool as &$question) {
            $usage = qps_coe_prior_usage($pdo, $paperCode, $question, $blueprint);
            $question['previously_used'] = !empty($question['is_used']) ? 1 : 0;
            $question['previous_usage_message'] = (string)($usage['message'] ?? '');
        }
        unset($question);

        qps_coe_json([
            'success' => true,
            'blueprint' => $blueprint,
            'slots' => $slots,
            'assignments' => $assignments,
            'questions' => $pool,
            'dean_rules_found' => !empty($deanRules['found']),
            'rule_count' => (int)($deanRules['count'] ?? 0),
            'rule_message' => (string)($deanRules['message'] ?? ''),
            'message' => count($pool) . ' approved question(s) found for ' . $paperCode . '.'
        ]);
    }

    if ($action === 'save' && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        if (empty($deanRules['found'])) {
            throw new RuntimeException((string)($deanRules['message'] ?? 'No matching Dean question rules were found.'));
        }
        foreach ($slots as $slot) {
            if (empty($slot['rule_available'])) {
                throw new RuntimeException((string)($slot['rule_message'] ?? ('No Dean rule exists for Q' . (int)$slot['slot_number'] . '.')));
            }
        }
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid JSON request body.');
        }
        $csrf=(string)($_SESSION['qps_review_csrf']??'');
        if($csrf===''||!hash_equals($csrf,(string)($payload['csrf']??''))) throw new RuntimeException('Security token expired. Refresh the page.');
        $chosen = $payload['assignments'] ?? [];
        if (!is_array($chosen)) {
            throw new RuntimeException('Invalid assignment payload.');
        }

        $slotsByKey = [];
        foreach ($slots as $slot) {
            $slotsByKey[$slot['slot_key']] = $slot;
        }

        $selectedIds = [];
        foreach ($chosen as $slotKey => $questionId) {
            $questionId = (int)$questionId;
            if ($questionId <= 0) {
                continue;
            }
            if (!isset($slotsByKey[$slotKey])) {
                throw new RuntimeException('Unknown blueprint slot: ' . $slotKey);
            }
            if (isset($selectedIds[$questionId])) {
                throw new RuntimeException('The same question cannot be assigned to more than one slot.');
            }
            $selectedIds[$questionId] = (string)$slotKey;
        }

        $pdo->beginTransaction();
        try {
            $questionStmt = $pdo->prepare(
                "SELECT id, bank_id, fixed_q_no, q_number, unit_no, sub_unit,
                        question_type, marks, k_level, co_level, section_type,
                        semester, academic_year, programme_year, total_marks, language
                 FROM questions WHERE id = ? LIMIT 1"
            );
            $bankStmt = $pdo->prepare("SELECT paper_code, status, semester, academic_year, programme_year, programme_level, total_marks, language FROM question_banks WHERE id = ? LIMIT 1");

            foreach ($chosen as $slotKey => $questionId) {
                $questionId = (int)$questionId;
                if ($questionId <= 0) {
                    continue;
                }
                if (!isset($slotsByKey[$slotKey])) {
                    throw new RuntimeException('Unknown blueprint slot: ' . $slotKey);
                }

                $slot = $slotsByKey[$slotKey];
                $questionStmt->execute([$questionId]);
                $question = $questionStmt->fetch(PDO::FETCH_ASSOC);
                if (!$question) {
                    throw new RuntimeException("Question for slot {$slotKey} no longer exists.");
                }

                $bankStmt->execute([(int)$question['bank_id']]);
                $bank = $bankStmt->fetch(PDO::FETCH_ASSOC);
                if (
                    !$bank ||
                    strtoupper(trim((string)$bank['paper_code'])) !== $paperCode ||
                    !in_array(strtoupper(trim((string)$bank['status'])), qps_coe_approved_bank_statuses(), true)
                ) {
                    throw new RuntimeException('Only approved questions from this course can be allotted.');
                }

                $questionSemester = (string)($question['semester'] ?: ($bank['semester'] ?? ''));
                $questionYear = (string)($question['academic_year'] ?: ($bank['academic_year'] ?? ''));
                $questionProgramme = (string)($question['programme_year'] ?: ($bank['programme_year'] ?: ($bank['programme_level'] ?? '')));
                $questionMarks = (int)($question['total_marks'] ?: ($bank['total_marks'] ?? 0));
                $questionLanguage = strtoupper((string)($question['language'] ?: ($bank['language'] ?? 'EN')));
                $contextChecks = [
                    [$questionSemester, (string)$blueprint['semester'], 'Semester'],
                    [$questionYear, (string)$blueprint['academic_year'], 'Academic Year'],
                    [$questionProgramme, (string)$blueprint['programme_year'], 'Year of Programme'],
                    [$questionMarks, (int)$blueprint['total_marks'], 'Total Marks'],
                    [$questionLanguage, strtoupper((string)$blueprint['language']), 'Question Language'],
                ];
                foreach ($contextChecks as [$actual, $expected, $label]) {
                    if ((string)$actual !== (string)$expected) throw new RuntimeException("{$label} mismatch. This question belongs to a different academic context.");
                }

                $checks = [
                    ['unit_no', 'Unit', static fn($a, $b) => (int)$a === (int)$b],
                    ['sub_unit', 'Subunit', static fn($a, $b) => trim((string)$a) === trim((string)$b)],
                    ['marks', 'Marks', static fn($a, $b) => (int)$a === (int)$b],
                    ['question_type', 'Question type', static fn($a, $b) => strtoupper(trim((string)$a)) === strtoupper(trim((string)$b))],
                    ['k_level', 'K-level', static fn($a, $b) => strtoupper(trim((string)$a)) === strtoupper(trim((string)$b))],
                    ['co_level', 'CO-level', static fn($a, $b) => strtoupper(trim((string)$a)) === strtoupper(trim((string)$b))],
                ];
                foreach ($checks as [$field, $label, $compare]) {
                    $required = $slot[$field] ?? '';
                    if ($required !== '' && $required !== 0 && $required !== '0' && !$compare($question[$field] ?? '', $required)) {
                        throw new RuntimeException("{$label} mismatch in slot {$slotKey}.");
                    }
                }

                // Previously used questions remain selectable. The UI dims them and
                // offers an optional hide-used toggle; duplicate current assignments are rejected above.
            }

            $delete = $pdo->prepare("DELETE FROM qps_coe_question_allotments WHERE final_blueprint_id = ?");
            $delete->execute([$blueprintId]);

            $insert = $pdo->prepare(
                "INSERT INTO qps_coe_question_allotments
                    (final_blueprint_id, slot_key, slot_number, section_type,
                     question_id, paper_code, academic_year, semester, assigned_by, assigned_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );

            foreach ($chosen as $slotKey => $questionId) {
                $questionId = (int)$questionId;
                if ($questionId <= 0) {
                    continue;
                }
                $slot = $slotsByKey[$slotKey];
                $insert->execute([
                    $blueprintId,
                    $slotKey,
                    $slot['slot_number'],
                    $slot['section_type'],
                    $questionId,
                    $paperCode,
                    $blueprint['academic_year'] ?? null,
                    $blueprint['semester'] ?? null,
                    $actor['staff_code'] ?? 'COE',
                ]);
            }

            // Persist usage metadata at allotment time without changing the assignment algorithm.
            $usageInsert=$pdo->prepare("INSERT INTO question_usage_history
                (paper_code,question_id,paper_id,unit_no,sub_unit,k_level,academic_year,exam_session,picked_at)
                SELECT ?,q.id,NULL,q.unit_no,q.sub_unit,q.k_level,?,?,NOW() FROM questions q
                WHERE q.id=? AND NOT EXISTS (
                  SELECT 1 FROM question_usage_history h WHERE h.paper_code=? AND h.question_id=? AND h.academic_year=? AND h.exam_session=?
                )");
            $usageUpdate=$pdo->prepare("UPDATE questions SET times_used=COALESCE(times_used,0)+1,last_used_exam=?,last_used_semester=?,last_used_year=? WHERE id=?");
            foreach($selectedIds as $questionId=>$slotKey){
                $usageInsert->execute([$paperCode,$blueprint['academic_year']??null,$blueprint['semester']??'',(int)$questionId,$paperCode,(int)$questionId,$blueprint['academic_year']??null,$blueprint['semester']??'']);
                if($usageInsert->rowCount()>0){
                    $usageUpdate->execute([$blueprint['exam_type']??'', $blueprint['semester']??'', $blueprint['academic_year']??'', (int)$questionId]);
                }
            }
            qps_refresh_question_is_used($pdo,$paperCode,(string)($blueprint['semester']??''),(string)($blueprint['academic_year']??''));

            if (function_exists('qps_audit')) {
                qps_audit($pdo, 'COE_FINAL_QUESTION_ALLOTMENT_SAVED', 'FINAL_BLUEPRINT', (string)$blueprintId, [
                    'paper_code' => $paperCode,
                    'assigned_count' => count($selectedIds),
                ]);
            }

            $pdo->commit();
            try {
                $stPkg=$pdo->prepare("SELECT id,status FROM qps_submission_packages WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND exam_type=? ORDER BY id DESC LIMIT 1");
                $stPkg->execute([$paperCode,$blueprint['semester']??'',$blueprint['academic_year']??'',$blueprint['exam_type']??'']);
                $pkg=$stPkg->fetch(PDO::FETCH_ASSOC);
                if($pkg && $pkg['status']==='COE_APPROVED') qps_transition($pdo,(int)$pkg['id'],'COE_ALLOT','Final question allotment saved.');
            } catch(Throwable $workflowError) { error_log('Workflow allotment transition: '.$workflowError->getMessage()); }
            qps_coe_json([
                'success' => true,
                'assigned_count' => count($selectedIds),
                'message' => 'Final question allotment saved successfully.'
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    throw new RuntimeException('Unsupported action. Use action=blueprints, action=load, or POST action=save.');
} catch (Throwable $e) {
    error_log('COE final allotment API error: ' . $e->getMessage());
    qps_coe_json(['success' => false, 'message' => $e->getMessage()], 400);
}
