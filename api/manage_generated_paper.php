<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!isCOE() && !isSuperAdmin()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
$pdo=getDBConnection(); $user=getCurrentUser() ?: []; qps_ensure_recycle_schema($pdo);
$in=json_decode((string)file_get_contents('php://input'),true) ?: $_POST;
$id=(int)($in['paper_id']??0); $action=(string)($in['action']??'');
try {
 if($id<1) throw new RuntimeException('Invalid generated paper ID.');
 $st=$pdo->prepare('SELECT * FROM generated_papers WHERE id=?'); $st->execute([$id]); $paper=$st->fetch(PDO::FETCH_ASSOC);
 if(!$paper) throw new RuntimeException('Generated paper not found.');
 if($action==='delete') {
   if((int)($paper['is_deleted']??0)===1) throw new RuntimeException('Paper is already deleted.');
   $pdo->prepare('UPDATE generated_papers SET is_deleted=1, deleted_at=?, deleted_by=?, expires_at=? WHERE id=?')
       ->execute([date('Y-m-d H:i:s'),$user['staff_code']??'COE_OFFICE',date('Y-m-d H:i:s',time()+5*86400),$id]);
   qps_audit($pdo,'QUESTION_PAPER_DELETED','GENERATED_PAPER',(string)$id,['paper_code'=>$paper['paper_code']??'','set_name'=>$paper['set_name']??'']);
   echo json_encode(['success'=>true,'message'=>'Paper moved to recycle bin for 5 days.']); exit;
 }
 if($action==='edit') {
   $newJson=$in['paper_data_json']??null;
   if(is_array($newJson)) $newJson=json_encode($newJson,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
   if(!is_string($newJson) || json_decode($newJson,true)===null) throw new RuntimeException('Valid paper allocation JSON is required.');
   $pdo->prepare('UPDATE generated_papers SET paper_data_json=? WHERE id=?')->execute([$newJson,$id]);
   qps_audit($pdo,'QUESTION_PAPER_EDITED','GENERATED_PAPER',(string)$id,['paper_code'=>$paper['paper_code']??'','allocation_change'=>true,'edited_by'=>$user['staff_code']??'COE_OFFICE']);
   echo json_encode(['success'=>true,'message'=>'Paper allocations saved and audit event recorded.']); exit;
 }
 throw new RuntimeException('Unsupported action.');
} catch(Throwable $e) { http_response_code(400); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
