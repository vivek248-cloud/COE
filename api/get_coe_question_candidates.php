<?php
/**
 * COE final-30 question picker. Returns the actual verified question pool for a course.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/question_bank_blueprint.php';

if (!isCOE()) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
$course = strtoupper(trim((string)($_GET['paper_code'] ?? '')));
$semester = trim((string)($_GET['semester'] ?? ''));
$year = trim((string)($_GET['academic_year'] ?? ''));
$exam = trim((string)($_GET['exam_type'] ?? ''));
$unit = trim((string)($_GET['unit'] ?? ''));
$sub = trim((string)($_GET['sub_unit'] ?? ''));
$type = strtoupper(trim((string)($_GET['question_type'] ?? '')));
$k = strtoupper(trim((string)($_GET['k_level'] ?? '')));
$co = strtoupper(trim((string)($_GET['co_level'] ?? '')));
$marks = (int)($_GET['marks'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
if ($course==='') { echo json_encode(['success'=>false,'error'=>'Course code is required.']); exit; }
try {
  $pdo=getDBConnection();
  $stats=qps_qbb_pool_stats($pdo,$course,$semester,$year,$exam,false);
  if (!$stats['found']) { echo json_encode(['success'=>true,'count'=>0,'questions'=>[],'message'=>'No verified question pool found.'],JSON_UNESCAPED_UNICODE); exit; }
  $ids=array_values(array_unique(array_map('intval',$stats['bank_ids']??[])));
  if (!$ids) { echo json_encode(['success'=>true,'count'=>0,'questions'=>[]]); exit; }
  $ph=implode(',',array_fill(0,count($ids),'?'));
  $st=$pdo->prepare("SELECT id,bank_id,course_code,q_number,unit_no,sub_unit,section_type,question_type,k_level,co_level,marks,question_text,question_json,answer_key,match_column_a,match_column_b,options_json,image_url,formula_latex FROM questions WHERE bank_id IN ($ph) ORDER BY unit_no,sub_unit,section_type,q_number,id");
  $st->execute($ids); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
  $seen=[];$out=[];
  foreach($rows as $r){
    $text=trim(strip_tags((string)($r['question_text']??''))); if($text==='') continue;
    $sig=mb_strtolower(preg_replace('/\s+/u',' ',$text),'UTF-8'); $hash=hash('sha256',$sig.'|'.(string)($r['options_json']??'').'|'.(string)($r['answer_key']??''));
    if(isset($seen[$hash])) continue; $seen[$hash]=1;
    $u=(string)($r['unit_no']??''); $su=(string)($r['sub_unit']??'');
    if($unit!=='' && $u!==preg_replace('/\D/','',$unit)) continue;
    if($sub!=='' && $su!==$sub) continue;
    if($type!=='' && strtoupper((string)$r['question_type'])!==$type) continue;
    if($k!=='' && strtoupper((string)$r['k_level'])!==$k) continue;
    if($co!=='' && strtoupper((string)$r['co_level'])!==$co) continue;
    if($marks>0 && (int)$r['marks']!==$marks) continue;
    if($q!=='' && stripos($text,$q)===false && stripos((string)$r['q_number'],$q)===false) continue;
    $r['question_text']=$text;
    $out[]=$r;
  }
  echo json_encode(['success'=>true,'count'=>count($out),'questions'=>$out],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) { http_response_code(400); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
