<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/system.php';
require_once __DIR__.'/../includes/workflow.php';
$pdo=getDBConnection();
try{
 requireAuth();$in=json_decode((string)file_get_contents('php://input'),true);if(!is_array($in))$in=$_POST;
 $csrf=(string)($_SESSION['qps_review_csrf']??'');
 if($csrf===''||!hash_equals($csrf,(string)($in['csrf']??'')))throw new RuntimeException('Security token expired. Refresh the page.');
 $actor=qps_workflow_actor();$action=(string)($in['action']??'add');
 if($action==='list'){
   $paperCode=strtoupper(trim((string)($in['paper_code']??'')));
   if($paperCode==='')throw new RuntimeException('Course code is required.');
   $st=$pdo->prepare("SELECT p.id FROM qps_submission_packages p WHERE UPPER(p.paper_code)=? AND p.staff_code=? ORDER BY p.id DESC LIMIT 1");
   $st->execute([$paperCode,(string)($actor['code'])]);$pkgId=(int)$st->fetchColumn();
   $items=[];
   if($pkgId){$st=$pdo->prepare("SELECT target_type,target_ref,remark_text,author_role,created_at FROM qps_remarks WHERE package_id=? AND status='OPEN' ORDER BY created_at DESC");$st->execute([$pkgId]);$items=$st->fetchAll(PDO::FETCH_ASSOC);}
   echo json_encode(['success'=>true,'remarks'=>$items]);exit;
 }
 if($action==='add'){
   $pkg=(int)($in['package_id']??0);$type=strtoupper((string)($in['target_type']??'QUESTION'));$ref=trim((string)($in['target_ref']??''));$msg=trim((string)($in['remark_text']??''));
   if($pkg<1||!in_array($type,['QUESTION','QBB','FINAL_SLOT'],true)||$ref===''||$msg==='')throw new RuntimeException('Package, target and remark are required.');
   $allowed=['HOD'=>['STAFF'],'DEAN'=>['HOD']];
   if(!isset($allowed[$actor['role']]))throw new RuntimeException('Only HOD or Dean can add review remarks.');
   $to=$allowed[$actor['role']][0];
   $st=$pdo->prepare('SELECT status,round_no,current_owner_role FROM qps_submission_packages WHERE id=?');$st->execute([$pkg]);$p=$st->fetch(PDO::FETCH_ASSOC);
   if(!$p)throw new RuntimeException('Submission package not found.');
   if (strtoupper((string)$p['current_owner_role']) !== $actor['role']) throw new RuntimeException('This package is read-only for your role.');
   $validStatus=$actor['role']==='HOD'?in_array($p['status'],['SUBMITTED_TO_HOD','HOD_RECHECK'],true):$p['status']==='SUBMITTED_TO_DEAN';
   if(!$validStatus)throw new RuntimeException('This package is not awaiting your review.');
   $st=$pdo->prepare('INSERT INTO qps_remarks(package_id,target_type,target_ref,round_no,author_code,author_name,author_role,to_role,remark_text,correction_json) VALUES(?,?,?,?,?,?,?,?,?,?)');
   $st->execute([$pkg,$type,$ref,(int)$p['round_no'],$actor['code'],$actor['name'],$actor['role'],$to,$msg,json_encode($in['corrections']??[],JSON_UNESCAPED_UNICODE)]);
   if($type==='QUESTION' && ctype_digit($ref)){
      // Keep legacy correction fields synchronized for existing staff screens.
      try{$pdo->prepare("UPDATE qps_question_bank_draft_questions SET correction_status='NEEDS_CORRECTION',remarks=?,updated_at=NOW() WHERE id=?")->execute([$msg,(int)$ref]);}catch(Throwable $ignored){}
   }
   qps_audit($pdo,'REVIEW_REMARK_ADDED','SUBMISSION_PACKAGE',(string)$pkg,['target_type'=>$type,'target_ref'=>$ref,'to_role'=>$to]);
   echo json_encode(['success'=>true,'message'=>'Remark saved. Use the workflow action to return the package.']);exit;
 }
 if($action==='delete_question'){
   qps_workflow_require_role(['HOD']);
   $pkg=(int)($in['package_id']??0);$qid=(int)($in['question_id']??0);
   if($pkg<1||$qid<1)throw new RuntimeException('Invalid package or question.');
   $st=$pdo->prepare('SELECT bank_draft_id,status,current_owner_role FROM qps_submission_packages WHERE id=?');$st->execute([$pkg]);$p=$st->fetch(PDO::FETCH_ASSOC);
   if(!$p || strtoupper((string)$p['current_owner_role']) !== 'HOD' || !in_array($p['status'],['SUBMITTED_TO_HOD','HOD_RECHECK'],true))throw new RuntimeException('Question can only be deleted while the package is in HOD review.');
   $pdo->beginTransaction();
   try{
     $del=$pdo->prepare('DELETE FROM qps_question_bank_draft_questions WHERE id=? AND draft_id=?');$del->execute([$qid,(int)$p['bank_draft_id']]);
     if(!$del->rowCount())throw new RuntimeException('Question not found in this course draft.');
     $pdo->prepare('UPDATE qps_question_bank_drafts SET total_questions=(SELECT COUNT(*) FROM qps_question_bank_draft_questions WHERE draft_id=?),updated_at=NOW() WHERE id=?')->execute([(int)$p['bank_draft_id'],(int)$p['bank_draft_id']]);
     qps_audit($pdo,'HOD_DELETED_DRAFT_QUESTION','DRAFT_QUESTION',(string)$qid,['package_id'=>$pkg]);
     $pdo->commit();
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
   echo json_encode(['success'=>true,'message'=>'Question deleted.']);exit;
 }
 if($action==='resolve'){
   qps_workflow_require_role(['STAFF']);
   $remarkId=(int)($in['remark_id']??0); $note=trim((string)($in['resolver_note']??''));
   if($remarkId<1) throw new RuntimeException('Select a valid correction.');
   $actor=qps_workflow_actor();
   $st=$pdo->prepare("SELECT r.id,r.target_type,r.target_ref,r.package_id,p.staff_code,p.bank_draft_id,p.current_owner_role,p.status AS package_status FROM qps_remarks r INNER JOIN qps_submission_packages p ON p.id=r.package_id WHERE r.id=? AND r.status='OPEN' AND r.to_role='STAFF' AND p.staff_code=? LIMIT 1");
   $st->execute([$remarkId,$actor['code']]); $row=$st->fetch(PDO::FETCH_ASSOC);
   if(!$row) throw new RuntimeException('Open correction not found for your staff account.');
   if(!in_array(strtoupper((string)$row['package_status']),['HOD_REMARKS','STAFF_CORRECTION','DEAN_REMARKS','HOD_RECHECK'],true) && strtoupper((string)$row['current_owner_role'])!=='STAFF') throw new RuntimeException('This correction is not currently assigned to you.');
   $pdo->beginTransaction();
   $up=$pdo->prepare("UPDATE qps_remarks SET status='RESOLVED',resolver_note=?,resolved_at=NOW() WHERE id=? AND status='OPEN' AND to_role='STAFF'");
   $up->execute([$note,$remarkId]); if(!$up->rowCount()) throw new RuntimeException('Correction was already resolved.');
   if($row['target_type']==='QUESTION' && ctype_digit((string)$row['target_ref'])){
     $pdo->prepare("UPDATE qps_question_bank_draft_questions SET correction_status='CORRECTED',remarks=?,updated_at=NOW() WHERE id=? AND draft_id=? AND staff_code=?")
       ->execute([$note!==''?$note:'Correction resolved',(int)$row['target_ref'],(int)$row['bank_draft_id'],$actor['code']]);
   }
   qps_audit($pdo,'STAFF_CORRECTION_RESOLVED','REMARK',(string)$remarkId,['target_type'=>$row['target_type'],'target_ref'=>$row['target_ref']]);
   $pdo->commit();
   echo json_encode(['success'=>true,'message'=>'Correction marked as resolved.']); exit;
 }
 if($action==='withdraw'){
   $id=(int)($in['remark_id']??0);$st=$pdo->prepare("UPDATE qps_remarks SET status='WITHDRAWN' WHERE id=? AND author_code=? AND status='OPEN'");
   $st->execute([$id,$actor['code']]);if(!$st->rowCount())throw new RuntimeException('Open remark not found or you are not its author.');
   qps_audit($pdo,'REVIEW_REMARK_WITHDRAWN','REMARK',(string)$id);
   echo json_encode(['success'=>true]);exit;
 }
 throw new RuntimeException('Unsupported action.');
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('[QPS remarks] '.$e->getMessage());http_response_code(400);$msg=$e instanceof RuntimeException?$e->getMessage():'Unable to process this correction.';echo json_encode(['success'=>false,'message'=>$msg],JSON_UNESCAPED_UNICODE);}
