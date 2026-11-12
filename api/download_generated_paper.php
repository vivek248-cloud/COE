<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';
requireCOE(); $pdo=getDBConnection(); qps_ensure_recycle_schema($pdo);
$id=(int)($_GET['id']??0);
$st=$pdo->prepare('SELECT paper_code,set_name,paper_data_json FROM generated_papers WHERE id=? AND COALESCE(is_deleted,0)=0');
$st->execute([$id]); $p=$st->fetch(PDO::FETCH_ASSOC);
if(!$p){http_response_code(404);exit('Generated paper not found.');}
$name=preg_replace('/[^A-Za-z0-9_.-]/','_',($p['paper_code']??'paper').'_'.($p['set_name']??'set').'.json');
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$name.'"');
echo $p['paper_data_json'] ?: '{}';
