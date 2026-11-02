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

function qps_coe_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isLoggedIn() || !isCOE()) {
    qps_coe_json(['success' => false, 'message' => 'COE access required.'], 403);
}

$pdo = getDBConnection();
$actor = getCurrentUser();

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
        ];
    }

    return $slots;
}

function qps_coe_approved_bank_statuses(): array
{
    return ['APPROVED', 'APPROVED_BY_COE', 'COE_APPROVED', 'VERIFIED', 'PUBLISHED'];
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
            'exam_type', 'total_marks', 'status', 'slots_config'
        ]);

        $st = $pdo->query(
            "SELECT id, paper_code, course_title, semester, academic_year,
                    exam_type, total_marks, status, slots_config
             FROM qps_final_blueprints
             WHERE slots_config IS NOT NULL AND TRIM(COALESCE(slots_config, '')) <> ''
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
        'exam_type', 'total_marks', 'status', 'slots_config'
    ]);

    $st = $pdo->prepare(
        "SELECT id, paper_code, course_title, semester, academic_year,
                exam_type, total_marks, status, slots_config
         FROM qps_final_blueprints
         WHERE id = ? AND slots_config IS NOT NULL
         LIMIT 1"
    );
    $st->execute([$blueprintId]);
    $blueprint = $st->fetch(PDO::FETCH_ASSOC);

    if (!$blueprint) {
        throw new RuntimeException('The selected blueprint is not ACTIVE or no longer exists. Refresh the page and select an active blueprint.');
    }

    $slots = qps_coe_normalize_slots($blueprint['slots_config'] ?? '');
    if (!$slots) {
        throw new RuntimeException('This blueprint has no valid saved slots. Open Teaching/HOD Master Blueprint Specification and save the slot structure.');
    }

    $paperCode = strtoupper(trim((string)$blueprint['paper_code']));
    if ($paperCode === '') {
        throw new RuntimeException('The selected blueprint has no paper/course code.');
    }

    if ($action === 'load') {
        qps_coe_require_columns($pdo, 'qps_coe_question_allotments', [
            'id', 'final_blueprint_id', 'slot_key', 'question_id', 'paper_code'
        ]);
        qps_coe_require_columns($pdo, 'questions', [
            'id', 'bank_id', 'q_number', 'question_text', 'unit_no', 'sub_unit',
            'question_type', 'marks', 'k_level', 'co_level', 'section_type'
        ]);
        qps_coe_require_columns($pdo, 'question_banks', [
            'id', 'paper_code', 'status'
        ]);

        $st = $pdo->prepare(
            "SELECT a.slot_key, a.question_id,
                    q.q_number, q.fixed_q_no, q.question_text, q.unit_no,
                    q.sub_unit, q.question_type, q.marks, q.k_level, q.co_level,
                    q.section_type, q.options_json, q.answer_key,
                    q.match_column_a, q.match_column_b, q.assertion_text,
                    q.reason_text, q.question_json, q.image_url
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
                    q.reason_text, q.question_json, q.image_url
             FROM questions q
             INNER JOIN question_banks b ON b.id = q.bank_id
             WHERE UPPER(TRIM(b.paper_code)) = UPPER(?)
               AND UPPER(TRIM(COALESCE(b.status, ''))) IN ($statusPlaceholders)
             ORDER BY q.unit_no, q.q_number, q.id";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$paperCode], $statuses));
        $pool = $st->fetchAll(PDO::FETCH_ASSOC);

        // Attach a server-calculated eligibility summary. The client still
        // applies the slot's exact unit/subunit/marks/type/K/CO filters.
        foreach ($pool as &$question) {
            $usage = qps_coe_prior_usage($pdo, $paperCode, $question, $blueprint);
            $question['previously_used'] = ($usage && !empty($usage['is_used'])) ? 1 : 0;
            $question['previous_usage_message'] = (string)($usage['message'] ?? '');
        }
        unset($question);

        qps_coe_json([
            'success' => true,
            'blueprint' => $blueprint,
            'slots' => $slots,
            'assignments' => $assignments,
            'questions' => $pool,
            'message' => count($pool) . ' approved question(s) found for ' . $paperCode . '.'
        ]);
    }

    if ($action === 'save' && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid JSON request body.');
        }
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
                        question_type, marks, k_level, co_level, section_type
                 FROM questions WHERE id = ? LIMIT 1"
            );
            $bankStmt = $pdo->prepare("SELECT paper_code, status FROM question_banks WHERE id = ? LIMIT 1");

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

                $usage = qps_coe_prior_usage($pdo, $paperCode, $question, $blueprint);
                if ($usage && !empty($usage['is_used'])) {
                    throw new RuntimeException(
                        'A selected question was used in an earlier examination: ' .
                        (string)($usage['message'] ?? 'previous usage detected')
                    );
                }
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

            if (function_exists('qps_audit')) {
                qps_audit($pdo, 'COE_FINAL_QUESTION_ALLOTMENT_SAVED', 'FINAL_BLUEPRINT', (string)$blueprintId, [
                    'paper_code' => $paperCode,
                    'assigned_count' => count($selectedIds),
                ]);
            }

            $pdo->commit();
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
