<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
require_once __DIR__.'/../config/config.php'; require_once __DIR__.'/../config/db.php'; require_once __DIR__.'/../includes/auth.php'; require_once __DIR__.'/../includes/system.php'; require_once __DIR__.'/../includes/workflow.php';
function qps_tsa_reply(bool $ok,string $message='',array $data=[],int $status=200): never { http_response_code($status); echo json_encode(['success'=>$ok,'message'=>$message,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
if(!isLoggedIn()||qps_workflow_role()!=='STAFF') qps_tsa_reply(false,'Teaching Staff login required.',[],403);
$in=json_decode((string)file_get_contents('php://input'),true)?:$_POST; $csrf=(string)($_SESSION['qps_review_csrf']??'');
if($csrf===''||!hash_equals($csrf,(string)($in['csrf']??''))) qps_tsa_reply(false,'Security token expired. Refresh the page.',[],403);
try {
 $pdo=getDBConnection(); $u=getCurrentUser(); $staff=trim((string)($u['staff_code']??''));
 $code=strtoupper(trim((string)($in['paper_code']??''))); $templateId=(int)($in['template_id']??0); $year=trim((string)($in['academic_year']??''));
 $semester=(int)($in['semester']??0); $programme=strtoupper(trim((string)($in['programme_year']??''))); $language=strtoupper(trim((string)($in['language']??'EN')));
 $action=(string)($in['action']??'save');
 if(!$code||$templateId<1||!preg_match('/^(19|20)\d{2}-(19|20)\d{2}$/',$year)||$semester<1||$semester>8||!in_array($programme,['I','II','III','IV'],true)) qps_tsa_reply(false,'Complete the course and academic context.',[],422);
 $st=$pdo->prepare('SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1'); $st->execute([$staff,$code]); if(!$st->fetchColumn()) qps_tsa_reply(false,'This course is not allocated to your staff account.',[],403);
 $st=$pdo->prepare("SELECT * FROM question_paper_templates WHERE id=? AND is_active=1 AND LOWER(status)='published' LIMIT 1"); $st->execute([$templateId]); $template=$st->fetch(PDO::FETCH_ASSOC); if(!$template) qps_tsa_reply(false,'Published Dean template not found.',[],404);
 if(!empty($template['academic_year']) && $template['academic_year']!==$year) qps_tsa_reply(false,'Selected template academic year does not match the course context.',[],422);
 if(!empty($template['semester']) && (int)$template['semester']!==$semester) qps_tsa_reply(false,'Selected template semester does not match the course context.',[],422);
 if(!empty($template['year_of_programme']) && strtoupper((string)$template['year_of_programme'])!==$programme) qps_tsa_reply(false,'Selected template programme year does not match the course context.',[],422);
 $st=$pdo->prepare("SELECT sec.id section_id,sec.section_type,sec.section_title,sec.display_order section_order,sl.id template_slot_id,sl.slot_label,sl.marks,sl.k_level,sl.co co_level,sl.question_type,sl.display_order slot_order FROM question_paper_template_sections sec JOIN question_paper_template_slots sl ON sl.section_id=sec.id WHERE sec.template_id=? AND sec.is_active=1 AND sl.is_active=1 ORDER BY sec.display_order,sl.display_order,sl.id"); $st->execute([$templateId]); $required=$st->fetchAll(PDO::FETCH_ASSOC); if(!$required) qps_tsa_reply(false,'This template has no active slots.',[],422);
 $minimum=max(1,min(20,(int)qps_setting($pdo,'FINAL_SLOT_MIN_ELIGIBLE_QUESTIONS',1)));
 // These tables already exist in the live installation. Do not use CREATE TABLE IF NOT EXISTS
 // as a substitute for schema migration: it does not reconcile existing table columns.
 $requiredColumns = [
   'final_blueprints' => ['id','staff_code','course_code','template_id','academic_year','semester','programme_year','total_marks','language','template_name_snapshot','template_snapshot','status'],
   'final_blueprint_slots' => ['id','final_blueprint_id','slot_key','paper_code','slot_number','sort_order','section_type','unit_no','sub_unit','question_type','marks','k_level','co_level','template_slot_id','section_id','section_title_snapshot','slot_label_snapshot','marks_snapshot','k_level_snapshot','co_level_snapshot','allocations_json','eligible_count']
 ];
 foreach ($requiredColumns as $tableName => $columns) {
   $check = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
   $check->execute([$tableName]);
   $present = array_fill_keys($check->fetchAll(PDO::FETCH_COLUMN), true);
   $missing = array_values(array_filter($columns, static fn($column) => !isset($present[$column])));
   if ($missing) qps_tsa_reply(false, "Database schema for $tableName is incomplete. Missing: ".implode(', ', $missing).". Apply the approved QPS migration first.", ['missing_columns'=>$missing], 500);
 }
 if($action==='load_saved'){
   $st=$pdo->prepare('SELECT * FROM final_blueprints WHERE staff_code=? AND UPPER(course_code)=UPPER(?) AND template_id=? AND academic_year=? AND semester=? AND programme_year=? AND UPPER(language)=UPPER(?) LIMIT 1');
   $st->execute([$staff,$code,$templateId,$year,$semester,$programme,$language]);$bp=$st->fetch(PDO::FETCH_ASSOC);
   if(!$bp)qps_tsa_reply(true,'No saved allocation exists.',['saved'=>false]);
   $st=$pdo->prepare('SELECT * FROM final_blueprint_slots WHERE final_blueprint_id=? ORDER BY sort_order,id');$st->execute([(int)$bp['id']]);$slots=$st->fetchAll(PDO::FETCH_ASSOC);
   foreach($slots as &$sl)$sl['allocations']=json_decode((string)($sl['allocations_json']??'[]'),true)?:[];unset($sl);
   qps_tsa_reply(true,'Saved allocation loaded.',['saved'=>true,'blueprint'=>$bp,'slots'=>$slots]);
 }
 if($action!=='save')qps_tsa_reply(false,'Unsupported action.',[],400);
 $submissionStatus='draft';
 try{$st=$pdo->prepare('SELECT final_blueprint_status FROM course_submissions WHERE UPPER(course_code)=UPPER(?) AND UPPER(staff_code)=UPPER(?) AND academic_year=? AND semester=? ORDER BY updated_at DESC,id DESC LIMIT 1');$st->execute([$code,$staff,$year,$semester]);$submissionStatus=(string)($st->fetchColumn()?:'draft');}catch(Throwable $ignored){}
 if(!in_array($submissionStatus,['draft','hod_corrections','dean_corrections'],true))qps_tsa_reply(false,'Final Blueprint is locked at status: '.$submissionStatus.'.',[],409);
 $slots=$in['slots']??[];if(!is_array($slots)||count($slots)!==count($required))qps_tsa_reply(false,'Every template slot must have an allocation before saving.',[],422);
 $requiredMap=[];foreach($required as $r)$requiredMap[(string)$r['template_slot_id']]=$r;
 $normalized=[];$sum=0;
 foreach($slots as $s){$sid=(string)($s['template_slot_id']??'');$r=$requiredMap[$sid]??null;if(!$r)qps_tsa_reply(false,'An unknown or duplicate template slot was submitted.',[],422);unset($requiredMap[$sid]);
   $pairs=$s['allocations']??[];if(!is_array($pairs)||!count($pairs))qps_tsa_reply(false,'Every slot needs at least one Unit / Sub-unit selection.',[],422);
   $unique=[];$eligibleCount=0;
   foreach($pairs as $pair){$unit=(int)($pair['unit_no']??0);$sub=trim((string)($pair['sub_unit']??''));if($unit<1||$sub==='')qps_tsa_reply(false,'Invalid Unit / Sub-unit selection.',[],422);$key=$unit.'|'.$sub;if(isset($unique[$key]))continue;
     $q=$pdo->prepare("SELECT COUNT(*) FROM questions_draft q WHERE UPPER(q.staff_id)=UPPER(?) AND UPPER(q.course_code)=UPPER(?) AND q.academic_year=? AND UPPER(q.language)=UPPER(?) AND q.unit_number=? AND COALESCE(NULLIF(q.sub_unit_number,''),'—')=? AND q.marks=? AND UPPER(q.blooms_level)=UPPER(?) AND UPPER(q.co_code)=UPPER(?) AND q.is_active=1 AND LOWER(q.status) IN ('draft','hod_corrections','dean_corrections') AND NOT EXISTS(SELECT 1 FROM corrections c WHERE c.target_type='question' AND c.target_id=q.id AND c.status='open')");
     $q->execute([$staff,$code,$year,$language,$unit,$sub,(int)$r['marks'],(string)$r['k_level'],(string)$r['co_level']]);$count=(int)$q->fetchColumn();if($count<$minimum)qps_tsa_reply(false,"Unit $unit / Sub-unit $sub has only $count eligible questions for {$r['slot_label']}; minimum required is $minimum.",[],422);
     $unique[$key]=['unit_no'=>$unit,'sub_unit'=>$sub,'eligible_count'=>$count];$eligibleCount+=$count;
   }
   $normalized[]=['template_slot_id'=>(int)$sid,'section_id'=>(int)$r['section_id'],'section_type'=>(string)$r['section_type'],'section_title'=>(string)$r['section_title'],'slot_label'=>(string)$r['slot_label'],'question_type'=>(string)($r['question_type']??'theory'),'marks'=>(int)$r['marks'],'k_level'=>(string)$r['k_level'],'co_level'=>(string)$r['co_level'],'allocations'=>array_values($unique),'eligible_count'=>$eligibleCount];$sum+=(int)$r['marks'];
 }
 if($requiredMap)qps_tsa_reply(false,'One or more template slots are missing.',[],422);
 if((int)$template['total_marks']>0&&$sum!==(int)$template['total_marks'])qps_tsa_reply(false,'Template slot marks total does not match the Dean template total.',[],422);
 $snapshot=['template_id'=>$templateId,'template_name'=>(string)$template['name'],'academic_year'=>$year,'semester'=>$semester,'programme_year'=>$programme,'total_marks'=>$sum,'language'=>$language,'captured_at'=>date('c'),'sections'=>[],'slots'=>[]];
 foreach($required as $r){$snapshot['sections'][(string)$r['section_id']]=['section_id'=>(int)$r['section_id'],'section_type'=>$r['section_type'],'section_title'=>$r['section_title']];$snapshot['slots'][]=['template_slot_id'=>(int)$r['template_slot_id'],'section_id'=>(int)$r['section_id'],'section_type'=>$r['section_type'],'section_title'=>$r['section_title'],'slot_label'=>$r['slot_label'],'question_type'=>$r['question_type']??'theory','marks'=>(int)$r['marks'],'k_level'=>$r['k_level'],'co_level'=>$r['co_level']];}
 $pdo->beginTransaction();
 $st=$pdo->prepare('SELECT id FROM final_blueprints WHERE staff_code=? AND UPPER(course_code)=UPPER(?) AND template_id=? AND academic_year=? AND semester=? AND programme_year=? AND UPPER(language)=UPPER(?) FOR UPDATE');$st->execute([$staff,$code,$templateId,$year,$semester,$programme,$language]);$id=(int)$st->fetchColumn();$snap=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
 if($id){$st=$pdo->prepare("UPDATE final_blueprints SET total_marks=?,template_name_snapshot=?,template_snapshot=?,status='draft' WHERE id=? AND staff_code=?");$st->execute([$sum,$template['name'],$snap,$id,$staff]);$pdo->prepare('DELETE FROM final_blueprint_slots WHERE final_blueprint_id=?')->execute([$id]);}
 else{$st=$pdo->prepare("INSERT INTO final_blueprints(staff_code,course_code,template_id,academic_year,semester,programme_year,total_marks,language,template_name_snapshot,template_snapshot,status) VALUES(?,?,?,?,?,?,?,?,?,?,'draft')");$st->execute([$staff,$code,$templateId,$year,$semester,$programme,$sum,$language,$template['name'],$snap]);$id=(int)$pdo->lastInsertId();}
 $ins=$pdo->prepare('INSERT INTO final_blueprint_slots(
   final_blueprint_id,slot_key,paper_code,slot_number,sort_order,section_type,
   unit_no,sub_unit,question_type,marks,k_level,co_level,template_slot_id,section_id,
   section_title_snapshot,slot_label_snapshot,marks_snapshot,k_level_snapshot,co_level_snapshot,
   allocations_json,eligible_count
 ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
 foreach($normalized as $i=>$r){
   // Legacy NOT NULL unit/sub_unit columns retain the first selected pair;
   // allocations_json preserves every selected Unit/Sub-unit combination.
   $first = $r['allocations'][0];
   $ins->execute([
     $id,(string)$r['template_slot_id'],$code,$i+1,$i+1,$r['section_type'],
     (int)$first['unit_no'],(string)$first['sub_unit'],($r['question_type']!==''?$r['question_type']:'theory'),
     $r['marks'],$r['k_level'],$r['co_level'],$r['template_slot_id'],$r['section_id'],
     $r['section_title'],$r['slot_label'],$r['marks'],$r['k_level'],$r['co_level'],
     json_encode($r['allocations'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),$r['eligible_count']
   ]);
 }
 $pdo->commit();qps_tsa_reply(true,'Final blueprint saved. '.$sum.' marks across '.count($normalized).' slots.',['id'=>$id,'assigned'=>count($normalized),'total'=>count($required),'status'=>'draft']);
} catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('[Teaching final blueprint save] '.$e->getMessage());qps_tsa_reply(false,'Unable to save final blueprint. Check the server error log.',[],500);}
