<?php
/** Teaching/HOD master blueprint slot definition API. COE allotment is a separate read-only workflow. */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/system.php';
if (!isLoggedIn() || isCOE()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Only teaching staff / HOD can define blueprint slots.']); exit; }
$pdo=getDBConnection(); $user=getCurrentUser(); qps_ensure_question_draft_schema($pdo);
try {
 $raw=file_get_contents('php://input');$in=json_decode((string)$raw,true);if(!is_array($in))$in=array_merge($_GET,$_POST);
 $action=trim((string)($in['action']??'save_slots'));
 $code=strtoupper(trim((string)($in['paper_code']??$in['course_code']??'')));
 $sem=trim((string)($in['semester']??'Semester 1'));
 $year=trim((string)($in['academic_year']??(defined('DEFAULT_ACADEMIC_YEAR')?DEFAULT_ACADEMIC_YEAR:'2026-2027')));
 $exam=trim((string)($in['exam_type']??'Odd Semester End Examination'));
 if($code==='')throw new RuntimeException('Course code is required.');
 if($action==='load_slots'){
  $st=$pdo->prepare("SELECT * FROM qps_final_blueprints WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND exam_type=? AND UPPER(status)='ACTIVE' ORDER BY id DESC LIMIT 1");
  $st->execute([$code,$sem,$year,$exam]);$bp=$st->fetch(PDO::FETCH_ASSOC);
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
   'choice_group'=>(string)($s['choice_group']??'')
  ];
 }
 if(!$normal)throw new RuntimeException('No valid slots supplied.');
 $json=json_encode($normal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 $pdo->beginTransaction();
 $st=$pdo->prepare("SELECT id FROM qps_final_blueprints WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND exam_type=? ORDER BY id DESC LIMIT 1");
 $st->execute([$code,$sem,$year,$exam]);$id=(int)$st->fetchColumn();
 $title=trim((string)($in['course_title']??''));
 $totalMarks=(int)($in['total_marks']??array_sum(array_column($normal,'marks')));
 if($id){
  $pdo->prepare("UPDATE qps_final_blueprints SET course_title=?,total_marks=?,slots_config=?,updated_by=?,status='ACTIVE',updated_at=NOW() WHERE id=?")->execute([$title,$totalMarks,$json,$user['staff_code']??'STAFF',$id]);
 }else{
  $pdo->prepare("INSERT INTO qps_final_blueprints (paper_code,course_title,semester,academic_year,exam_type,total_marks,slots_config,created_by,updated_by,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'ACTIVE',NOW(),NOW())")->execute([$code,$title,$sem,$year,$exam,$totalMarks,$json,$user['staff_code']??'STAFF',$user['staff_code']??'STAFF']);
  $id=(int)$pdo->lastInsertId();
 }
 qps_audit($pdo,'TEACHING_FINAL_BLUEPRINT_SAVED','FINAL_BLUEPRINT',(string)$id,['paper_code'=>$code,'slot_count'=>count($normal)]);
 $pdo->commit();
 echo json_encode(['success'=>true,'final_blueprint_id'=>$id,'total_slots'=>count($normal),'message'=>'Final blueprint saved and made available for COE allotment.'],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(400);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
