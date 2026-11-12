<?php
/** Secure POST actions for the additive Dean portal. */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
require_once __DIR__ . '/../../includes/workflow.php';
requireDean();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST required.'); }
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['qps_dean_csrf']) || !hash_equals($_SESSION['qps_dean_csrf'], $token)) { http_response_code(403); exit('Invalid CSRF token. Refresh the review page.'); }
$pdo = getDBConnection();
$user = getCurrentUser();
$draftId = filter_input(INPUT_POST, 'draft_id', FILTER_VALIDATE_INT);
$action = (string)($_POST['action'] ?? '');
if (!$draftId) { http_response_code(400); exit('Invalid draft ID.'); }
$pkgStmt=$pdo->prepare("SELECT * FROM qps_submission_packages WHERE bank_draft_id=? ORDER BY id DESC LIMIT 1");
$pkgStmt->execute([$draftId]);$workflowPackage=$pkgStmt->fetch(PDO::FETCH_ASSOC);
if($action==='submit_coe'){
    if(!$workflowPackage) { http_response_code(409); exit('This course has not been submitted through the centralized Staff → HOD → Dean workflow.'); }
    try {
        $stDraftCheck=$pdo->prepare('SELECT * FROM qps_question_bank_drafts WHERE id=?');$stDraftCheck->execute([$draftId]);$draftCheck=$stDraftCheck->fetch(PDO::FETCH_ASSOC);
        $stBlueprintCheck=$pdo->prepare('SELECT * FROM qps_final_blueprints WHERE id=?');$stBlueprintCheck->execute([(int)$workflowPackage['final_blueprint_id']]);$blueprintCheck=$stBlueprintCheck->fetch(PDO::FETCH_ASSOC);
        if(!$draftCheck||!$blueprintCheck)throw new RuntimeException('Question bank or final blueprint is missing.');
        $slotData=json_decode((string)$blueprintCheck['slots_config'],true);if(isset($slotData['slots']))$slotData=$slotData['slots'];
        $validationContext=qps_rule_context_from_course($pdo,(string)$draftCheck['paper_code'],(string)$draftCheck['semester'],(string)$draftCheck['academic_year']);$validationContext['programme_year']=trim((string)($blueprintCheck['programme_year']??$draftCheck['programme_year']??$draftCheck['programme_level']??''));$validationContext['programme_level']=$validationContext['programme_year'];$validationContext['selected_total_marks']=(int)($blueprintCheck['total_marks']??$draftCheck['total_marks']??$draftCheck['max_marks']??0);$validationContext['total_marks']=$validationContext['selected_total_marks'];$validationContext['language']=strtoupper((string)($blueprintCheck['language']??$draftCheck['language']??'EN'));$validationContext['bank_draft_id']=$draftId;
        $validation=qps_validate_slots($pdo,$validationContext,is_array($slotData)?$slotData:[],'submit');
        if($validation['errors'])throw new RuntimeException('Dean validation failed: '.implode("\n",$validation['errors']));
        $result=qps_transition($pdo,(int)$workflowPackage['id'],'DEAN_APPROVE','Dean approved all three artifacts and sent the course to COE.');
        $pdo->prepare("UPDATE qps_question_bank_drafts SET status='SUBMITTED_TO_COE',review_comments=CONCAT(COALESCE(review_comments,''),?) WHERE id=?")
            ->execute(["\nDean approved at ".date('Y-m-d H:i:s'),$draftId]);
        $pdo->prepare("INSERT INTO qps_dean_review_actions (draft_id,question_id,staff_code,action_type,remark_text,question_version,created_at) VALUES (?,NULL,?,'SUBMITTED_TO_COE',NULL,NULL,NOW())")
            ->execute([$draftId,(string)($user['staff_code']??'DEAN')]);
        header('Location: dashboard.php?msg='.rawurlencode('Course approved and sent to COE.'));exit;
    } catch(Throwable $e){http_response_code(400);exit(htmlspecialchars($e->getMessage()));}
}
try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT id, status, paper_code FROM qps_question_bank_drafts WHERE id = ? FOR UPDATE");
    $st->execute([$draftId]);
    $draft = $st->fetch(PDO::FETCH_ASSOC);
    if (!$draft || $draft['status'] !== 'SUBMITTED_TO_DEAN') throw new RuntimeException('This course is no longer pending Dean review.');
    $staffCode = (string)($user['staff_code'] ?? '');

    if ($action === 'remark') {
        $questionId = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
        $remark = trim((string)($_POST['remarks'] ?? ''));
        if (!$questionId || $remark === '') throw new RuntimeException('Question and correction remark are required.');
        $q = $pdo->prepare('SELECT id, version_no FROM qps_question_bank_draft_questions WHERE id = ? AND draft_id = ? FOR UPDATE');
        $q->execute([$questionId, $draftId]); $question = $q->fetch(PDO::FETCH_ASSOC);
        if (!$question) throw new RuntimeException('Question does not belong to this submission.');
        $up = $pdo->prepare("UPDATE qps_question_bank_draft_questions SET remarks = ?, correction_status = 'NEEDS_CORRECTION' WHERE id = ? AND draft_id = ?");
        $up->execute([$remark, $questionId, $draftId]);
        $log = $pdo->prepare("INSERT INTO qps_dean_review_actions (draft_id, question_id, staff_code, action_type, remark_text, question_version, created_at) VALUES (?, ?, ?, 'REMARK_SENT', ?, ?, NOW())");
        $log->execute([$draftId, $questionId, $staffCode, $remark, (int)$question['version_no']]);
        if(!$workflowPackage) throw new RuntimeException('Workflow package not found for this Dean submission.');
        $pdo->prepare("INSERT INTO qps_remarks(package_id,target_type,target_ref,round_no,author_code,author_name,author_role,to_role,remark_text) VALUES(?,'QUESTION',?,?,?,?,'DEAN','HOD',?)")
            ->execute([(int)$workflowPackage['id'],(string)$questionId,(int)$workflowPackage['round_no'],$staffCode,(string)($user['name']??$staffCode),$remark]);

    } elseif ($action === 'verify_corrected') {
        $questionId = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
        if (!$questionId) throw new RuntimeException('Invalid question.');
        $q = $pdo->prepare('SELECT id, version_no, correction_status FROM qps_question_bank_draft_questions WHERE id = ? AND draft_id = ? FOR UPDATE');
        $q->execute([$questionId, $draftId]); $question = $q->fetch(PDO::FETCH_ASSOC);
        if (!$question || $question['correction_status'] !== 'NEEDS_CORRECTION') throw new RuntimeException('This question has no pending Dean correction.');
        $last = $pdo->prepare("SELECT question_version FROM qps_dean_review_actions WHERE draft_id = ? AND question_id = ? AND action_type = 'REMARK_SENT' ORDER BY id DESC LIMIT 1");
        $last->execute([$draftId, $questionId]); $versionAtRemark = $last->fetchColumn();
        if ($versionAtRemark === false || (int)$question['version_no'] <= (int)$versionAtRemark) throw new RuntimeException('The staff member must save a newer version before Dean verification.');
        $up = $pdo->prepare("UPDATE qps_question_bank_draft_questions SET remarks = NULL, correction_status = 'CLEAN' WHERE id = ? AND draft_id = ?");
        $up->execute([$questionId, $draftId]);
        $log = $pdo->prepare("INSERT INTO qps_dean_review_actions (draft_id, question_id, staff_code, action_type, remark_text, question_version, created_at) VALUES (?, ?, ?, 'CORRECTION_VERIFIED', NULL, ?, NOW())");
        $log->execute([$draftId, $questionId, $staffCode, (int)$question['version_no']]);

    } else {
        throw new RuntimeException('Unknown Dean action.');
    }
    $pdo->commit();
    if($action==='remark' && $workflowPackage){
        qps_transition($pdo,(int)$workflowPackage['id'],'DEAN_RETURN','Dean returned a question-level remark to HOD.');
        $pdo->prepare("UPDATE qps_question_bank_drafts SET status='DEAN_REMARKS',updated_at=NOW() WHERE id=?")->execute([$draftId]);
    }
    header('Location: review.php?draft_id=' . $draftId . '&msg=' . rawurlencode('Action completed successfully.'));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><div style="font:16px Arial;padding:32px"><h2>Dean action not completed</h2><p>' . htmlspecialchars($e->getMessage()) . '</p><p><a href="review.php?draft_id=' . (int)$draftId . '">Return to review</a></p></div>';
}
