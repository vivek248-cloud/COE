<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/system.php';
require_once __DIR__.'/../includes/question_rules.php';
require_once __DIR__.'/../includes/workflow.php';
$pdo=getDBConnection();
try{
 requireAuth();$in=json_decode((string)file_get_contents('php://input'),true);if(!is_array($in))$in=$_POST;
 $csrf=(string)($_SESSION['qps_review_csrf']??'');
 if($csrf===''||!hash_equals($csrf,(string)($in['csrf']??'')))throw new RuntimeException('Security token expired. Refresh the page.');
 $action=(string)($in['action']??'');$packageId=(int)($in['package_id']??0);
 if($action==='staff_submit'){
   qps_workflow_require_role(['STAFF']);
   $draftId=(int)($in['bank_draft_id']??($in['course']['bank_draft_id']??0));
   if($draftId<1)throw new RuntimeException('Save the question bank draft before submitting.');
   $stDraft=$pdo->prepare('SELECT * FROM qps_question_bank_drafts WHERE id=? LIMIT 1');$stDraft->execute([$draftId]);$draft=$stDraft->fetch(PDO::FETCH_ASSOC);
   if(!$draft)throw new RuntimeException('Question bank draft not found.');
   $actor=qps_workflow_actor();
   if(strtoupper((string)$draft['staff_code'])!==strtoupper($actor['code']))throw new RuntimeException('You can submit only your own course draft.');
   $stQ=$pdo->prepare('SELECT COUNT(*) FROM qps_question_bank_draft_questions WHERE draft_id=?');$stQ->execute([$draftId]);
   if((int)$stQ->fetchColumn()<1)throw new RuntimeException('Question bank is empty.');
   $courseCode=trim((string)$draft['paper_code']);$semester=trim((string)$draft['semester']);$year=trim((string)$draft['academic_year']);$exam=trim((string)($draft['exam_type']??'')); // Older/manual drafts may not have exam_type saved; resolve it from the selected blueprint.
   $draftMarks=(int)($draft['total_marks']??0);if($draftMarks<1)$draftMarks=(int)($draft['max_marks']??0);
   $draftProgramme=trim((string)($draft['programme_year']??''));if($draftProgramme==='')$draftProgramme=trim((string)($draft['programme_level']??''));
   $draftLanguage=strtoupper(trim((string)($draft['language']??'EN')));if($draftLanguage==='')$draftLanguage='EN';
   $requestedFinalId=(int)($in['final_blueprint_id']??($in['course']['final_blueprint_id']??0));
   $finalId=0;$fb=null;$mismatch=[];

   // First inspect the browser-supplied ID. Never trust a stale ID without checking
   // the complete persisted draft context.
   if($requestedFinalId>0){
     $stById=$pdo->prepare('SELECT * FROM qps_final_blueprints WHERE id=? LIMIT 1');
     $stById->execute([$requestedFinalId]);$candidate=$stById->fetch(PDO::FETCH_ASSOC);
     if($candidate){
       $checks=[
         'course code'=>strcasecmp(trim((string)$candidate['paper_code']),$courseCode)===0,
         'semester'=>trim((string)$candidate['semester'])===$semester,
         'academic year'=>trim((string)$candidate['academic_year'])===$year,
         // Only enforce exam type when the draft actually stores one.
         // Legacy/manual drafts without exam_type inherit it from the selected blueprint.
         'exam type'=>$exam==='' || trim((string)$candidate['exam_type'])===$exam,
         'programme year'=>trim((string)($candidate['programme_year']??$candidate['programme_level']??''))===$draftProgramme,
         'total marks'=>(int)$candidate['total_marks']===$draftMarks,
         'language'=>strtoupper(trim((string)($candidate['language']??'EN')))===$draftLanguage,
         'active status'=>strtoupper((string)$candidate['status'])==='ACTIVE'
       ];
       foreach($checks as $field=>$matches){if(!$matches)$mismatch[]=$field;}
       if(!$mismatch){$fb=$candidate;$finalId=(int)$candidate['id'];}
     }
   }

   // Recover automatically from an outdated browser ID by resolving the exact
   // blueprint saved for this draft's complete context.
   if(!$fb){
     if($exam!==''){
       $stExact=$pdo->prepare("SELECT * FROM qps_final_blueprints WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND exam_type=? AND total_marks=? AND COALESCE(NULLIF(programme_year,''),programme_level,'')=? AND UPPER(language)=UPPER(?) AND UPPER(status)='ACTIVE' ORDER BY id DESC LIMIT 1");
       $stExact->execute([$courseCode,$semester,$year,$exam,$draftMarks,$draftProgramme,$draftLanguage]);
     }else{
       $stExact=$pdo->prepare("SELECT * FROM qps_final_blueprints WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND total_marks=? AND COALESCE(NULLIF(programme_year,''),programme_level,'')=? AND UPPER(language)=UPPER(?) AND UPPER(status)='ACTIVE' ORDER BY id DESC LIMIT 1");
       $stExact->execute([$courseCode,$semester,$year,$draftMarks,$draftProgramme,$draftLanguage]);
     }
     $fb=$stExact->fetch(PDO::FETCH_ASSOC)?:null;
     if($fb)$finalId=(int)$fb['id'];
   }
   if(!$fb){
     if($requestedFinalId>0&&$mismatch){$details='The selected saved blueprint does not match this question-bank draft: '.implode(', ',$mismatch).'.';}
     else{$details='No active final blueprint matches this draft context (course '.$courseCode.', '.$year.', '.$semester.', '.$draftProgramme.', '.$draftMarks.' marks, '.$draftLanguage.').';}
     throw new RuntimeException($details.' Open Final Question Slot Allocation, confirm the top-form context, save the slots, then submit again.');
   }
   // Persisted blueprint is authoritative for drafts that predate exam_type storage.
   $exam=trim((string)($fb['exam_type']??$exam));
   $slots=json_decode((string)$fb['slots_config'],true);if(isset($slots['slots']))$slots=$slots['slots'];
   $ctx=qps_rule_context_from_course($pdo,$courseCode,$semester,$year);$ctx['programme_year']=trim((string)($fb['programme_year']??$draft['programme_year']??$draft['programme_level']??''));$ctx['programme_level']=$ctx['programme_year'];$ctx['selected_total_marks']=(int)($fb['total_marks']??$draft['total_marks']??$draft['max_marks']??0);$ctx['total_marks']=$ctx['selected_total_marks'];$ctx['language']=strtoupper((string)($fb['language']??$draft['language']??'EN'));$ctx['bank_draft_id']=$draftId;$ctx['final_blueprint_id']=$finalId;
   $valid=qps_validate_slots($pdo,$ctx,is_array($slots)?$slots:[],'submit');
   if($valid['errors'])throw new RuntimeException(implode("\n",$valid['errors']));
   $qbbId=(int)($draft['qbb_id']??0);
   if($qbbId>0){
     $stQbb=$pdo->prepare("SELECT id FROM question_bank_blueprints WHERE id=? AND status='PUBLISHED' AND qbb_enabled=1 LIMIT 1");
     $stQbb->execute([$qbbId]);if(!$stQbb->fetchColumn())$qbbId=0;
   }
   if($qbbId<1){
     try{$stQbb=$pdo->prepare("SELECT id FROM question_bank_blueprints WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND exam_type=? AND status='PUBLISHED' AND qbb_enabled=1 ORDER BY published_at DESC,id DESC LIMIT 1");$stQbb->execute([$courseCode,$semester,$year,$exam]);$qbbId=(int)$stQbb->fetchColumn();}catch(Throwable $e){}
   }
   if($qbbId<1)throw new RuntimeException('Published Question Bank Blueprint is missing or disabled. Ask the HOD to publish the course blueprint before submitting.');
   $stExisting=$pdo->prepare('SELECT staff_code FROM qps_submission_packages WHERE paper_code=? AND semester=? AND academic_year=? AND exam_type=? LIMIT 1');
   $stExisting->execute([$courseCode,$semester,$year,$exam]);$existingOwner=$stExisting->fetchColumn();
   if($existingOwner!==false && strtoupper((string)$existingOwner)!==strtoupper($actor['code']))throw new RuntimeException('This course submission is already owned by another staff member.');
   $packageId=qps_create_or_update_package($pdo,[
     'paper_code'=>$courseCode,'semester'=>$semester,'academic_year'=>$year,'exam_type'=>$exam,
     'dept_code'=>$draft['dept_code']??null,'bank_draft_id'=>$draftId,'qbb_id'=>$qbbId,'final_blueprint_id'=>$finalId
   ]);
   $stStatus=$pdo->prepare('SELECT status FROM qps_submission_packages WHERE id=?');$stStatus->execute([$packageId]);$currentStatus=(string)$stStatus->fetchColumn();
   $transition=$currentStatus==='HOD_REMARKS'?'STAFF_RESUBMIT':($currentStatus==='STAFF_CORRECTION'?'STAFF_RESUBMIT_DEAN':'STAFF_SUBMIT');
   $result=qps_transition($pdo,$packageId,$transition,'Staff submitted the corrected / completed three course artifacts.');
   $pdo->prepare("UPDATE qps_question_bank_drafts SET status='SUBMITTED_TO_HOD',submitted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$draftId]);
   $pdo->prepare("UPDATE qps_remarks SET status='RESOLVED',resolved_at=NOW() WHERE package_id=? AND status='OPEN' AND to_role='STAFF'")->execute([$packageId]);
   echo json_encode(['success'=>true,'workflow'=>$result,'message'=>'All three course artifacts were validated and submitted to HOD.'],JSON_UNESCAPED_UNICODE);exit;
 }
 if($packageId<=0)throw new RuntimeException('A valid submission package is required.');
 $actions=['hod_return','hod_forward','staff_resubmit','dean_return','hod_forward_dean_remarks','staff_resubmit_dean','hod_reverify','dean_approve','coe_approve','coe_allot','coe_generate'];
 $map=['hod_return'=>'HOD_RETURN','hod_forward'=>'HOD_FORWARD','staff_resubmit'=>'STAFF_RESUBMIT','dean_return'=>'DEAN_RETURN','hod_forward_dean_remarks'=>'HOD_FORWARD_DEAN_REMARKS','staff_resubmit_dean'=>'STAFF_RESUBMIT_DEAN','hod_reverify'=>'HOD_REVERIFY','dean_approve'=>'DEAN_APPROVE','coe_approve'=>'COE_APPROVE','coe_allot'=>'COE_ALLOT','coe_generate'=>'COE_GENERATE'];
 if(!isset($map[$action]))throw new RuntimeException('Unsupported workflow action.');
 if($action==='dean_approve'){
   qps_workflow_require_role(['DEAN']);
   $st=$pdo->prepare('SELECT p.*,fb.slots_config,fb.total_marks,fb.programme_year,fb.programme_level,fb.language FROM qps_submission_packages p LEFT JOIN qps_final_blueprints fb ON fb.id=p.final_blueprint_id WHERE p.id=?');
   $st->execute([$packageId]);$pkg=$st->fetch(PDO::FETCH_ASSOC);if(!$pkg)throw new RuntimeException('Package not found.');
   $slots=json_decode((string)$pkg['slots_config'],true);if(isset($slots['slots']))$slots=$slots['slots'];
   $ctx=qps_rule_context_from_course($pdo,(string)$pkg['paper_code'],(string)$pkg['semester'],(string)$pkg['academic_year']);$ctx['programme_year']=trim((string)($pkg['programme_year']??$pkg['programme_level']??''));$ctx['programme_level']=$ctx['programme_year'];$ctx['selected_total_marks']=(int)($pkg['total_marks']??0);$ctx['total_marks']=$ctx['selected_total_marks'];$ctx['language']=strtoupper((string)($pkg['language']??'EN'));
   $ctx['bank_draft_id']=(int)($pkg['bank_draft_id']??0);
   if($ctx['bank_draft_id']<1){$stDraft=$pdo->prepare('SELECT id FROM qps_question_bank_drafts WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND programme_year=? AND total_marks=? AND UPPER(language)=UPPER(?) ORDER BY id DESC LIMIT 1');$stDraft->execute([$pkg['paper_code'],$pkg['semester'],$pkg['academic_year'],$ctx['programme_year'],$ctx['selected_total_marks'],$ctx['language']]);$ctx['bank_draft_id']=(int)$stDraft->fetchColumn();}
   $valid=qps_validate_slots($pdo,$ctx,is_array($slots)?$slots:[],'submit');
   if($valid['errors'])throw new RuntimeException('Dean validation failed: '.implode("\n",$valid['errors']));
 }
 $result=qps_transition($pdo,$packageId,$map[$action],trim((string)($in['note']??'')));
 $stPkg=$pdo->prepare('SELECT bank_draft_id FROM qps_submission_packages WHERE id=?');$stPkg->execute([$packageId]);$draftId=(int)$stPkg->fetchColumn();
 if($draftId>0){
   $draftStatus=[
     'hod_return'=>'RETURNED_TO_STAFF','hod_forward'=>'SUBMITTED_TO_DEAN',
     'staff_resubmit'=>'SUBMITTED_TO_HOD','dean_return'=>'DEAN_REMARKS',
     'hod_forward_dean_remarks'=>'RETURNED_TO_STAFF','staff_resubmit_dean'=>'HOD_RECHECK',
     'hod_reverify'=>'SUBMITTED_TO_DEAN','dean_approve'=>'SUBMITTED_TO_COE'
   ][$action]??null;
   if($draftStatus!==null)$pdo->prepare('UPDATE qps_question_bank_drafts SET status=?,updated_at=NOW() WHERE id=?')->execute([$draftStatus,$draftId]);
 }
 echo json_encode(['success'=>true,'workflow'=>$result],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(400);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
