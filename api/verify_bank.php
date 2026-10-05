<?php
/**
 * AJAX API for HOD Verification & Approval
 * Submits Question Bank to COE or checks duplicates
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

requireAuth();
$pdo = getDBConnection();
$user = getCurrentUser();

function qps_promote_draft(PDO $pdo, array $draft, array $user): int {
    $pdo->beginTransaction();
    try {
        $now=date('Y-m-d H:i:s');$dec=json_decode((string)$draft['questions_json'],true);$questions=$dec['questions']??[];if(!is_array($questions)||!$questions)throw new RuntimeException('Draft contains no questions.');
        // Keep the INSERT column list and execute values exactly aligned.
        // The previous build declared 28 columns but supplied only 25 runtime values,
        // causing MySQL 1136: Column count doesn't match value count.
        $st=$pdo->prepare("INSERT INTO question_banks (
            staff_code,dept_code,dept_name,paper_code,course_title,semester,academic_year,
            exam_type,regulation,degree_level,max_marks,total_questions,status,questions_json,
            created_at,updated_at,submitted_at,source_format,source_file_name,source_path,
            archive_path,language,hod_reviewed_by,hod_reviewed_at,hod_status,version_no,
            root_bank_id,schema_version
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?, NULL, ?)");
        $st->execute([
            $draft['staff_code'], $draft['dept_code'], $draft['dept_name'], $draft['paper_code'],
            $draft['course_title'], $draft['semester'], $draft['academic_year'], $draft['exam_type'],
            $draft['regulation'], $draft['degree_level'], $draft['max_marks'], count($questions),
            'Approved', $draft['questions_json'], $now, $now, $now,
            $draft['source_format'], $draft['source_file_name'], $draft['source_path'],
            $draft['archive_path'], 'en', $user['staff_code'], $now, 'approved', 1, '5.0'
        ]);
        $bankId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE question_banks SET root_bank_id=? WHERE id=?')->execute([$bankId,$bankId]);
        $ins=$pdo->prepare("INSERT INTO questions (bank_id,course_code,q_number,unit_no,sub_unit,section_type,question_type,question_text,question_json,marks,k_level,co_level,has_formula,formula_latex,image_url,options_json,answer_key,match_column_a,match_column_b,language,created_at,source_question_no,import_schema,parser_version,parser_confidence,validation_status,normalized_text,question_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach($questions as $q){$qt=(string)($q['question_text']??'');$ins->execute([$bankId,$draft['paper_code'],(int)($q['q_number']??0),(int)($q['unit_no']??0),(string)($q['sub_unit']??''),(string)($q['section_type']??''),(string)($q['question_type']??''),$qt,json_encode($q,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)($q['marks']??0),(string)($q['k_level']??''),(string)($q['co_level']??''),(int)($q['has_formula']??0),(string)($q['formula_latex']??''),(string)($q['image_url']??''),!empty($q['options'])?json_encode($q['options'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):($q['options_json']??null),(string)($q['answer_key']??''),(string)($q['match_column_a']??''),(string)($q['match_column_b']??''),(string)($q['language']??'en'),$now,(int)($q['source_question_no']??$q['q_number']??0),(string)($q['import_schema']??'standard-v5'),(string)($q['parser_version']??'v5'),(float)($q['parser_confidence']??1),'VERIFIED',preg_replace('/\s+/u',' ',trim($qt)),hash('sha256',strtolower(trim($qt)))]);}
        $pdo->prepare("DELETE FROM qps_question_bank_drafts WHERE id=?")->execute([$draft['id']]);$pdo->commit();return $bankId;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

try {
    qps_ensure_aux_schema($pdo);
    $rawInput = file_get_contents('php://input'); if (empty($rawInput)) { $rawInput = @file_get_contents('php://stdin'); } $input = json_decode($rawInput, true);
    if (!is_array($input)) throw new RuntimeException('Invalid JSON payload.');

    $bankId=(int)($input['bank_id']??0);$draftId=(int)($input['draft_id']??0);$action=trim((string)($input['action']??'check_duplicates'));
    if($draftId>0&&$action==='verify_draft'){if(!isHOD())throw new RuntimeException('Only the HOD can verify a staff draft.');$ds=$pdo->prepare("SELECT * FROM qps_question_bank_drafts WHERE id=? AND status='SUBMITTED_TO_HOD'");$ds->execute([$draftId]);$draft=$ds->fetch(PDO::FETCH_ASSOC);if(!$draft)throw new RuntimeException('Submitted draft not found.');$newBankId=qps_promote_draft($pdo,$draft,$user);echo json_encode(['success'=>true,'draft_id'=>$draftId,'bank_id'=>$newBankId,'status'=>'Submitted to COE','message'=>'HOD verification passed. Draft cleared and all questions inserted into the master question pool.'],JSON_UNESCAPED_UNICODE);exit;}

    if (!$bankId) throw new RuntimeException('Bank ID is required.');

    $st = $pdo->prepare("SELECT * FROM question_banks WHERE id = ?");
    $st->execute([$bankId]);
    $bank = $st->fetch(PDO::FETCH_ASSOC);
    if (!$bank) throw new RuntimeException('Question bank not found.');

    // Fetch questions
    $stQ = $pdo->prepare("SELECT * FROM questions WHERE bank_id = ? ORDER BY q_number ASC");
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
            'message' => count($duplicates) > 0 ? (count($duplicates) . ' duplicate questions found.') : 'Verification passed: Zero duplicate questions detected.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'submit_to_coe') {
        if (!isHOD() && !isCOE()) {
            throw new RuntimeException('Only the Head of Department (HOD) or COE can verify and submit question banks to COE.');
        }

        $now = date('Y-m-d H:i:s');
        $stUp = $pdo->prepare("UPDATE question_banks SET status = 'Submitted to COE', hod_reviewed_by = ?, hod_reviewed_at = ?, hod_status = 'approved', updated_at = ? WHERE id = ?");
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
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
