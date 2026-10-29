<?php
/**
 * Return the latest published HOD Question Bank Blueprint for a course.
 * COE blueprint pages consume this; the institutional `blueprints` table is
 * intentionally not used here.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/question_bank_blueprint.php';

requireAuth();
$pdo=getDBConnection();
$course=strtoupper(trim((string)($_GET['paper_code'] ?? '')));
$semester=trim((string)($_GET['semester'] ?? ''));
$academicYear=trim((string)($_GET['academic_year'] ?? ''));
$examType=trim((string)($_GET['exam_type'] ?? ''));
if($course===''){echo json_encode(['success'=>false,'error'=>'Course code is required.']);exit;}

try{
    $sql="SELECT * FROM question_bank_blueprints WHERE status='PUBLISHED' AND qbb_enabled=1 AND UPPER(paper_code)=UPPER(?)";
    $params=[$course];
    if($semester!==''){ $sql.=" AND (semester=? OR semester IS NULL OR semester='')"; $params[]=$semester; }
    if($academicYear!==''){ $sql.=" AND (academic_year=? OR academic_year IS NULL OR academic_year='')"; $params[]=$academicYear; }
    if($examType!==''){ $sql.=" AND (exam_type=? OR exam_type IS NULL OR exam_type='')"; $params[]=$examType; }
    $sql.=" ORDER BY published_at DESC, id DESC LIMIT 1";
    $st=$pdo->prepare($sql);$st->execute($params);$bp=$st->fetch(PDO::FETCH_ASSOC);
    if(!$bp){
        echo json_encode(['success'=>true,'found'=>false,'paper_code'=>$course,'message'=>'No published Question Bank Blueprint exists for '.$course.'.'],JSON_UNESCAPED_UNICODE);exit;
    }
    $matrix=json_decode((string)$bp['matrix_json'],true); if(!is_array($matrix))$matrix=[];
    $stats=qps_qbb_pool_stats($pdo,$course,(string)$bp['semester'],(string)$bp['academic_year'],(string)$bp['exam_type']);
    $labels=qps_qbb_choice_labels();
    $rows=[];
    foreach($matrix as $i=>$r){
        $su=(string)($r['sub_unit']??'');$u=(int)($r['unit']??0);
        $rows[]=[
            'row_no'=>$i+1,'section'=>(string)($r['section']??'A'),'unit'=>$u,'sub_unit'=>$su,
            'question_type'=>(string)($r['question_type']??''),'k_level'=>(string)($r['k_level']??''),
            'co_level'=>'CO'.max(1,(int)preg_replace('/\D/','',(string)($r['k_level']??'K1'))),
            'marks'=>(int)($r['marks']??0),'required_count'=>(int)($r['required_count']??0),
            'choice_mode'=>(string)($r['choice_mode']??'ALL'),'choice_label'=>$labels[(string)($r['choice_mode']??'ALL')]??$labels['ALL'],
            'compulsory'=>(int)($r['compulsory']??0),'instruction'=>(string)($r['instruction']??''),
            'available_subunit'=>(int)($stats['sub_units'][$su]??0),'available_unit'=>(int)($stats['units'][$u]??0)
        ];
    }
    echo json_encode([
        'success'=>true,'found'=>true,'blueprint_id'=>(int)$bp['id'],'paper_code'=>$bp['paper_code'],
        'course_title'=>$bp['course_title'],'semester'=>$bp['semester'],'academic_year'=>$bp['academic_year'],
        'exam_type'=>$bp['exam_type'],'total_questions'=>(int)$bp['total_questions'],'total_marks'=>(int)$bp['total_marks'],
        'published_at'=>$bp['published_at'],'qbb_enabled'=>(int)$bp['qbb_enabled'],'rows'=>$rows,
        'pool_total'=>(int)($stats['total']??0),'units'=>$stats['units']??[],'sub_units'=>$stats['sub_units']??[]
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(400);echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
