<?php
/** Teaching/HOD master blueprint slot definition API. COE allotment is a separate read-only workflow. */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/system.php';
require_once __DIR__.'/../includes/question_rules.php';
require_once __DIR__.'/../includes/workflow.php';
if (!isLoggedIn() || qps_workflow_role() !== 'STAFF') { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Only the submitting teaching staff member can define blueprint slots.']); exit; }
$pdo=getDBConnection(); $user=getCurrentUser(); qps_ensure_question_draft_schema($pdo);
try {
 $raw=file_get_contents('php://input');$in=json_decode((string)$raw,true);if(!is_array($in))$in=array_merge($_GET,$_POST);
 $action=trim((string)($in['action']??'save_slots'));
 if($action==='save_slots'){
  $csrf=(string)($_SESSION['qps_review_csrf']??'');
  if($csrf===''||!hash_equals($csrf,(string)($in['csrf']??'')))throw new RuntimeException('Security token expired. Refresh the page.');
 }
 $code=strtoupper(trim((string)($in['paper_code']??$in['course_code']??'')));
 $sem=trim((string)($in['semester']??'Semester 1'));
 $year=trim((string)($in['academic_year']??(defined('DEFAULT_ACADEMIC_YEAR')?DEFAULT_ACADEMIC_YEAR:'2026-2027')));
 $exam=trim((string)($in['exam_type']??'Odd Semester End Examination'));
 if($code==='')throw new RuntimeException('Course code is required.');
 $staffCode=(string)($user['staff_code']??'');
 $stAssigned=$pdo->prepare("SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1");
 $stAssigned->execute([$staffCode,$code]);
 if(!$stAssigned->fetchColumn())throw new RuntimeException('You are not assigned to this course.');
 if($action==='load_slots'){
  $requestedMarks=(int)($in['total_marks']??0);
  if($requestedMarks>0){
   $st=$pdo->prepare("SELECT * FROM qps_final_blueprints WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND exam_type=? AND total_marks=? AND programme_year=? AND UPPER(language)=UPPER(?) AND UPPER(status)='ACTIVE' ORDER BY id DESC LIMIT 1");
   $st->execute([$code,$sem,$year,$exam,$requestedMarks,trim((string)($in['programme_year']??'')),trim((string)($in['language']??'EN'))]);
  }else{
   $st=$pdo->prepare("SELECT * FROM qps_final_blueprints WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND exam_type=? AND UPPER(status)='ACTIVE' ORDER BY id DESC LIMIT 1");
   $st->execute([$code,$sem,$year,$exam]);
  }
  $bp=$st->fetch(PDO::FETCH_ASSOC);
  $slots=$bp?json_decode((string)$bp['slots_config'],true):[];
  if(is_array($slots)&&isset($slots['slots']))$slots=$slots['slots'];
  echo json_encode(['success'=>true,'blueprint'=>$bp?:null,'slots'=>is_array($slots)?$slots:[]],JSON_UNESCAPED_UNICODE);exit;
 }
 if($action!=='save_slots')throw new RuntimeException('Unsupported action.');
 $slots=$in['slots']??[];if(!is_array($slots)||!count($slots))throw new RuntimeException('Add at least one blueprint slot.');
 $normal=[];$seen=[];
 foreach($slots as $i=>$s){
  if(!is_array($s))continue;
  $num=(int)($s['slot_number']??$s['question_number']??($i+1));
  $sec=strtoupper(trim((string)($s['section_type']??$s['section']??'SECTION-A')));
  $marks=(int)($s['marks']??0);
  if($num<=0||$marks<=0)throw new RuntimeException('Every slot must have a valid question number and marks.');
  $key=(string)($s['slot_key']??($sec.'-'.$num));
  if(isset($seen[$key]))throw new RuntimeException('Duplicate slot key: '.$key);
  $seen[$key]=true;
  $normal[]=[
   'slot_key'=>$key,'slot_number'=>$num,'section_type'=>$sec,
   'unit_no'=>(int)($s['unit_no']??$s['unit']??0),
   'sub_unit'=>(string)($s['sub_unit']??$s['subunit']??''),
   'question_type'=>(string)($s['question_type']??$s['type']??''),
   'k_level'=>strtoupper((string)($s['k_level']??'')),
   'co_level'=>strtoupper((string)($s['co_level']??'')),
   'marks'=>$marks,'is_compulsory'=>!empty($s['is_compulsory']),
   'choice_group'=>(string)($s['choice_group']??''),
   'option_label'=>strtolower(trim((string)($s['option_label']??'')))
  ];
 }
 if(!$normal)throw new RuntimeException('No valid slots supplied.');

 // Resolve the four-value context from persisted course/draft records.
 $checkDraft=$pdo->prepare('SELECT semester,academic_year FROM qps_question_bank_drafts WHERE UPPER(paper_code)=UPPER(?) AND staff_code=? ORDER BY id DESC LIMIT 1');$checkDraft->execute([$code,$staffCode]);$savedContext=$checkDraft->fetch(PDO::FETCH_ASSOC)?:[];if(!empty($savedContext['semester'])&&$savedContext['semester']!==$sem)throw new RuntimeException('Selected semester does not match the saved course draft.');if(!empty($savedContext['academic_year'])&&$savedContext['academic_year']!==$year)throw new RuntimeException('Selected Academic Year does not match the saved course draft.');
 $ctx=qps_rule_context_from_course($pdo,$code,$sem,$year);
 if(isset($in['total_marks'])&&$in['total_marks']!==''){$ctx['selected_total_marks']=$in['total_marks'];$ctx['total_marks']=$in['total_marks'];}
 $ctx['bank_draft_id']=0;
 $postedProgramme=trim((string)($in['programme_year']??$in['programme_level']??''));
 if($postedProgramme!==''){$ctx['programme_year']=$postedProgramme;$ctx['programme_level']=$postedProgramme;}
 $ctx['selected_total_marks']=$in['total_marks']??($ctx['total_marks']??null);
 $ctx['language']=strtoupper(trim((string)($in['language']??$ctx['language']??'EN')));
 $stDraftId=$pdo->prepare("SELECT id FROM qps_question_bank_drafts WHERE UPPER(paper_code)=UPPER(?) AND staff_code=? AND semester=? AND academic_year=? AND programme_year=? AND total_marks=? AND UPPER(language)=UPPER(?) ORDER BY id DESC LIMIT 1");
 $stDraftId->execute([$code,$staffCode,$sem,$year,$postedProgramme,(int)$ctx['selected_total_marks'],$ctx['language']]);
 $ctx['bank_draft_id']=(int)$stDraftId->fetchColumn();
 $validation=qps_validate_slots($pdo,$ctx,$normal,'draft');
 if($validation['errors']) throw new RuntimeException(implode("\n",$validation['errors']));
 $normal=$validation['slots'];
 $json=json_encode($normal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 $pdo->beginTransaction();
 $title=trim((string)($in['course_title']??''));
 $totalMarks=(int)$validation['total_marks'];
 $programmeYear=trim((string)($ctx['programme_year']??$postedProgramme));$language=strtoupper(trim((string)($ctx['language']??'EN')));
 $st=$pdo->prepare("SELECT id FROM qps_final_blueprints WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND exam_type=? AND total_marks=? AND programme_year=? AND UPPER(language)=UPPER(?) ORDER BY id DESC LIMIT 1");
 $st->execute([$code,$sem,$year,$exam,$totalMarks,$programmeYear,$language]);$id=(int)$st->fetchColumn();
 if($id){
  $pdo->prepare("UPDATE qps_final_blueprints SET course_title=?,programme_year=?,programme_level=?,language=?,total_marks=?,slots_config=?,updated_by=?,status='ACTIVE',updated_at=NOW() WHERE id=?")->execute([$title,$programmeYear,$programmeYear,$language,$totalMarks,$json,$user['staff_code']??'STAFF',$id]);
 }else{
  $pdo->prepare("INSERT INTO qps_final_blueprints (paper_code,course_title,semester,academic_year,exam_type,programme_year,programme_level,language,total_marks,slots_config,created_by,updated_by,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'ACTIVE',NOW(),NOW())")->execute([$code,$title,$sem,$year,$exam,$programmeYear,$programmeYear,$language,$totalMarks,$json,$user['staff_code']??'STAFF',$user['staff_code']??'STAFF']);
  $id=(int)$pdo->lastInsertId();
 }
 qps_audit($pdo,'TEACHING_FINAL_BLUEPRINT_SAVED','FINAL_BLUEPRINT',(string)$id,['paper_code'=>$code,'slot_count'=>count($normal)]);
 $pdo->commit();
 echo json_encode(['success'=>true,'final_blueprint_id'=>$id,'total_slots'=>count($normal),'rule_count'=>(int)$validation['rule_count'],'total_marks'=>$totalMarks,'warnings'=>$validation['warnings'],'message'=>'Final blueprint saved and made available for COE allotment.'],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(400);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
