<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/config.php';require_once __DIR__.'/../config/db.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/system.php';require_once __DIR__.'/../includes/question_rules.php';require_once __DIR__.'/../includes/workflow.php';
$pdo=getDBConnection();
function qps_rules_json_input():array{$v=json_decode((string)file_get_contents('php://input'),true);return is_array($v)?$v:$_POST;}
try{
 requireAuth();$in=qps_rules_json_input();$csrf=(string)($_SESSION['qps_review_csrf']??'');if($csrf===''||!hash_equals($csrf,(string)($in['csrf']??'')))throw new RuntimeException('Security token expired. Refresh the page.');
 $action=(string)($in['action']??'resolve');
 if($action==='resolve'){
  $paper=trim((string)($in['paper_code']??''));$sem=trim((string)($in['semester']??''));$year=trim((string)($in['academic_year']??''));
  $postedProgramme=trim((string)($in['programme_year']??$in['programme_level']??''));$ctx=['paper_code'=>$paper,'semester'=>$sem,'academic_year'=>$year,'programme_year'=>$postedProgramme,'programme_level'=>$postedProgramme];
  if($paper!==''){$check=$pdo->prepare('SELECT semester,academic_year FROM qps_question_bank_drafts WHERE UPPER(paper_code)=UPPER(?) ORDER BY id DESC LIMIT 1');$check->execute([$paper]);$saved=$check->fetch(PDO::FETCH_ASSOC)?:[];if(!empty($saved['semester'])&&$saved['semester']!==$sem)throw new RuntimeException('Selected semester does not match the saved course draft.');if(!empty($saved['academic_year'])&&$saved['academic_year']!==$year)throw new RuntimeException('Selected Academic Year does not match the saved course draft.');$persisted=qps_rule_context_from_course($pdo,$paper,$sem,$year);$ctx=array_merge($persisted,$ctx);$postedProgramme=trim((string)($in['programme_year']??$in['programme_level']??''));$ctx['semester']=$sem;$ctx['academic_year']=$year;if(isset($in['total_marks'])&&$in['total_marks']!==''){$ctx['selected_total_marks']=$in['total_marks'];$ctx['total_marks']=$in['total_marks'];}$ctx['programme_year']=$postedProgramme!==''?$postedProgramme:trim((string)($ctx['programme_year']??$ctx['programme_level']));$ctx['programme_level']=$ctx['programme_year'];}
  else {$ctx['programme_year']=$postedProgramme;$ctx['programme_level']=$postedProgramme; if(isset($in['total_marks'])&&$in['total_marks']!==''){$ctx['selected_total_marks']=$in['total_marks'];$ctx['total_marks']=$in['total_marks'];}}
  $result=qps_rules_for_course($pdo,$ctx);echo json_encode(['success'=>$result['found'],'rules'=>$result['rules'],'count'=>$result['count'],'total_marks'=>$result['total_marks'],'matched_semester'=>$result['matched_semester'],'matched_year'=>$result['matched_year'],'message'=>$result['message']],JSON_UNESCAPED_UNICODE);exit;
 }
 qps_workflow_require_role(['DEAN']);

 // Read-only card listing and row-level actions are independent of the
 // rule-set selectors below, but still use the same session CSRF token.
 if($action==='list'){
  // question_rules is shared by academic context, not stored against a single
  // course_code. Link each matching rule set to courses that have a saved
  // question-bank draft for the same context. This keeps the existing rule
  // key and avoids introducing a course-specific rule table.
  $allStmt=$pdo->prepare('SELECT id,semester,academic_year,programme_year,programme_level,total_marks,question_number,k_level,co_level,course_type,created_by,updated_by,created_at,updated_at FROM question_rules ORDER BY academic_year DESC,total_marks DESC,programme_year,programme_level,semester,question_number,id');
  $allStmt->execute();
  $allRows=$allStmt->fetchAll(PDO::FETCH_ASSOC);

  $draftStmt=$pdo->prepare("SELECT d.id AS draft_id,d.paper_code,d.course_title AS draft_course_title,d.semester,d.academic_year,d.degree_level,d.programme_year,d.programme_level,d.max_marks,d.total_marks AS draft_total_marks,d.language,c.coursecode,c.coursetitle,c.dept_code,c.level,c.programme,c.maxmark FROM qps_question_bank_drafts d LEFT JOIN courses c ON UPPER(c.coursecode)=UPPER(d.paper_code) WHERE TRIM(COALESCE(d.paper_code,''))<>'' ORDER BY d.updated_at DESC,d.id DESC");
  $draftStmt->execute();

  $groups=[];$seenCourseContexts=[];$usedRuleSets=[];$ruleRowsCache=[];
  while($draft=$draftStmt->fetch(PDO::FETCH_ASSOC)){
   $paperCode=trim((string)($draft['coursecode']??$draft['paper_code']??''));
   if($paperCode==='')continue;
   $semester=trim((string)($draft['semester']??''));
   $academicYear=trim((string)($draft['academic_year']??''));
   if($semester===''||$academicYear==='')continue;
   $programmeFromDraft=trim((string)($draft['programme_year']??$draft['programme_level']??''));
   $context=['semester'=>$semester,'academic_year'=>$academicYear,'programme_year'=>$programmeFromDraft,'programme_level'=>$programmeFromDraft,'degree_level'=>$draft['degree_level']??'', 'programme'=>$draft['programme']??'', 'level'=>$draft['level']??'', 'draft_total_marks'=>$draft['draft_total_marks']??null,'draft_max_marks'=>$draft['max_marks']??null,'course_maxmark'=>$draft['maxmark']??null];
   $programme=$programmeFromDraft!==''?$programmeFromDraft:qps_resolve_programme_level($context);
   if($programme==='')continue;
   $resolved=qps_rules_for_course($pdo,$context+['programme_year'=>$programme,'programme_level'=>$programme]);
   if(empty($resolved['found']))continue;
   $marks=(int)$resolved['total_marks'];
   $matchedSemester=(string)$resolved['matched_semester'];
   $matchedYear=(string)$resolved['matched_year'];
   $setKey=json_encode([$matchedSemester,$matchedYear,$programme,$marks],JSON_UNESCAPED_UNICODE);
   if(!isset($ruleRowsCache[$setKey])){
    $ruleStmt=$pdo->prepare("SELECT id,semester,academic_year,programme_year,programme_level,total_marks,question_number,k_level,co_level,course_type,created_by,updated_by,created_at,updated_at FROM question_rules WHERE semester=? AND academic_year=? AND COALESCE(NULLIF(programme_year,''),programme_level)=? AND total_marks=? ORDER BY question_number,id");
    $ruleStmt->execute([$matchedSemester,$matchedYear,$programme,$marks]);
    $ruleRowsCache[$setKey]=$ruleStmt->fetchAll(PDO::FETCH_ASSOC);
   }
   $ruleRows=$ruleRowsCache[$setKey];
   if(!$ruleRows)continue;
   $courseContextKey=json_encode([strtoupper($paperCode),$semester,$academicYear,$programme,$marks],JSON_UNESCAPED_UNICODE);
   if(isset($seenCourseContexts[$courseContextKey]))continue;
   $seenCourseContexts[$courseContextKey]=true;
   $usedRuleSets[$setKey]=true;
   $groups[]=[
    'kind'=>'course','course_code'=>$paperCode,
    'course_title'=>(string)($draft['coursetitle']?:$draft['draft_course_title']?:'Course title not available'),
    'department'=>(string)($draft['dept_code']??''),
    'academic_year'=>$academicYear,'semester'=>$semester,'programme_level'=>$programme,'programme_year'=>$programme,
    'total_marks'=>$marks,'matched_year'=>$matchedYear,'matched_semester'=>$matchedSemester,
    'rule_count'=>count($ruleRows),'rules'=>$ruleRows
   ];
  }

  // Keep rule sets visible even when no saved course draft exists yet. They
  // appear under a clearly labelled shared/unlinked group instead of being lost.
  $unlinked=[];
  foreach($allRows as $rule){
   $setKey=json_encode([(string)$rule['semester'],(string)$rule['academic_year'],(string)($rule['programme_year'] ?: $rule['programme_level']),(int)$rule['total_marks']],JSON_UNESCAPED_UNICODE);
   if(isset($usedRuleSets[$setKey]))continue;
   $unlinked[$setKey]['semester']=$rule['semester'];
   $unlinked[$setKey]['academic_year']=$rule['academic_year'];
   $unlinked[$setKey]['programme_level']=$rule['programme_year'] ?: $rule['programme_level'];
   $unlinked[$setKey]['programme_year']=$rule['programme_year'] ?: $rule['programme_level'];
   $unlinked[$setKey]['total_marks']=(int)$rule['total_marks'];
   $unlinked[$setKey]['rules'][]=$rule;
  }
  foreach($unlinked as $set){
   $groups[]=[
    'kind'=>'shared','course_code'=>'','course_title'=>'Shared rule set (not yet linked to a course draft)',
    'department'=>'','academic_year'=>$set['academic_year'],'semester'=>$set['semester'],
    'programme_level'=>$set['programme_level'],'programme_year'=>$set['programme_year'],'total_marks'=>$set['total_marks'],
    'matched_year'=>$set['academic_year'],'matched_semester'=>$set['semester'],
    'rule_count'=>count($set['rules']),'rules'=>$set['rules']
   ];
  }
  echo json_encode(['success'=>true,'groups'=>$groups,'group_count'=>count($groups),'rule_count'=>count($allRows)],JSON_UNESCAPED_UNICODE);exit;
 }
 if($action==='view'){
  $id=filter_var($in['id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new RuntimeException('Select a valid question rule.');
  $st=$pdo->prepare('SELECT id,semester,academic_year,programme_year,programme_level,total_marks,question_number,k_level,co_level,course_type,created_by,updated_by,created_at,updated_at FROM question_rules WHERE id=? LIMIT 1');$st->execute([$id]);$row=$st->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Question rule not found.');
  echo json_encode(['success'=>true,'rule'=>$row],JSON_UNESCAPED_UNICODE);exit;
 }
 if($action==='delete'){
  $id=filter_var($in['id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new RuntimeException('Select a valid question rule.');
  $st=$pdo->prepare('SELECT id,semester,academic_year,programme_level,total_marks,question_number FROM question_rules WHERE id=? LIMIT 1');$st->execute([$id]);$row=$st->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Question rule not found.');
  if(function_exists('qps_ensure_audit_schema'))qps_ensure_audit_schema($pdo);$pdo->beginTransaction();try{$del=$pdo->prepare('DELETE FROM question_rules WHERE id=?');$del->execute([$id]);qps_audit($pdo,'DEAN_QUESTION_RULE_DELETED','QUESTION_RULE',(string)$id,$row);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  echo json_encode(['success'=>true,'message'=>'Question rule deleted.'],JSON_UNESCAPED_UNICODE);exit;
 }
 if($action==='update'){
  $id=filter_var($in['id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new RuntimeException('Select a valid question rule.');
  $semester=trim((string)($in['semester']??''));$year=trim((string)($in['academic_year']??''));$programme=trim((string)($in['programme_year']??$in['programme_level']??''));$marks=filter_var($in['total_marks']??null,FILTER_VALIDATE_INT);$number=filter_var($in['question_number']??null,FILTER_VALIDATE_INT);$k=strtoupper(trim((string)($in['k_level']??'')));$co=strtoupper(trim((string)($in['co_level']??'')));
  if(!in_array($semester,array_merge(['*'],qps_semester_options()),true))throw new RuntimeException('Choose a valid semester or All semesters.');
  if(!preg_match('/^(19|20)\d{2}-(19|20)\d{2}$/',$year))throw new RuntimeException('Choose a valid academic year in YYYY-YYYY format.');[$ys,$ye]=array_map('intval',explode('-',$year));if($ye!==$ys+1)throw new RuntimeException('Academic year must contain consecutive years.');
  if(!in_array($programme,qps_programme_levels(),true))throw new RuntimeException('Choose a valid year of programme.');
  if($marks===false||!in_array((int)$marks,qps_allowed_total_marks($pdo),true))throw new RuntimeException('Choose one of the configured total marks values.');
  if($number===false||$number<1)throw new RuntimeException('Question number must be a positive whole number.');
  if(!preg_match('/^K[1-6]$/',$k)||!preg_match('/^CO[1-6]$/',$co))throw new RuntimeException('Choose valid K-Level and CO-Level values.');
  $actor=qps_workflow_actor();if(function_exists('qps_ensure_audit_schema'))qps_ensure_audit_schema($pdo);$pdo->beginTransaction();
  try{$up=$pdo->prepare("UPDATE question_rules SET semester=:semester,academic_year=:academic_year,programme_year=:programme_year,programme_level=:programme_level,total_marks=:total_marks,course_type='*',question_number=:question_number,k_level=:k_level,co_level=:co_level,updated_by=:updated_by WHERE id=:id");$up->execute([':semester'=>$semester,':academic_year'=>$year,':programme_year'=>$programme,':programme_level'=>$programme,':total_marks'=>(int)$marks,':question_number'=>(int)$number,':k_level'=>$k,':co_level'=>$co,':updated_by'=>$actor['code'],':id'=>$id]);if($up->rowCount()===0){$check=$pdo->prepare('SELECT id FROM question_rules WHERE id=?');$check->execute([$id]);if(!$check->fetchColumn())throw new RuntimeException('Question rule not found.');}qps_audit($pdo,'DEAN_QUESTION_RULE_UPDATED','QUESTION_RULE',(string)$id,['semester'=>$semester,'academic_year'=>$year,'programme_level'=>$programme,'total_marks'=>(int)$marks,'question_number'=>(int)$number,'k_level'=>$k,'co_level'=>$co]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException && str_contains($e->getMessage(),'Duplicate'))throw new RuntimeException('Another rule already uses this semester, year, programme year, total marks and question number.');throw $e;}
  echo json_encode(['success'=>true,'message'=>'Question rule updated.'],JSON_UNESCAPED_UNICODE);exit;
 }
 $semester=trim((string)($in['semester']??'*'));$year=trim((string)($in['academic_year']??''));$programme=trim((string)($in['programme_year']??$in['programme_level']??''));$marks=filter_var($in['total_marks']??null,FILTER_VALIDATE_INT);if($marks===false)$marks=0;
 if(!in_array($semester,array_merge(['*'],qps_semester_options()),true))throw new RuntimeException('Choose a valid semester or All semesters.');
 if(!preg_match('/^(19|20)\d{2}-(19|20)\d{2}$/',$year))throw new RuntimeException('Choose a valid academic year in YYYY-YYYY format.');[$ys,$ye]=array_map('intval',explode('-',$year));if($ye!==$ys+1)throw new RuntimeException('Academic year must contain consecutive years.');
 if(!in_array($programme,qps_programme_levels(),true))throw new RuntimeException('Choose a valid year of programme.');
 if(!in_array($marks,qps_allowed_total_marks($pdo),true))throw new RuntimeException('Choose one of the configured total marks values.');
 if($action==='load'){
  $st=$pdo->prepare("SELECT question_number,k_level,co_level FROM question_rules WHERE semester=? AND academic_year=? AND COALESCE(NULLIF(programme_year,''),programme_level)=? AND total_marks=? ORDER BY question_number");$st->execute([$semester,$year,$programme,$marks]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode(['success'=>true,'rules'=>$rows,'count'=>count($rows)],JSON_UNESCAPED_UNICODE);exit;
 }
 if($action==='save'){
  $rows=$in['rules']??null;if(!is_array($rows)||!$rows)throw new RuntimeException('Add at least one question rule.');$seen=[];$normal=[];
  foreach($rows as $row){if(!is_array($row))throw new RuntimeException('Invalid question rule row.');$n=filter_var($row['question_number']??null,FILTER_VALIDATE_INT);$k=strtoupper(trim((string)($row['k_level']??'')));$co=strtoupper(trim((string)($row['co_level']??'')));if($n===false||$n<1)throw new RuntimeException('Question numbers must be positive whole numbers.');if(isset($seen[$n]))throw new RuntimeException("Duplicate question number Q{$n}.");if(!preg_match('/^K[1-6]$/',$k))throw new RuntimeException("Q{$n}: select K1-K6.");if(!preg_match('/^CO[1-6]$/',$co))throw new RuntimeException("Q{$n}: select CO1-CO6.");$seen[$n]=true;$normal[]=[(int)$n,$k,$co];}
  $nums=array_keys($seen);sort($nums,SORT_NUMERIC);if($nums!==range(1,count($nums)))throw new RuntimeException('Rule question numbers must be continuous from Q1 with no gaps.');
  $actor=qps_workflow_actor();if(function_exists('qps_ensure_audit_schema'))qps_ensure_audit_schema($pdo);$pdo->beginTransaction();
  try{
   $pdo->prepare("DELETE FROM question_rules WHERE semester=:semester AND academic_year=:academic_year AND COALESCE(NULLIF(programme_year,''),programme_level)=:programme_year AND total_marks=:total_marks")->execute([':semester'=>$semester,':academic_year'=>$year,':programme_year'=>$programme,':total_marks'=>$marks]);
   $ins=$pdo->prepare("INSERT INTO question_rules (semester,academic_year,programme_year,programme_level,total_marks,course_type,question_number,k_level,co_level,created_by,updated_by) VALUES (:semester,:academic_year,:programme_year,:programme_level,:total_marks,'*',:question_number,:k_level,:co_level,:created_by,:updated_by)");
   foreach($normal as $r)$ins->execute([':semester'=>$semester,':academic_year'=>$year,':programme_year'=>$programme,':programme_level'=>$programme,':total_marks'=>$marks,':question_number'=>$r[0],':k_level'=>$r[1],':co_level'=>$r[2],':created_by'=>$actor['code'],':updated_by'=>$actor['code']]);
   qps_audit($pdo,'DEAN_QUESTION_RULES_SAVED','QUESTION_RULES',$semester.'|'.$year.'|'.$programme.'|'.$marks,['semester'=>$semester,'academic_year'=>$year,'programme_year'=>$programme,'programme_level'=>$programme,'total_marks'=>$marks,'count'=>count($normal)]);$pdo->commit();}
  catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  echo json_encode(['success'=>true,'message'=>'Question rules saved.','count'=>count($normal),'total_marks'=>$marks],JSON_UNESCAPED_UNICODE);exit;
 }
 throw new RuntimeException('Unsupported action.');
}catch(Throwable $e){http_response_code(400);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
