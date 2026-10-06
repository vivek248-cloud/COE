<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn() || !isCOE()) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'COE access required']);
    exit;
}

$paperCode = strtoupper(trim((string)($_GET['paper_code'] ?? '')));
$semester = trim((string)($_GET['semester'] ?? ''));
$academicYear = trim((string)($_GET['academic_year'] ?? ''));
$examType = trim((string)($_GET['exam_type'] ?? ''));
if ($paperCode === '') { echo json_encode(['success'=>false,'error'=>'Course code is required.']); exit; }

$pdo = getDBConnection();
try {
    $sql = "SELECT q.id,q.bank_id,q.q_number,q.source_question_no,q.unit_no,q.sub_unit,
                   q.k_level,q.co_level,q.section_type,q.marks,q.question_type,q.question_text,
                   qb.paper_code,qb.academic_year,qb.semester,qb.exam_type
            FROM questions q INNER JOIN question_banks qb ON qb.id=q.bank_id
            WHERE UPPER(qb.paper_code)=UPPER(?)
              AND (UPPER(COALESCE(qb.status,'')) IN ('SUBMITTED TO COE','APPROVED','HOD APPROVED','VERIFIED','COE VERIFIED','PUBLISHED')
                   OR UPPER(COALESCE(qb.hod_status,''))='APPROVED')";
    $params=[$paperCode];
    if($semester!==''){ $sql.=" AND (qb.semester=? OR qb.semester IS NULL OR qb.semester='')"; $params[]=$semester; }
    if($academicYear!==''){ $sql.=" AND (qb.academic_year=? OR qb.academic_year IS NULL OR qb.academic_year='')"; $params[]=$academicYear; }
    if($examType!==''){ $sql.=" AND (qb.exam_type=? OR qb.exam_type IS NULL OR qb.exam_type='')"; $params[]=$examType; }
    $sql.=" ORDER BY q.section_type ASC,q.unit_no ASC,q.sub_unit ASC,q.q_number ASC,q.id ASC";
    $st=$pdo->prepare($sql); $st->execute($params); $raw=$st->fetchAll(PDO::FETCH_ASSOC);

    $seen=[];$questions=[];$ids=[];
    foreach($raw as $q){
        $text=trim((string)$q['question_text']); if($text==='') continue;
        $sig=mb_strtolower(preg_replace('/\s+/u',' ',$text),'UTF-8');
        $hash=hash('sha256',$sig.'|'.(string)($q['question_type']??''));
        if(isset($seen[$hash])) continue; $seen[$hash]=true;
        $id=(int)$q['id']; $ids[]=$id;
        $uRaw=trim((string)($q['unit_no']??'')); $unit=preg_match('/([1-9][0-9]*)/',$uRaw,$m)?$m[1]:($uRaw?:'1');
        $sub=trim((string)($q['sub_unit']??'')); if($sub==='') $sub=$unit.'.1';
        $section=strtoupper(trim((string)($q['section_type']??''))); if($section==='') $section='SECTION-A';
        $qnum=(string)($q['source_question_no'] ?? $q['q_number'] ?? $id);
        $questions[]=['id'=>$id,'q_number'=>$qnum,'unit'=>$unit,'sub_unit'=>$sub,'k_level'=>(string)($q['k_level']??''),'co_level'=>(string)($q['co_level']??''),'section'=>$section,'marks'=>(int)($q['marks']??0),'question_type'=>(string)($q['question_type']??''),'question_text'=>$text,'used_previous'=>false];
    }

    if($ids){
        try{
            $ph=implode(',',array_fill(0,count($ids),'?')); $up=$ids; $up[]=$paperCode;
            $ust=$pdo->prepare("SELECT DISTINCT question_id FROM qps_question_usage WHERE question_id IN ($ph) AND UPPER(COALESCE(paper_code,''))=UPPER(?)");
            $ust->execute($up); $used=[]; foreach($ust->fetchAll(PDO::FETCH_COLUMN) as $qid)$used[(int)$qid]=true;
            foreach($questions as &$q)$q['used_previous']=isset($used[(int)$q['id']]); unset($q);
        }catch(Throwable $e){}
    }

    $available=count(array_filter($questions,static fn($q)=>empty($q['used_previous'])));
    echo json_encode(['success'=>true,'paper_code'=>$paperCode,'semester'=>$semester,'academic_year'=>$academicYear,'exam_type'=>$examType,'total_questions'=>count($questions),'available_questions'=>$available,'questions'=>$questions],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Failed to load verified question pool: '.$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}