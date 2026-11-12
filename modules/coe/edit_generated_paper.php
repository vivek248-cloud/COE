<?php
declare(strict_types=1);
define('PAGE_TITLE','Edit Generated Question Paper');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
requireCOE();
$pdo=getDBConnection(); qps_ensure_aux_schema($pdo); $user=getCurrentUser() ?: [];
if (empty($_SESSION['coe_paper_edit_csrf'])) $_SESSION['coe_paper_edit_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['coe_paper_edit_csrf']; $id=(int)($_GET['paper_id'] ?? $_POST['paper_id'] ?? 0);
$st=$pdo->prepare('SELECT * FROM generated_papers WHERE id=? AND deleted_at IS NULL'); $st->execute([$id]); $paper=$st->fetch(PDO::FETCH_ASSOC);
if(!$paper){http_response_code(404);exit('Generated paper not found.');}
$error=''; $msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($csrf,(string)($_POST['csrf']??''))) throw new RuntimeException('Security token expired. Reload and try again.');
  $raw=trim((string)($_POST['paper_data_json']??'')); $decoded=json_decode($raw,true);
  if(!is_array($decoded) || json_last_error()!==JSON_ERROR_NONE) throw new RuntimeException('Paper data must be valid JSON.');
  if(!isset($decoded['sections']) || !is_array($decoded['sections'])) throw new RuntimeException('Paper JSON must contain a sections array.');
  $before=$paper['paper_data_json'] ?? '';
  $pdo->beginTransaction();
  $up=$pdo->prepare('UPDATE generated_papers SET paper_data_json=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL');
  $up->execute([json_encode($decoded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);
  qps_audit($pdo,'QUESTION_PAPER_EDITED','QUESTION_PAPER',(string)$id,['actor'=>$user['staff_code']??'COE_OFFICE','changed'=>'paper allocations/content','before_hash'=>hash('sha256',(string)$before),'after_hash'=>hash('sha256',$raw)]);
  $pdo->commit(); $msg='Paper allocations saved. The audit trail has been updated.';
  $st->execute([$id]); $paper=$st->fetch(PDO::FETCH_ASSOC);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
require_once __DIR__.'/../../includes/header.php'; require_once __DIR__.'/../../includes/navbar.php';
?>
<main class="max-w-5xl mx-auto px-4 py-6 space-y-4"><div class="bg-white rounded-2xl border p-5"><h1 class="text-xl font-black">Edit Generated Paper #<?php echo $id; ?></h1><p class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars(($paper['paper_code']??'').' · '.($paper['set_name']??'')); ?></p><?php if($msg):?><div class="my-3 p-3 bg-emerald-50 text-emerald-700 rounded-xl text-sm"><?php echo htmlspecialchars($msg);?></div><?php endif;?><?php if($error):?><div class="my-3 p-3 bg-rose-50 text-rose-700 rounded-xl text-sm"><?php echo htmlspecialchars($error);?></div><?php endif;?><form method="post" class="space-y-3"><input type="hidden" name="paper_id" value="<?php echo $id;?>"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf);?>"><label class="block text-sm font-bold">Paper content and question allocations (JSON)</label><textarea name="paper_data_json" rows="28" spellcheck="false" class="w-full border rounded-xl p-3 font-mono text-xs"><?php echo htmlspecialchars((string)($paper['paper_data_json']??''),ENT_QUOTES,'UTF-8');?></textarea><div class="flex justify-end gap-2"><a href="<?php echo getBaseUrl();?>/modules/coe/file_manager.php?view=papers" class="px-4 py-2 rounded-xl border text-sm">Cancel</a><button class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold text-sm">Save Changes</button></div></form></div></main>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
