<?php
/**
 * COE Staff Question Bank Template Downloader v3.
 * Staff-required fields: Q.No, Section, Marks, K-Level, CO, Question.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

requireAuth();
$lang=strtolower(trim($_GET['lang'] ?? 'english'));
if(!in_array($lang,['english','tamil','hindi','french'],true)) $lang='english';
$format=strtolower(trim($_GET['format'] ?? 'csv'));
if(!in_array($format,['csv','xlsx','docx'],true)) $format='csv';

$courseCode=trim($_GET['course'] ?? $_GET['course_code'] ?? '');
$courseTitle='';
try{
 $pdo=getDBConnection();
 $st=$pdo->prepare("SELECT coursetitle FROM courses WHERE UPPER(coursecode)=? LIMIT 1");
 $st->execute([strtoupper($courseCode)]);
 $courseTitle=(string)($st->fetchColumn() ?: '');
}catch(Throwable $e){}

$prefix='HCC_Staff_Simple_Question_Bank_Template';
if($lang==='tamil') $prefix='HCC_Staff_Vina_Vanki_Simple_Template';
elseif($lang==='hindi') $prefix='HCC_Staff_Hindi_Simple_Question_Bank_Template';
elseif($lang==='french') $prefix='HCC_Staff_Francais_Simple_Question_Bank_Template';
if($courseCode) $prefix.='_'.preg_replace('/[^a-zA-Z0-9_-]/','_',$courseCode);

if($format==='docx' || $format==='xlsx'){
 $tmpDir=sys_get_temp_dir().'/hcc_tpl_'.uniqid();
 if(!is_dir($tmpDir)) @mkdir($tmpDir,0777,true);
 $outFile=$tmpDir.'/'.$prefix.'.'.$format;
 $script=__DIR__.'/../../includes/template_generator.py';
 $cmd=sprintf('python3 %s --lang %s --format %s --output %s --course_code %s --course_title %s',
   escapeshellarg($script),escapeshellarg($lang),escapeshellarg($format),escapeshellarg($outFile),
   escapeshellarg($courseCode),escapeshellarg($courseTitle));
 exec($cmd.' 2>&1',$output,$ret);
 if($ret===0 && file_exists($outFile)){
  header('Content-Type: '.($format==='docx'
    ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
  header('Content-Disposition: attachment; filename="'.basename($outFile).'"');
  header('Content-Length: '.filesize($outFile)); header('Cache-Control: no-store');
  readfile($outFile); @unlink($outFile); @rmdir($tmpDir); exit;
 }
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$prefix.'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');

if($lang==='tamil') $headers=['வினா எண்','பகுதி','மதிப்பெண்','K-நிலை','CO','வினா'];
elseif($lang==='hindi') $headers=['प्रश्न सं.','सेक्शन','अंक','K-स्तर','CO','प्रश्न'];
elseif($lang==='french') $headers=['N° Q','Section','Points','Niveau K','CO','Question'];
else $headers=['Q.No','Section','Marks','K-Level','CO','Question'];
fputcsv($out,$headers);

$rows=[
 [1,'A',1,'K1','CO1','Which of the following data structures follows LIFO? (a) Queue (b) Stack (c) Tree (d) Graph'],
 [2,'A',2,'K2','CO1','Explain the given concept briefly.'],
 [3,'B',5,'K3','CO2','Discuss the significance of the given topic.'],
 [4,'C',10,'K4','CO3','Analyse the topic with suitable examples.']
];
if($lang==='tamil') $rows=[
 [1,'A',1,'K1','CO1','பின்வருவனவற்றில் சரியான விடையைத் தேர்ந்தெடுக்கவும். (a) ஒன்று (b) இரண்டு (c) மூன்று (d) நான்கு'],
 [2,'A',2,'K2','CO1','இந்தக் கருத்தைச் சுருக்கமாக விளக்குக.'],
 [3,'B',5,'K3','CO2','இந்தத் தலைப்பின் முக்கியத்துவத்தை விளக்குக.'],
 [4,'C',10,'K4','CO3','இந்தத் தலைப்பை பகுப்பாய்வு செய்து பொருத்தமான எடுத்துக்காட்டுகளுடன் விளக்குக.']
];
elseif($lang==='hindi') $rows=[
 [1,'A',1,'K1','CO1','सही उत्तर चुनिए। (a) एक (b) दो (c) तीन (d) चार'],
 [2,'A',2,'K2','CO1','इस विषय को संक्षेप में समझाइए।'],
 [3,'B',5,'K3','CO2','इस विषय के महत्व पर चर्चा कीजिए।'],
 [4,'C',10,'K4','CO3','इस विषय का विश्लेषण उदाहरण सहित कीजिए।']
];
elseif($lang==='french') $rows=[
 [1,'A',1,'K1','CO1','Choisissez la bonne réponse. (a) Un (b) Deux (c) Trois (d) Quatre'],
 [2,'A',2,'K2','CO1','Expliquez brièvement ce sujet.'],
 [3,'B',5,'K3','CO2','Discutez l’importance de ce sujet.'],
 [4,'C',10,'K4','CO3','Analysez ce sujet avec des exemples appropriés.']
];
foreach($rows as $row) fputcsv($out,$row);
fclose($out); exit;
