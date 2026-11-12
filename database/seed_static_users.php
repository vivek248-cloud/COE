<?php
/**
 * Run once from CLI after migrate_workflow_dean_coe_v1.sql:
 * php database/seed_static_users.php
 * Set QPS_DEAN_PASSWORD / QPS_COE_PASSWORD before running in production.
 */
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/db.php';
$pdo=getDBConnection();
$accounts=[
 ['dean',getenv('QPS_DEAN_PASSWORD') ?: 'dean@123','Static Dean','DEAN'],
 ['coe',getenv('QPS_COE_PASSWORD') ?: 'coe@123','COE Office','COE']
];
$st=$pdo->prepare("INSERT INTO qps_static_users(username,password_hash,display_name,role,is_active,must_change_password)
 VALUES(?,?,?,?,1,1) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),display_name=VALUES(display_name),role=VALUES(role),is_active=1");
foreach($accounts as [$username,$password,$name,$role]){
 $st->execute([$username,password_hash($password,PASSWORD_DEFAULT),$name,$role]);
}
echo "Static Dean and COE accounts seeded. Change the default passwords immediately.\n";
