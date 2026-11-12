<?php
define('PAGE_TITLE','Change Portal Password');
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/system.php';
if(!isLoggedIn()||empty($_SESSION['qps_static_role'])){header('Location: '.getBaseUrl().'/modules/auth/login.php');exit;}
$pdo=getDBConnection();if(empty($_SESSION['qps_static_csrf']))$_SESSION['qps_static_csrf']=bin2hex(random_bytes(32));
$error='';$success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals((string)$_SESSION['qps_static_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Security token expired.');
  $old=(string)($_POST['old_password']??'');$new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');
  if(strlen($new)<12)throw new RuntimeException('Use at least 12 characters for the new password.');
  if($new!==$confirm)throw new RuntimeException('New password and confirmation do not match.');
  $username=(string)($_SESSION['qps_static_username']??'');$st=$pdo->prepare('SELECT id,password_hash FROM qps_static_users WHERE username=? AND is_active=1 LIMIT 1');$st->execute([$username]);$row=$st->fetch(PDO::FETCH_ASSOC);
  if(!$row||!password_verify($old,(string)$row['password_hash']))throw new RuntimeException('Current password is incorrect.');
  $pdo->prepare('UPDATE qps_static_users SET password_hash=?,must_change_password=0,updated_at=NOW() WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),(int)$row['id']]);
  $_SESSION['qps_static_must_change']=false;unset($_SESSION['qps_static_csrf']);
  qps_audit($pdo,'STATIC_PASSWORD_CHANGED','AUTH',(string)$username);
  header('Location: '.getBaseUrl().($_SESSION['qps_static_role']==='DEAN'?'/modules/dean/dashboard.php':'/modules/coe/dashboard.php'));exit;
 }catch(Throwable $e){$error=$e->getMessage();}
}
require_once __DIR__.'/../../includes/header.php';
?>
<main class="min-h-screen flex items-center justify-center px-4 py-10"><section class="w-full max-w-md bg-white border rounded-2xl shadow-lg p-6 space-y-4"><div><p class="text-xs font-black uppercase tracking-widest text-indigo-600">Account security</p><h1 class="text-2xl font-black">Change your portal password</h1><p class="text-sm text-slate-500 mt-1">A new password is required before accessing the portal.</p></div>
<?php if($error):?><div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-700 p-3 text-sm"><?=htmlspecialchars($error)?></div><?php endif;?>
<form method="post" class="space-y-3"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['qps_static_csrf'])?>"><label class="block text-sm font-bold">Current password<input type="password" name="old_password" required autocomplete="current-password" class="mt-1 w-full rounded-xl border-slate-300"></label><label class="block text-sm font-bold">New password<input type="password" name="new_password" required minlength="12" autocomplete="new-password" class="mt-1 w-full rounded-xl border-slate-300"></label><label class="block text-sm font-bold">Confirm new password<input type="password" name="confirm_password" required minlength="12" autocomplete="new-password" class="mt-1 w-full rounded-xl border-slate-300"></label><button class="w-full rounded-xl bg-indigo-600 text-white font-black py-3">Update Password</button></form></section></main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
