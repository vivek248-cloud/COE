<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
require_once __DIR__.'/../config/config.php'; require_once __DIR__.'/../config/db.php'; require_once __DIR__.'/../includes/auth.php'; require_once __DIR__.'/../includes/system.php'; require_once __DIR__.'/../includes/workflow.php';
function qps_slot_json(bool $ok,string $message='',array $data=[],int $status=200): never { http_response_code($status); echo json_encode(['success'=>$ok,'message'=>$message,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
if(!isLoggedIn()||qps_workflow_role()!=='STAFF') qps_slot_json(false,'Teaching Staff login required.',[],403);
$in=json_decode((string)file_get_contents('php://input'),true)?:$_POST; $csrf=(string)($_SESSION['qps_review_csrf']??'');
if($csrf===''||!hash_equals($csrf,(string)($in['csrf']??''))) qps_slot_json(false,'Security token expired. Refresh the page.',[],403);
try {
 $pdo=getDBConnection(); $user=getCurrentUser(); $staff=trim((string)($user['staff_code']??''));
 $code=strtoupper(trim((string)($in['paper_code']??''))); $year=trim((string)($in['academic_year']??''));
 $marks=filter_var($in['marks']??null,FILTER_VALIDATE_INT); $k=strtoupper(trim((string)($in['k_level']??''))); $co=strtoupper(trim((string)($in['co_level']??'')));
 $language=strtoupper(trim((string)($in['language']??'EN'))); $minimum=max(1,min(20,(int)qps_setting($pdo,'FINAL_SLOT_MIN_ELIGIBLE_QUESTIONS',1)));
 if(!$code||!$year||$marks===false||$marks<1||!preg_match('/^K[1-6]$/',$k)||!preg_match('/^CO[1-9][0-9]?$/',$co)) qps_slot_json(false,'Invalid course context or slot requirements.',[],422);
 $st=$pdo->prepare('SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1'); $st->execute([$staff,$code]); if(!$st->fetchColumn()) qps_slot_json(false,'This course is not allocated to your staff account.',[],403);
 $sql="SELECT q.unit_number AS unit_no,COALESCE(NULLIF(q.sub_unit_number,''),'—') AS sub_unit,COUNT(*) AS eligible_count
       FROM questions_draft q
       WHERE UPPER(q.staff_id)=UPPER(?) AND UPPER(q.course_code)=UPPER(?) AND q.academic_year=?
         AND UPPER(q.language)=UPPER(?) AND q.marks=? AND UPPER(q.blooms_level)=? AND UPPER(q.co_code)=?
         AND q.is_active=1 AND LOWER(q.status) IN ('draft','hod_corrections','dean_corrections')
         AND NOT EXISTS (SELECT 1 FROM corrections c WHERE c.target_type='question' AND c.target_id=q.id AND c.status='open')
       GROUP BY q.unit_number,COALESCE(NULLIF(q.sub_unit_number,''),'—')
       ORDER BY q.unit_number, q.sub_unit_number";
 $st=$pdo->prepare($sql); $st->execute([$staff,$code,$year,$language,(int)$marks,$k,$co]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
 foreach($rows as &$r){$r['unit_no']=(int)$r['unit_no'];$r['eligible_count']=(int)$r['eligible_count'];$r['warning']=$r['eligible_count']<$minimum;} unset($r);
 $total=array_sum(array_column($rows,'eligible_count'));
 qps_slot_json(true,'Eligible Unit / Sub-unit combinations loaded.',['combinations'=>$rows,'eligible_questions'=>$total,'minimum_eligible'=>$minimum,'combination_count'=>count($rows)]);
} catch(Throwable $e){error_log('[Teaching slot eligibility] '.$e->getMessage());qps_slot_json(false,'Unable to validate eligible questions. Check the server error log.',[],500);}
