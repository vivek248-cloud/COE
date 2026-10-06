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
        $now=date('Y-m-d H:i:s');
        $dec=json_decode((string)$draft['questions_json'],true);
        $questions=$dec['questions']??[];
        if(!is_array($questions)||!$questions) throw new RuntimeException('Draft contains no questions.');

        // Build INSERTs from the columns that actually exist in the live MySQL
        // schema. The project has evolved through several hccweb schema versions;
        // hard-coded value lists were the cause of SQLSTATE[21S01]/1136 on older
        // and newer installations.
        $tableCols = static function(PDO $pdo, string $table): array {
            $st=$pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION");
            $st->execute([$table]);
            return array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
        };
        $qbCols=$tableCols($pdo,'question_banks');
        if(!$qbCols) throw new RuntimeException('The question_banks table was not found in the current database.');

        $qbData=[
            'staff_code'=>$draft['staff_code']??'', 'dept_code'=>$draft['dept_code']??'', 'dept_name'=>$draft['dept_name']??'',
            'paper_code'=>$draft['paper_code']??'', 'course_title'=>$draft['course_title']??'', 'semester'=>$draft['semester']??'',
            'academic_year'=>$draft['academic_year']??'', 'exam_type'=>$draft['exam_type']??'', 'regulation'=>$draft['regulation']??'',
            'degree_level'=>$draft['degree_level']??'', 'max_marks'=>(int)($draft['max_marks']??75), 'total_questions'=>count($questions),
            'status'=>'Submitted to COE', 'questions_json'=>$draft['questions_json']??'', 'created_at'=>$now, 'updated_at'=>$now,
            'submitted_at'=>$now, 'source_format'=>$draft['source_format']??'', 'source_file_name'=>$draft['source_file_name']??'',
            'source_path'=>$draft['source_path']??'', 'archive_path'=>$draft['archive_path']??'', 'language'=>$draft['language']??'en',
            'hod_reviewed_by'=>$user['staff_code']??'', 'hod_reviewed_at'=>$now, 'hod_status'=>'approved',
            'version_no'=>1, 'root_bank_id'=>null, 'schema_version'=>'5.0'
        ];
        $qbInsert=[];
        foreach($qbData as $col=>$val){ if(in_array(strtolower($col),$qbCols,true)) $qbInsert[$col]=$val; }
        $cols=array_keys($qbInsert);
        $quotedCols=implode(',',array_map(fn($c)=>"`{$c}`",$cols));
        $ph=implode(',',array_fill(0,count($cols),'?'));
        $st=$pdo->prepare("INSERT INTO question_banks ({$quotedCols}) VALUES ({$ph})");
        $st->execute(array_values($qbInsert));
        $bankId=(int)$pdo->lastInsertId();
        if(in_array('root_bank_id',$qbCols,true)) $pdo->prepare('UPDATE question_banks SET root_bank_id=? WHERE id=?')->execute([$bankId,$bankId]);

        $qCols=$tableCols($pdo,'questions');
        if(!$qCols) throw new RuntimeException('The questions table was not found in the current database.');
        foreach($questions as $q){
            $qt=(string)($q['question_text']??'');
            $qData=[
                'bank_id'=>$bankId,'course_code'=>$draft['paper_code']??'','q_number'=>(int)($q['q_number']??0),
                'unit_no'=>(int)($q['unit_no']??0),'sub_unit'=>(string)($q['sub_unit']??''),'section_type'=>(string)($q['section_type']??''),
                'question_type'=>(string)($q['question_type']??''),'question_text'=>$qt,
                'question_json'=>json_encode($q,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'marks'=>(int)($q['marks']??0),
                'k_level'=>(string)($q['k_level']??''),'co_level'=>(string)($q['co_level']??''),'has_formula'=>(int)($q['has_formula']??0),
                'formula_latex'=>(string)($q['formula_latex']??''),'image_url'=>(string)($q['image_url']??''),
                'options_json'=>!empty($q['options'])?json_encode($q['options'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):($q['options_json']??null),
                'answer_key'=>(string)($q['answer_key']??''),'match_column_a'=>(string)($q['match_column_a']??''),'match_column_b'=>(string)($q['match_column_b']??''),
                'language'=>(string)($q['language']??'en'),'created_at'=>$now,'source_question_no'=>(int)($q['source_question_no']??$q['q_number']??0),
                'import_schema'=>(string)($q['import_schema']??'standard-v5'),'parser_version'=>(string)($q['parser_version']??'v5'),
                'parser_confidence'=>(float)($q['parser_confidence']??1),'validation_status'=>'VERIFIED',
                'normalized_text'=>preg_replace('/\s+/u',' ',trim($qt)),'question_hash'=>hash('sha256',strtolower(trim($qt)))
            ];
            $qInsert=[]; foreach($qData as $col=>$val){ if(in_array(strtolower($col),$qCols,true)) $qInsert[$col]=$val; }
            $cols=array_keys($qInsert); $quotedCols=implode(',',array_map(fn($c)=>"`{$c}`",$cols)); $ph=implode(',',array_fill(0,count($cols),'?'));
            $ins=$pdo->prepare("INSERT INTO questions ({$quotedCols}) VALUES ({$ph})");
            $ins->execute(array_values($qInsert));
        }

        $pdo->prepare("DELETE FROM qps_question_bank_drafts WHERE id=?")->execute([$draft['id']]);
        $pdo->commit();
        return $bankId;
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
