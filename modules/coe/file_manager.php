<?php
declare(strict_types=1);
define('PAGE_TITLE','COE File Manager');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';
requireCOE();
$pdo=getDBConnection();
$user=getCurrentUser() ?: [];
$root=realpath(BASE_PATH.'/storage/uploads');
if(!$root){ @mkdir(BASE_PATH.'/storage/uploads',0777,true); $root=realpath(BASE_PATH.'/storage/uploads'); }
$rel=trim((string)($_GET['path']??''),"/\\");
$rel=preg_replace('#[^a-zA-Z0-9_./\\ -]#','',$rel);
$target=realpath($root.($rel!==''?DIRECTORY_SEPARATOR.$rel:''));
if(!$target || strpos($target,$root)!==0 || !is_dir($target)) { $rel=''; $target=$root; }

// Safe download endpoint from the same page.
if(isset($_GET['download'])){
    $drel=trim((string)$_GET['download'],"/\\");
    $file=realpath($root.DIRECTORY_SEPARATOR.$drel);
    if(!$file || strpos($file,$root)!==0 || !is_file($file)){ http_response_code(404); exit('File not found.'); }
    $mime=function_exists('mime_content_type')?(mime_content_type($file)?:'application/octet-stream'):'application/octet-stream';
    header('Content-Type: '.$mime); header('Content-Length: '.filesize($file)); header('Content-Disposition: attachment; filename="'.basename($file).'"'); readfile($file); exit;
}

$items=[];
foreach(scandir($target) ?: [] as $name){
    if($name==='.'||$name==='..'||$name==='.htaccess'||$name==='web.config') continue;
    $full=$target.DIRECTORY_SEPARATOR.$name; $isDir=is_dir($full);
    $items[]=['name'=>$name,'dir'=>$isDir,'size'=>$isDir?0:(int)@filesize($full),'mtime'=>(int)@filemtime($full),'rel'=>ltrim(($rel!==''?$rel.'/':'').$name,'/')];
}
usort($items,fn($a,$b)=>$a['dir']!==$b['dir']?($a['dir']?-1:1):strnatcasecmp($a['name'],$b['name']));

// Make the standard folders visible for every course/year without hiding real files.
if($rel!==''){
    foreach(['DOCS','GENERATED PAPERS','BLUEPRINTS'] as $folder){
        if(!is_dir($target.DIRECTORY_SEPARATOR.$folder)) @mkdir($target.DIRECTORY_SEPARATOR.$folder,0777,true);
    }
    // Backfill existing uploaded documents into DOCS so older uploads also follow the new hierarchy.
    $docExt=['doc','docx','pdf','csv','xls','xlsx','ods','txt','zip'];
    foreach(scandir($target) ?: [] as $legacy){
        $legacyPath=$target.DIRECTORY_SEPARATOR.$legacy;
        if(is_file($legacyPath) && in_array(strtolower(pathinfo($legacy,PATHINFO_EXTENSION)),$docExt,true)){
            $docTarget=$target.DIRECTORY_SEPARATOR.'DOCS'.DIRECTORY_SEPARATOR.$legacy;
            if(!is_file($docTarget)) @copy($legacyPath,$docTarget);
        }
    }
}

$parts=$rel===''?[]:explode('/',$rel); $crumb=[]; $acc='';
foreach($parts as $part){ $acc=$acc===''?$part:$acc.'/'.$part; $crumb[]=['name'=>$part,'path'=>$acc]; }

function fm_size($n){ if($n<1024)return $n.' B'; if($n<1048576)return number_format($n/1024,1).' KB'; if($n<1073741824)return number_format($n/1048576,1).' MB'; return number_format($n/1073741824,1).' GB'; }
function fm_icon($name,$dir){ if($dir)return 'folder'; $e=strtolower(pathinfo($name,PATHINFO_EXTENSION)); return match($e){'pdf'=>'file-text','doc','docx'=>'file-type-2','xls','xlsx','csv'=>'table-2','json','gz'=>'file-code','zip'=>'archive',default=>'file'}; }
require_once __DIR__.'/../../includes/header.php'; require_once __DIR__.'/../../includes/navbar.php'; require_once __DIR__.'/../../includes/sidebar.php';
?>
<style>.fm-card{border:1px solid #e2e8f0;border-radius:22px;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.06)}.fm-item:hover{background:#f8fafc}.fm-grid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr))}</style>
<main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-5">
<section class="fm-card p-6 bg-gradient-to-r from-slate-950 via-indigo-950 to-slate-900 text-white">
 <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4"><div><div class="text-[10px] uppercase font-black text-amber-300">COE SECURE STORAGE</div><h1 class="text-2xl font-black mt-1">COE File Manager</h1><p class="text-xs text-slate-300 mt-1">Courses → Semester → Academic Year → DOCS / GENERATED PAPERS / BLUEPRINTS</p></div><div class="text-right text-xs"><div class="font-black"><?=htmlspecialchars($user['name']??'COE Office')?></div><div class="text-slate-300 font-mono"><?=htmlspecialchars($user['staff_code']??'COE_OFFICE')?></div></div></div>
</section>
<section class="fm-card p-4"><div class="flex flex-wrap items-center gap-2 text-xs"><a class="px-3 py-2 rounded-xl bg-slate-100 font-black" href="?">My Storage</a><?php foreach($crumb as $c): ?><span class="text-slate-400">/</span><a class="px-3 py-2 rounded-xl bg-indigo-50 text-indigo-700 font-black" href="?path=<?=urlencode($c['path'])?>"><?=htmlspecialchars($c['name'])?></a><?php endforeach; ?></div></section>
<section class="fm-card p-5">
 <div class="flex items-center justify-between mb-4"><div><h2 class="font-black text-slate-900">Files & Folders</h2><p class="text-[11px] text-slate-500"><?=count($items)?> item(s) • Stored under server storage</p></div></div>
 <div class="grid fm-grid gap-3">
 <?php if($rel!=='' && count($parts)>0): $parent=implode('/',array_slice($parts,0,-1)); ?><a href="?path=<?=urlencode($parent)?>" class="fm-item border rounded-2xl p-4"><i data-lucide="corner-up-left" class="w-6 h-6 text-slate-500"></i><div class="font-black text-xs mt-3">Back</div></a><?php endif; ?>
 <?php foreach($items as $it): if($it['dir']): ?><a href="?path=<?=urlencode($it['rel'])?>" class="fm-item border rounded-2xl p-4"><i data-lucide="folder" class="w-10 h-10 text-amber-400"></i><div class="font-black text-xs mt-3 truncate"><?=htmlspecialchars($it['name'])?></div><div class="text-[10px] text-slate-400 mt-1">Folder</div></a>
 <?php else: ?><div class="fm-item border rounded-2xl p-4"><i data-lucide="<?=fm_icon($it['name'],false)?>" class="w-10 h-10 text-indigo-500"></i><div class="font-black text-xs mt-3 truncate" title="<?=htmlspecialchars($it['name'])?>"><?=htmlspecialchars($it['name'])?></div><div class="text-[10px] text-slate-400 mt-1"><?=fm_size($it['size'])?> • <?=date('d M Y H:i',$it['mtime'])?></div><a href="?download=<?=urlencode($it['rel'])?>" class="inline-flex mt-3 px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-[10px] font-black">Download</a></div><?php endif; endforeach; ?>
 </div>
 <?php if(!$items): ?><div class="py-16 text-center text-slate-400 text-xs">This folder is empty.</div><?php endif; ?>
</section>
</main>
