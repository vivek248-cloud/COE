<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/system.php';
header('Content-Type: application/json; charset=utf-8');
function fb_out($ok,$message='',$data=[],$code=200){http_response_code($code);echo json_encode(['ok'=>$ok,'message'=>$message,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(!isLoggedIn())fb_out(false,'Please sign in.',[],401);
$pdo=getDBConnection();$user=getCurrentUser()?:[];$staff=(string)($user['staff_code']??'');
$action=(string)($_POST['action']??$_GET['action']??'');
try {
 if($action==='courses'){
  $st=$pdo->query("SELECT DISTINCT paper_code,course_title,semester,academic_year,exam_type FROM question_banks WHERE LOWER(status) IN ('approved','published','coe_approved') ORDER BY paper_code,semester,academic_year DESC");
  fb_out(true,'Approved courses loaded.',['courses'=>$st->fetchAll()]);
 }
 if($action==='candidates'){
  $paper=trim((string)($_GET['paper_code']??''));$sem=trim((string)($_GET['semester']??''));$year=trim((string)($_GET['academic_year']??''));$unit=(int)($_GET['unit_no']??0);$sub=trim((string)($_GET['sub_unit']??''));$type=strtoupper(trim((string)($_GET['question_type']??'')));$k=strtoupper(trim((string)($_GET['k_level']??'')));$co=strtoupper(trim((string)($_GET['co_level']??'')));$marks=(int)($_GET['marks']??0);
  if(!$paper||!$sem||!$year||!$unit||!$type||!$k||!$co||!$marks)fb_out(false,'Complete course and question requirements.',[],422);
  $sql="SELECT q.id,q.bank_id,q.q_number,q.question_text,q.section_type,q.question_type,q.unit_no,q.sub_unit,q.marks,q.k_level,q.co_level,q.choice_group,q.compulsory FROM questions q JOIN question_banks b ON b.id=q.bank_id WHERE UPPER(b.paper_code)=UPPER(?) AND b.semester=? AND b.academic_year=? AND LOWER(b.status) IN ('approved','published','coe_approved') AND q.unit_no=? AND COALESCE(q.sub_unit,'')=? AND UPPER(q.question_type)=? AND UPPER(q.k_level)=? AND UPPER(q.co_level)=? AND q.marks=? AND COALESCE(q.validation_status,'VALID')='VALID' ORDER BY q.id DESC LIMIT 100";
  $st=$pdo->prepare($sql);$st->execute([$paper,$sem,$year,$unit,$sub,$type,$k,$co,$marks]);fb_out(true,'Candidates loaded.',['questions'=>$st->fetchAll()]);
 }
 if($action==='save'){
  if($_SERVER['REQUEST_METHOD']!=='POST')fb_out(false,'POST required.',[],405);
  $name=trim((string)($_POST['blueprint_name']??''));$paper=trim((string)($_POST['paper_code']??''));$title=trim((string)($_POST['course_title']??''));$sem=trim((string)($_POST['semester']??''));$year=trim((string)($_POST['academic_year']??''));$exam=trim((string)($_POST['exam_type']??''));$items=json_decode((string)($_POST['items_json']??''),true);
  if(!$name||!$paper||!$sem||!$year||!$exam||!is_array($items)||!count($items))fb_out(false,'Enter blueprint name, course details and at least one selected question.',[],422);
  if(count($items)>200)fb_out(false,'Blueprint item limit exceeded.',[],422);
  // Preserve the existing timetable-based course assignment model for teaching staff.
  if(!isCOE()&&!isHOD()&&!isERPStaff()){
   $college=getCollegeDBConnection();$st=$college->prepare('SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1');$st->execute([$staff,$paper]);if(!$st->fetchColumn())fb_out(false,'This course is not assigned to your staff account.',[],403);
  }
  $ids=[];foreach($items as $item){$qid=(int)($item['question_id']??0);if($qid<1)fb_out(false,'Every blueprint row must select a question.',[],422);$ids[]=$qid;}
  if(count(array_unique($ids))!==count($ids))fb_out(false,'The same question cannot be selected twice in one final blueprint.',[],422);
  $pdo->beginTransaction();
  $st=$pdo->prepare("INSERT INTO qps_final_blueprints(blueprint_name,paper_code,course_title,semester,academic_year,exam_type,created_by,status,total_marks,blueprint_json) VALUES(?,?,?,?,?,?,?,'DRAFT',75,?)");
  $st->execute([$name,$paper,$title,$sem,$year,$exam,$staff,json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$bp=(int)$pdo->lastInsertId();
  $ins=$pdo->prepare('INSERT INTO qps_final_blueprint_items(blueprint_id,question_number,section_type,unit_no,sub_unit,question_type,k_level,co_level,marks,choice_group,question_id,source_bank_id) SELECT ?,?,?,?,?,?,?,?,?,?,q.id,q.bank_id FROM questions q JOIN question_banks b ON b.id=q.bank_id WHERE q.id=? AND UPPER(b.paper_code)=UPPER(?) AND b.semester=? AND b.academic_year=? AND LOWER(b.status) IN (\'approved\',\'published\',\'coe_approved\') AND q.unit_no=? AND COALESCE(q.sub_unit,\'\')=? AND UPPER(q.question_type)=UPPER(?) AND UPPER(q.k_level)=UPPER(?) AND UPPER(q.co_level)=UPPER(?) AND q.marks=?');
  foreach($items as $item){
   $ins->execute([$bp,(string)($item['question_number']??''),(string)($item['section_type']??''),(int)($item['unit_no']??0),(string)($item['sub_unit']??''),(string)($item['question_type']??''),(string)($item['k_level']??''),(string)($item['co_level']??''),(int)($item['marks']??0),$item['choice_group']??null,$paper,$sem,$year,(int)($item['unit_no']??0),(string)($item['sub_unit']??''),(string)($item['question_type']??''),(string)($item['k_level']??''),(string)($item['co_level']??''),(int)($item['marks']??0)]);
   if($ins->rowCount()!==1)throw new RuntimeException('A selected question no longer matches the approved question pool. Reload candidates and try again.');
  }
  $pdo->commit();qps_activity_log($pdo,'FINAL_BLUEPRINT_SAVED','FINAL_BLUEPRINT',(string)$bp,['course'=>$paper,'items'=>count($items)]);fb_out(true,'Final blueprint saved.',['blueprint_id'=>$bp,'items'=>count($items)]);
 }
 if($action==='list'){
  $st=$pdo->prepare('SELECT id,blueprint_name,paper_code,course_title,semester,academic_year,exam_type,status,version_no,updated_at FROM qps_final_blueprints WHERE created_by=? OR ? IN (\'COE_ADMIN\',\'COE_STAFF\',\'HOD\',\'ERP_ADMIN\',\'SUPER_ADMIN\') ORDER BY updated_at DESC LIMIT 100');$role=strtoupper((string)($user['role']??''));$st->execute([$staff,$role]);fb_out(true,'Blueprints loaded.',['blueprints'=>$st->fetchAll()]);
 }
 fb_out(false,'Unknown action.',[],400);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('QPS final blueprint error: '.$e->getMessage());fb_out(false,'Blueprint request failed. Please retry or contact the administrator.',[],500);}
