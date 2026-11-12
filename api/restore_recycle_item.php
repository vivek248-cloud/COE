<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$pdo=getDBConnection(); $user=getCurrentUser() ?: [];
qps_ensure_recycle_schema($pdo);
$input=json_decode((string)file_get_contents('php://input'),true) ?: $_POST;
$type=strtolower(trim((string)($input['type'] ?? '')));
$id=(int)($input['id'] ?? 0);
try {
 if ($id<1 || !in_array($type,['paper','bank'],true)) throw new RuntimeException('Invalid recycle item.');
 $table=$type==='paper'?'generated_papers':'question_banks';
 $st=$pdo->prepare("SELECT * FROM {$table} WHERE id=? AND is_deleted=1");
 $st->execute([$id]); $item=$st->fetch(PDO::FETCH_ASSOC);
 if (!$item) throw new RuntimeException('Deleted item not found.');
 if (empty($item['expires_at']) || strtotime($item['expires_at']) < time()) {
   throw new RuntimeException('The 5-day restore window has expired. This item can no longer be restored.');
 }
 if ($type==='bank') {
   $owner=($user['staff_code']??'')===($item['staff_code']??'');
   $hod=isHOD() && strtoupper($user['dept_code']??'')===strtoupper($item['dept_code']??'');
   if (!isCOE() && !isSuperAdmin() && !$owner && !$hod) throw new RuntimeException('Permission denied.');
 } elseif (!isCOE() && !isSuperAdmin()) { throw new RuntimeException('Permission denied.'); }
 $pdo->prepare("UPDATE {$table} SET is_deleted=0, deleted_at=NULL, deleted_by=NULL, expires_at=NULL WHERE id=?")->execute([$id]);
 qps_audit($pdo,$type==='paper'?'QUESTION_PAPER_RESTORED':'QUESTION_BANK_ITEM_RESTORED',strtoupper($type==='paper'?'GENERATED_PAPER':'QUESTION_BANK'),(string)$id,['paper_code'=>$item['paper_code']??'','restored_by'=>$user['staff_code']??'SYSTEM']);
 echo json_encode(['success'=>true,'message'=>'Item restored successfully.']);
} catch(Throwable $e) { http_response_code(400); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
