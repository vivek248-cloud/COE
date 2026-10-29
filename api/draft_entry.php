<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';
header('Content-Type: application/json; charset=utf-8');
function out($ok, $message = '', $data = [], $code = 200) { http_response_code($code); echo json_encode(['ok'=>$ok,'message'=>$message,'data'=>$data], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
if (!isLoggedIn()) out(false, 'Please sign in again.', [], 401);
$pdo = getDBConnection();
try { qps_ensure_aux_schema($pdo); qps_ensure_question_draft_schema($pdo); } catch (Throwable $e) { out(false, 'Database initialization failed: '.$e->getMessage(), [], 500); }
$user = getCurrentUser() ?: []; $staff = trim((string)($user['staff_code'] ?? ''));
try { $collegePdo = getCollegeDBConnection(); } catch (Throwable $e) { $collegePdo = null; }
$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
function getDraft(PDO $pdo, int $id, string $staff, bool $all = false): array {
    $sql = 'SELECT * FROM qps_question_bank_drafts WHERE id=?'; $args=[$id];
    if (!$all) { $sql .= ' AND staff_code=?'; $args[]=$staff; }
    $st=$pdo->prepare($sql); $st->execute($args); $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) out(false, 'Draft not found or you do not have access.', [], 404);
    return $row;
}
try {
 if ($action === 'list') {
   $st=$pdo->prepare('SELECT id,paper_code,course_title,semester,academic_year,exam_type,total_questions,status,updated_at FROM qps_question_bank_drafts WHERE staff_code=? ORDER BY updated_at DESC');
   $st->execute([$staff]); $drafts=$st->fetchAll();
   foreach($drafts as &$d){$q=$pdo->prepare('SELECT id,q_number,unit_no,sub_unit,section_type,question_type,question_text,marks,k_level,co_level,options_json,answer_key,match_column_a,match_column_b,image_path,compulsory,choice_group,review_status,review_remarks FROM qps_question_bank_draft_questions WHERE draft_id=? ORDER BY section_type,q_number,id');$q->execute([$d['id']]);$d['questions']=$q->fetchAll();} unset($d);
   out(true,'Drafts loaded.',['drafts'=>$drafts]);
 }
 if ($action === 'create') {
   $paper=trim((string)($_POST['paper_code']??'')); $title=trim((string)($_POST['course_title']??''));
   $semester=trim((string)($_POST['semester']??'')); $year=trim((string)($_POST['academic_year']??'')); $exam=trim((string)($_POST['exam_type']??''));
   if($paper===''||$semester===''||$year===''||$exam==='') out(false,'Course, semester, academic year and exam type are required.',[],422);
   // Verify faculty allocation against the college timetable, except privileged COE users.
   if (!isCOE()) {
      if(!$collegePdo) out(false,'College course database is unavailable. Configure COLLEGE_DB_NAME.',[],500);
      $st=$collegePdo->prepare('SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1');
      $st->execute([$staff,$paper]);
      if(!$st->fetchColumn()) out(false,'This course is not assigned to your staff account.',[],403);
   }
   $deptCode=trim((string)($user['dept_code']??'')); $deptName=trim((string)($user['department']??''));
   try {if($collegePdo){$st=$collegePdo->prepare('SELECT c.coursetitle, c.dept_code, d.name AS dept_name FROM courses c LEFT JOIN departments d ON d.code=c.dept_code WHERE UPPER(c.coursecode)=UPPER(?) LIMIT 1');$st->execute([$paper]);$course=$st->fetch();if($course){$title=$title?:($course['coursetitle']??'');$deptCode=$course['dept_code']?:$deptCode;$deptName=$course['dept_name']?:$deptName;}}} catch(Throwable $ignore) {}
   $existing=$pdo->prepare("SELECT id FROM qps_question_bank_drafts WHERE staff_code=? AND UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND exam_type=? AND status IN ('DRAFT','RETURNED_BY_HOD','RETURNED_BY_COE','CORRECTION_REQUIRED') ORDER BY id DESC LIMIT 1");
   $existing->execute([$staff,$paper,$semester,$year,$exam]);$existingId=(int)$existing->fetchColumn();
   if($existingId>0) { qps_activity_log($pdo,'DRAFT_OPENED','QUESTION_BANK_DRAFT',(string)$existingId,['paper_code'=>$paper]); out(true,'Existing editable draft opened.',['draft_id'=>$existingId]); }
   $pdo->beginTransaction();
   $st=$pdo->prepare("INSERT INTO qps_question_bank_drafts(staff_code,dept_code,dept_name,paper_code,course_title,semester,academic_year,exam_type,max_marks,total_questions,status,questions_json) VALUES(?,?,?,?,?,?,?,?,75,0,'DRAFT','{}')");
   $st->execute([$staff,$deptCode,$deptName,$paper,$title?:$paper,$semester,$year,$exam]);$id=(int)$pdo->lastInsertId();
   $pdo->prepare('UPDATE qps_question_bank_drafts SET questions_json=? WHERE id=?')->execute([json_encode(['schema_version'=>'1.0','metadata'=>['staff_code'=>$staff,'paper_code'=>$paper,'course_title'=>$title?:$paper,'semester'=>$semester,'academic_year'=>$year,'exam_type'=>$exam],'questions'=>[]],JSON_UNESCAPED_UNICODE),$id]);
   $pdo->commit(); qps_activity_log($pdo,'DRAFT_CREATED','QUESTION_BANK_DRAFT',(string)$id,['paper_code'=>$paper,'semester'=>$semester,'academic_year'=>$year]); out(true,'Draft created.',['draft_id'=>$id]);
 }
 if ($action === 'add_question' || $action === 'update_question') {
   $draftId=(int)($_POST['draft_id']??0); $draft=getDraft($pdo,$draftId,$staff); if(!in_array($draft['status'],['DRAFT','RETURNED_BY_HOD','RETURNED_BY_COE','CORRECTION_REQUIRED'],true)) out(false,'This draft is locked for editing.',[],409);
   $questionId=(int)($_POST['question_id']??0); $text=trim((string)($_POST['question_text']??'')); $marks=(int)($_POST['marks']??0); $unit=(int)($_POST['unit_no']??0); $sub=trim((string)($_POST['sub_unit']??'')); $section=strtoupper(trim((string)($_POST['section_type']??''))); $type=strtoupper(trim((string)($_POST['question_type']??''))); $k=strtoupper(trim((string)($_POST['k_level']??'K1'))); $co=strtoupper(trim((string)($_POST['co_level']??'CO1')));
   $options=trim((string)($_POST['options_json']??'')); $answer=trim((string)($_POST['answer_key']??'')); $matchA=trim((string)($_POST['match_column_a']??'')); $matchB=trim((string)($_POST['match_column_b']??'')); $image=''; $comp=!empty($_POST['compulsory'])?1:0; $choice=trim((string)($_POST['choice_group']??''));
   if($text===''||$marks<1||$unit<1||$sub===''||!in_array($section,['SECTION-A','SECTION-B','SECTION-C','SECTION-D'],true)||$type==='') out(false,'Complete question text, unit/subunit, section, type and positive marks.',[],422);
   if($marks===1&&$section!=='SECTION-A') out(false,'One-mark questions belong to Section A.',[],422);
   if($marks===5&&$section!=='SECTION-B') out(false,'Five-mark questions belong to Section B.',[],422);
   if($marks===10&&!in_array($section,['SECTION-C','SECTION-D'],true)) out(false,'Ten-mark questions belong to Section C or Section D.',[],422);
   if($section==='SECTION-A'&&$marks!==1) out(false,'Every Section A question must be 1 mark.',[],422);
   if($section==='SECTION-B'&&$marks!==5) out(false,'Every Section B question must be 5 marks.',[],422);
   if(in_array($section,['SECTION-C','SECTION-D'],true)&&$marks!==10) out(false,'Section C and Section D questions must be 10 marks.',[],422);
   if($type==='MCQ') { $decoded=json_decode($options,true); if(!is_array($decoded)||count(array_filter($decoded,fn($v)=>trim((string)$v)!==''))!==4) out(false,'MCQ requires exactly four options.',[],422); }
   if(in_array($type,['MATCH','MATCHING'],true)&&($matchA===''||$matchB==='')) out(false,'Matching questions require both Column A and Column B.',[],422);
   $comp=in_array($section,['SECTION-A','SECTION-D'],true)?1:0;
   if($section==='SECTION-B'&&$choice===''){if($action==='update_question'&&$questionId>0){$st=$pdo->prepare('SELECT choice_group FROM qps_question_bank_draft_questions WHERE id=? AND draft_id=?');$st->execute([$questionId,$draftId]);$choice=trim((string)$st->fetchColumn());}if($choice===''){$st=$pdo->prepare("SELECT COUNT(*) FROM qps_question_bank_draft_questions WHERE draft_id=? AND section_type='SECTION-B'");$st->execute([$draftId]);$choice='B'.(intdiv((int)$st->fetchColumn(),2)+1);}}
   if(isset($_FILES['diagram'])&&is_uploaded_file($_FILES['diagram']['tmp_name'])) { $f=$_FILES['diagram']; if($f['size']>8*1024*1024) out(false,'Diagram must be 8 MB or smaller.',[],422); $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']); $ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'][$mime]??null; if(!$ext) out(false,'Diagram must be PNG, JPG, WEBP or GIF.',[],422); $dir=PRIVATE_STORAGE_DIR.'/question-diagrams/'.(int)$draftId; if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir)) out(false,'Could not create private diagram folder.',[],500); $name=bin2hex(random_bytes(16)).'.'.$ext; if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name)) out(false,'Diagram upload failed.',[],500); $image='storage/private/question-diagrams/'.$draftId.'/'.$name; }
   if($action==='add_question') {
      $st=$pdo->prepare("INSERT INTO qps_question_bank_draft_questions(draft_id,q_number,unit_no,sub_unit,section_type,question_type,question_text,marks,k_level,co_level,options_json,answer_key,match_column_a,match_column_b,image_path,compulsory,choice_group,review_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'PENDING')");
      $st->execute([$draftId,0,$unit,$sub,$section,$type,$text,$marks,$k,$co,$options?:null,$answer?:null,$matchA?:null,$matchB?:null,$image?:null,$comp,$choice?:null]); $questionId=(int)$pdo->lastInsertId();
   } else {
      $st=$pdo->prepare("UPDATE qps_question_bank_draft_questions SET unit_no=?,sub_unit=?,section_type=?,question_type=?,question_text=?,marks=?,k_level=?,co_level=?,options_json=?,answer_key=?,match_column_a=?,match_column_b=?,image_path=COALESCE(NULLIF(?,''),image_path),compulsory=?,choice_group=?,review_status='PENDING',review_remarks=NULL WHERE id=? AND draft_id=?");
      $st->execute([$unit,$sub,$section,$type,$text,$marks,$k,$co,$options?:null,$answer?:null,$matchA?:null,$matchB?:null,$image,$comp,$choice?:null,$questionId,$draftId]); if(!$st->rowCount()) { $check=$pdo->prepare('SELECT id FROM qps_question_bank_draft_questions WHERE id=? AND draft_id=?');$check->execute([$questionId,$draftId]);if(!$check->fetchColumn())out(false,'Question not found in this draft.',[],404); }
   }
   $count=$pdo->prepare('SELECT COUNT(*) FROM qps_question_bank_draft_questions WHERE draft_id=?');$count->execute([$draftId]);$n=(int)$count->fetchColumn();$pdo->prepare('UPDATE qps_question_bank_drafts SET total_questions=?,updated_at=NOW() WHERE id=?')->execute([$n,$draftId]);
   try { qps_rebuild_course_matrix($pdo,(string)$draft['paper_code'],(string)$draft['semester'],(string)$draft['academic_year'],(string)$draft['exam_type'],(string)$draft['dept_code']); } catch(Throwable $matrixError) { error_log('QPS matrix refresh failed: '.$matrixError->getMessage()); }
   qps_activity_log($pdo,$action==='add_question'?'QUESTION_ADDED':'QUESTION_UPDATED','DRAFT_QUESTION',(string)$questionId,['draft_id'=>$draftId,'section'=>$section,'marks'=>$marks,'unit_no'=>$unit]); out(true,'Question saved automatically.',['question_id'=>$questionId,'total_questions'=>$n]);
 }
 if ($action === 'delete_question') {
   $draftId=(int)($_POST['draft_id']??0);$qid=(int)($_POST['question_id']??0);$draft=getDraft($pdo,$draftId,$staff);if(!in_array($draft['status'],['DRAFT','RETURNED_BY_HOD','RETURNED_BY_COE','CORRECTION_REQUIRED'],true))out(false,'This draft is locked for editing.',[],409);
   $st=$pdo->prepare('DELETE FROM qps_question_bank_draft_questions WHERE id=? AND draft_id=?');$st->execute([$qid,$draftId]);$pdo->prepare('UPDATE qps_question_bank_drafts SET total_questions=(SELECT COUNT(*) FROM qps_question_bank_draft_questions WHERE draft_id=?) WHERE id=?')->execute([$draftId,$draftId]);try { qps_rebuild_course_matrix($pdo,(string)$draft['paper_code'],(string)$draft['semester'],(string)$draft['academic_year'],(string)$draft['exam_type'],(string)$draft['dept_code']); } catch(Throwable $matrixError) { error_log('QPS matrix refresh failed: '.$matrixError->getMessage()); }qps_activity_log($pdo,'QUESTION_DELETED','DRAFT_QUESTION',(string)$qid,['draft_id'=>$draftId]); out(true,'Question deleted.');
 }
 if ($action === 'submit_hod') {
   $draftId=(int)($_POST['draft_id']??0);$draft=getDraft($pdo,$draftId,$staff);if(!in_array($draft['status'],['DRAFT','RETURNED_BY_HOD','RETURNED_BY_COE','CORRECTION_REQUIRED'],true))out(false,'This draft has already been submitted.',[],409);
   $st=$pdo->prepare('SELECT * FROM qps_question_bank_draft_questions WHERE draft_id=? ORDER BY section_type,id');$st->execute([$draftId]);$allQuestions=$st->fetchAll(PDO::FETCH_ASSOC);$n=count($allQuestions);if($n<1)out(false,'Add at least one question before submitting.',[],422);
   // Standard 75-mark paper validation, performed before moving the draft into the HOD queue.
   $bySection=['SECTION-A'=>[],'SECTION-B'=>[],'SECTION-C'=>[],'SECTION-D'=>[]];foreach($allQuestions as $q){$sec=strtoupper((string)$q['section_type']);if(isset($bySection[$sec]))$bySection[$sec][]=$q;}
   $errors=[];$a=$bySection['SECTION-A'];$aTypes=['MCQ'=>0,'MATCH'=>0,'MATCHING'=>0,'ASSERTION_REASON'=>0,'VSA'=>0];foreach($a as $q){$t=strtoupper((string)$q['question_type']);if(isset($aTypes[$t]))$aTypes[$t]++;}
   if(count($a)!==20)$errors[]='Section A must contain exactly 20 questions.';
   if(($aTypes['MCQ']??0)!==8)$errors[]='Section A requires 8 MCQs.';
   if((($aTypes['MATCH']??0)+($aTypes['MATCHING']??0))!==1)$errors[]='Section A requires 1 matching question.';
   if(($aTypes['ASSERTION_REASON']??0)!==1)$errors[]='Section A requires 1 assertion–reason question.';
   if(($aTypes['VSA']??0)!==10)$errors[]='Section A requires 10 very-short-answer questions.';
   foreach($a as $q)if((int)$q['marks']!==1)$errors[]='Every Section A question must be 1 mark.';
   $b=$bySection['SECTION-B'];if(count($b)!==10)$errors[]='Section B requires 10 questions arranged as 5 A/B choice pairs.';foreach($b as $q)if((int)$q['marks']!==5)$errors[]='Every Section B question must be 5 marks.';
   $groups=[];foreach($b as $q){$g=trim((string)$q['choice_group']);if($g==='')$errors[]='Every Section B question must have a choice group identifying its A/B pair.';else $groups[$g]=($groups[$g]??0)+1;}if(count($groups)!==5||count(array_filter($groups,fn($v)=>$v===2))!==5)$errors[]='Section B must have exactly 5 choice groups, each containing 2 questions.';
   $c=$bySection['SECTION-C'];if(count($c)!==4)$errors[]='Section C requires 4 available 10-mark questions.';foreach($c as $q)if((int)$q['marks']!==10)$errors[]='Every Section C question must be 10 marks.';
   $d=$bySection['SECTION-D'];if(count($d)!==1)$errors[]='Section D requires exactly 1 compulsory 10-mark question.';foreach($d as $q){if((int)$q['marks']!==10)$errors[]='The Section D question must be 10 marks.';if(!(int)$q['compulsory'])$errors[]='The Section D question must be compulsory.';}
   if($errors)out(false,'The bank does not match the required 75-mark pattern: '.implode(' ',array_values(array_unique($errors))),['validation_errors'=>array_values(array_unique($errors))],422);
   $pdo->beginTransaction();$pdo->prepare("UPDATE qps_question_bank_drafts SET total_questions=?,status='SUBMITTED_TO_HOD',submitted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$n,$draftId]);$pdo->prepare("INSERT INTO qps_audit_log(actor,role,action,entity_type,entity_id,details,created_at) VALUES(?,?,?,?,?,?,NOW())")->execute([$staff,getUserRole(),'DRAFT_SUBMITTED_TO_HOD','QUESTION_BANK_DRAFT',(string)$draftId,json_encode(['question_count'=>$n])]);$pdo->commit();qps_activity_log($pdo,'DRAFT_SUBMITTED_TO_HOD','QUESTION_BANK_DRAFT',(string)$draftId,['question_count'=>$n]);out(true,'Question bank submitted to HOD.');
 }
 out(false,'Unknown action.',[],400);
} catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); error_log('QPS draft action failed: '.$e->getMessage()); out(false,'Request failed due to a server error. Please retry or contact the administrator.',[],500); }
