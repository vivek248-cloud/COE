<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireCOE();
$pdo=getDBConnection();
try{
  qps_ensure_aux_schema($pdo);
  $rows=$pdo->query("SELECT id,name,dept_code,paper_code,total_marks,duration_hours,sections_config,instructions FROM blueprints ORDER BY id DESC")->fetchAll();
  foreach($rows as &$r){$r['sections_config']=json_decode($r['sections_config']??'[]',true)?:[];}
  echo json_encode(['success'=>true,'blueprints'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(500);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
