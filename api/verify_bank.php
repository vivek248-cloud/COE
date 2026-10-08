<?php
/**
 * AJAX API for HOD Verification & Approval
 * Submits Question Bank to COE or checks duplicates.
 *
 * V29 FIX:
 * - HOD draft promotion previously failed with MySQL 1136 because the
 *   question_banks INSERT declared more columns than the supplied values.
 * - The promotion INSERT is now built from an explicit column/value map and
 *   only includes optional columns when they actually exist in the database.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

requireAuth();
$pdo = getDBConnection();
$user = getCurrentUser();

function qps_promote_draft(PDO $pdo, array $draft, array $user): int
{
    $pdo->beginTransaction();

    try {
        $now = date('Y-m-d H:i:s');
        $decoded = json_decode((string)$draft['questions_json'], true);
        $questions = is_array($decoded) ? ($decoded['questions'] ?? $decoded) : [];

        if (!is_array($questions) || !$questions) {
            throw new RuntimeException('Draft contains no questions.');
        }

        /*
         * IMPORTANT:
         * Do not use a long positional INSERT here. The live hccweb database
         * has evolved through migrations and optional columns are not identical
         * in every installation. The old code had 28 columns but only 27 values,
         * which caused SQLSTATE[21S01] / MySQL 1136.
         */
        $bankValues = [
            'staff_code'        => $draft['staff_code'] ?? null,
            'dept_code'         => $draft['dept_code'] ?? null,
            'dept_name'         => $draft['dept_name'] ?? null,
            'paper_code'        => $draft['paper_code'] ?? null,
            'course_title'      => $draft['course_title'] ?? null,
            'semester'          => $draft['semester'] ?? 'Semester 1',
            'academic_year'     => $draft['academic_year'] ?? DEFAULT_ACADEMIC_YEAR,
            'exam_type'         => $draft['exam_type'] ?? 'Odd Semester End Examination',
            'regulation'        => $draft['regulation'] ?? DEFAULT_REGULATION,
            'degree_level'      => $draft['degree_level'] ?? 'UG',
            'max_marks'         => (int)($draft['max_marks'] ?? 75),
            'total_questions'   => count($questions),
            'status'            => 'Approved',
            'questions_json'    => $draft['questions_json'],
            'created_at'        => $now,
            'updated_at'        => $now,
            'submitted_at'      => $now,
            'source_format'     => $draft['source_format'] ?? null,
            'source_file_name'  => $draft['source_file_name'] ?? null,
            'source_path'       => $draft['source_path'] ?? null,
            'archive_path'      => $draft['archive_path'] ?? null,
            'language'          => 'en',
            'hod_reviewed_by'   => $user['staff_code'] ?? null,
            'hod_reviewed_at'   => $now,
            'hod_status'        => 'approved',
            'version_no'        => 1,
            'root_bank_id'      => null,
        ];

        // schema_version is optional; only write it when the live table has it.
        if (qps_column_exists($pdo, 'question_banks', 'schema_version')) {
            $bankValues['schema_version'] = '5.0';
        }

        $insertColumns = [];
        $insertValues = [];
        $placeholders = [];

        foreach ($bankValues as $column => $value) {
            if (!qps_column_exists($pdo, 'question_banks', $column)) {
                continue;
            }
            $insertColumns[] = '`' . $column . '`';
            $placeholders[] = '?';
            $insertValues[] = $value;
        }

        if (!$insertColumns) {
            throw new RuntimeException('Question bank schema is unavailable.');
        }

        $sql = 'INSERT INTO `question_banks` (' . implode(', ', $insertColumns) .
               ') VALUES (' . implode(', ', $placeholders) . ')';
        $st = $pdo->prepare($sql);
        $st->execute($insertValues);

        $bankId = (int)$pdo->lastInsertId();
        if ($bankId <= 0) {
            throw new RuntimeException('Question bank was not created.');
        }

        if (qps_column_exists($pdo, 'question_banks', 'root_bank_id')) {
            $pdo->prepare('UPDATE question_banks SET root_bank_id = ? WHERE id = ?')
                ->execute([$bankId, $bankId]);
        }

        /*
         * Store every question as a relational row. This keeps the draft
         * promotion complete: no question is silently discarded.
         */
        $questionColumns = [
            'bank_id', 'course_code', 'q_number', 'unit_no', 'sub_unit',
            'section_type', 'question_type', 'question_text', 'question_json',
            'marks', 'k_level', 'co_level', 'has_formula', 'formula_latex',
            'image_url', 'options_json', 'answer_key', 'match_column_a',
            'match_column_b', 'language', 'created_at', 'source_question_no',
            'import_schema', 'parser_version', 'parser_confidence',
            'validation_status', 'normalized_text', 'question_hash'
        ];

        $questionPlaceholders = implode(', ', array_fill(0, count($questionColumns), '?'));
        $ins = $pdo->prepare(
            'INSERT INTO `questions` (`' . implode('`, `', $questionColumns) . '`) VALUES (' .
            $questionPlaceholders . ')'
        );

        foreach ($questions as $q) {
            $qt = (string)($q['question_text'] ?? '');
            $normalized = preg_replace('/\s+/u', ' ', trim($qt));

            $optionsJson = null;
            if (!empty($q['options']) && is_array($q['options'])) {
                $optionsJson = json_encode($q['options'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (!empty($q['options_json'])) {
                $optionsJson = (string)$q['options_json'];
            }

            $questionValues = [
                $bankId,
                $draft['paper_code'] ?? '',
                (int)($q['q_number'] ?? 0),
                (int)($q['unit_no'] ?? 0),
                (string)($q['sub_unit'] ?? ''),
                (string)($q['section_type'] ?? ''),
                (string)($q['question_type'] ?? ''),
                $qt,
                json_encode($q, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int)($q['marks'] ?? 0),
                (string)($q['k_level'] ?? ''),
                (string)($q['co_level'] ?? ''),
                (int)($q['has_formula'] ?? 0),
                (string)($q['formula_latex'] ?? ''),
                (string)($q['image_url'] ?? ''),
                $optionsJson,
                (string)($q['answer_key'] ?? ''),
                (string)($q['match_column_a'] ?? ''),
                (string)($q['match_column_b'] ?? ''),
                (string)($q['language'] ?? 'en'),
                $now,
                (int)($q['source_question_no'] ?? $q['q_number'] ?? 0),
                (string)($q['import_schema'] ?? 'standard-v5'),
                (string)($q['parser_version'] ?? 'v5'),
                (float)($q['parser_confidence'] ?? 1),
                'VERIFIED',
                $normalized,
                hash('sha256', strtolower(trim($qt))),
            ];

            $ins->execute($questionValues);
        }

        // Only remove the draft after the bank and all question rows are safely stored.
        $pdo->prepare('DELETE FROM qps_question_bank_drafts WHERE id = ?')
            ->execute([(int)$draft['id']]);

        qps_audit($pdo, 'HOD_DRAFT_VERIFIED', 'QUESTION_BANK', (string)$bankId, [
            'draft_id' => (int)$draft['id'],
            'paper_code' => $draft['paper_code'] ?? '',
            'question_count' => count($questions),
            'verified_by' => $user['staff_code'] ?? 'HOD',
        ]);

        $pdo->commit();
        return $bankId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

try {
    qps_ensure_aux_schema($pdo);

    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        $rawInput = @file_get_contents('php://stdin');
    }

    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        throw new RuntimeException('Invalid JSON payload.');
    }

    $bankId = (int)($input['bank_id'] ?? 0);
    $draftId = (int)($input['draft_id'] ?? 0);
    $action = trim((string)($input['action'] ?? 'check_duplicates'));

    if ($draftId > 0 && $action === 'verify_draft') {
        if (!isHOD()) {
            throw new RuntimeException('Only the HOD can verify a staff draft.');
        }

        $ds = $pdo->prepare(
            "SELECT * FROM qps_question_bank_drafts WHERE id = ? AND status = 'SUBMITTED_TO_HOD'"
        );
        $ds->execute([$draftId]);
        $draft = $ds->fetch(PDO::FETCH_ASSOC);

        if (!$draft) {
            throw new RuntimeException('Submitted draft not found. It may already have been verified.');
        }

        // Verify department ownership when HOD information is available.
        $hodDept = trim((string)($user['dept_code'] ?? ''));
        $draftDept = trim((string)($draft['dept_code'] ?? ''));
        if ($hodDept !== '' && $draftDept !== '' && strcasecmp($hodDept, $draftDept) !== 0) {
            throw new RuntimeException('You can verify only question-bank drafts from your department.');
        }

        $newBankId = qps_promote_draft($pdo, $draft, $user);

        echo json_encode([
            'success' => true,
            'draft_id' => $draftId,
            'bank_id' => $newBankId,
            'status' => 'Approved',
            'message' => 'HOD verification passed. The draft was stored in the master question bank and all questions were inserted successfully.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$bankId) {
        throw new RuntimeException('Bank ID is required.');
    }

    $st = $pdo->prepare('SELECT * FROM question_banks WHERE id = ?');
    $st->execute([$bankId]);
    $bank = $st->fetch(PDO::FETCH_ASSOC);

    if (!$bank) {
        throw new RuntimeException('Question bank not found.');
    }

    $stQ = $pdo->prepare('SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC');
    $stQ->execute([$bankId]);
    $questions = $stQ->fetchAll(PDO::FETCH_ASSOC);

    if ($action === 'check_duplicates') {
        $seen = [];
        $duplicates = [];

        foreach ($questions as $q) {
            $textNorm = strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', strip_tags($q['question_text'])));
            $textNorm = trim(preg_replace('/\s+/', ' ', $textNorm));
            if ($textNorm === '') continue;

            if (isset($seen[$textNorm])) {
                $duplicates[] = [
                    'q_number' => $q['q_number'],
                    'section' => $q['section_type'],
                    'k_level' => $q['k_level'],
                    'question_text' => $q['question_text'],
                    'matched_with' => 'Question #' . $seen[$textNorm]['q_number']
                ];
            } else {
                $seen[$textNorm] = $q;
            }
        }

        echo json_encode([
            'success' => true,
            'bank_id' => $bankId,
            'duplicate_count' => count($duplicates),
            'duplicates' => $duplicates,
            'message' => count($duplicates) > 0
                ? count($duplicates) . ' duplicate questions found.'
                : 'Verification passed: Zero duplicate questions detected.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'submit_to_coe') {
        if (!isHOD() && !isCOE()) {
            throw new RuntimeException('Only the Head of Department (HOD) or COE can verify and submit question banks to COE.');
        }

        $now = date('Y-m-d H:i:s');
        $stUp = $pdo->prepare(
            "UPDATE question_banks
             SET status = 'Submitted to COE',
                 hod_reviewed_by = ?,
                 hod_reviewed_at = ?,
                 hod_status = 'approved',
                 updated_at = ?
             WHERE id = ?"
        );
        $stUp->execute([$user['staff_code'], $now, $now, $bankId]);

        qps_audit($pdo, 'HOD_SUBMIT_TO_COE', 'QUESTION_BANK', (string)$bankId, [
            'paper_code' => $bank['paper_code'],
            'reviewed_by' => $user['staff_code'],
            'timestamp' => $now
        ]);

        echo json_encode([
            'success' => true,
            'bank_id' => $bankId,
            'status' => 'Submitted to COE',
            'message' => "Question Bank #{$bankId} ({$bank['paper_code']}) successfully verified and submitted to the COE Office for paper generation!"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Unsupported action requested.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
