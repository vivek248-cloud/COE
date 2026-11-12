<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/system.php';
require_once __DIR__.'/../includes/workflow.php';
require_once __DIR__.'/../includes/paper_rebuild.php';
$pdo=getDBConnection();
qps_ensure_aux_schema($pdo);
qps_ensure_workflow_schema($pdo);
function qps_set_letter(int $index): string { return chr(65+$index); }
function qps_shuffle_options(array $q,int $enabled): array {
    if(!$enabled || strtoupper((string)($q['question_type']??''))!=='MCQ') return [[], $q['answer_key']??null];
    $raw=$q['options']??$q['options_json']??[];
    if(is_string($raw))$raw=json_decode($raw,true);
    if(!is_array($raw)||count($raw)<2)return [[], $q['answer_key']??null];
    $keys=array_keys($raw);$movable=[];$fixed=[];
    foreach($keys as $i=>$k){$v=(string)$raw[$k];if(preg_match('/^(all of the above|none of the above)$/i',trim(strip_tags($v))))$fixed[]=$i;else $movable[]=$i;}
    shuffle($movable);$order=array_merge($movable,$fixed);
    $answer=$q['answer_key']??null;
    if(is_string($answer)&&preg_match('/^[A-D]$/i',trim($answer))){
       $old=ord(strtoupper(trim($answer)))-65;$new=array_search($old,$order,true);
       if($new!==false)$answer=chr(65+$new);
    } elseif(is_numeric($answer)){
       $new=array_search((int)$answer,$order,true);if($new!==false)$answer=$new;
    }
    return [$order,$answer];
}
try{
 requireAuth();qps_workflow_require_role(['COE']);
 $in=json_decode((string)file_get_contents('php://input'),true);if(!is_array($in))$in=$_POST;
 $csrf=(string)($_SESSION['qps_review_csrf']??'');
 if($csrf===''||!hash_equals($csrf,(string)($in['csrf']??'')))throw new RuntimeException('Security token expired. Refresh the page.');
 $blueprintId=(int)($in['final_blueprint_id']??0);$setCount=(int)($in['set_count']??1);
 if($blueprintId<1||!in_array($setCount,[1,2,3],true))throw new RuntimeException('Select a course and a valid set count.');
 $st=$pdo->prepare("SELECT * FROM qps_final_blueprints WHERE id=? AND UPPER(status)='ACTIVE' LIMIT 1");$st->execute([$blueprintId]);$bp=$st->fetch(PDO::FETCH_ASSOC);
 if(!$bp)throw new RuntimeException('Active final blueprint not found.');
 $slots=json_decode((string)$bp['slots_config'],true);if(isset($slots['slots']))$slots=$slots['slots'];if(!is_array($slots)||!$slots)throw new RuntimeException('Final blueprint has no slots.');
 $st=$pdo->prepare("SELECT a.slot_key,a.slot_number,a.section_type,a.question_id,q.q_number,q.fixed_q_no,q.unit_no,q.sub_unit,q.question_type,q.marks,q.k_level,q.co_level,q.question_text,q.options_json,q.answer_key,q.match_column_a,q.match_column_b,q.assertion_text,q.reason_text,q.image_url
  FROM qps_coe_question_allotments a JOIN questions q ON q.id=a.question_id WHERE a.final_blueprint_id=? ORDER BY a.slot_number,a.id");
 $st->execute([$blueprintId]);$allot=$st->fetchAll(PDO::FETCH_ASSOC);
 $bySlot=[];foreach($allot as $r)$bySlot[(string)$r['slot_key']]=$r;
 $canonical=[];
 foreach($slots as $i=>$slot){$key=(string)($slot['slot_key']??$slot['slot_id']??(($slot['section_type']??'SECTION-A').'-'.($slot['slot_number']??($i+1))));
   if(!isset($bySlot[$key]))throw new RuntimeException("Question slot {$key} has not been allotted.");
   $q=$bySlot[$key];$opts=json_decode((string)($q['options_json']??''),true);
   $canonical[]=['slot_key'=>$key,'slot_number'=>(int)($slot['slot_number']??$i+1),'option_label'=>strtolower((string)($slot['option_label']??'')),'section_type'=>(string)($slot['section_type']??$q['section_type']??'SECTION-A'),
     'question_type'=>strtoupper((string)($q['question_type']??'')),'marks'=>(int)$q['marks'],'k_level'=>$q['k_level'],'co_level'=>$q['co_level'],
     'question_id'=>(int)$q['question_id'],'q_number'=>(int)($q['fixed_q_no']?:$q['q_number']),'unit_no'=>(int)$q['unit_no'],'sub_unit'=>$q['sub_unit'],
     'question_text'=>$q['question_text'],'options'=>is_array($opts)?array_values($opts):[],'answer_key'=>$q['answer_key'],
     'match_column_a'=>$q['match_column_a'],'match_column_b'=>$q['match_column_b'],'assertion_text'=>$q['assertion_text'],'reason_text'=>$q['reason_text'],'image_url'=>$q['image_url'],
     'is_compulsory'=>!empty($slot['is_compulsory'])];
 }
 $questionsJson=json_encode($canonical,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 $contentHash=hash('sha256',$questionsJson);
 $pkgStmt=$pdo->prepare("SELECT id,status,bank_draft_id FROM qps_submission_packages WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? AND exam_type=? ORDER BY id DESC LIMIT 1");
 $pkgStmt->execute([$bp['paper_code'],$bp['semester'],$bp['academic_year'],$bp['exam_type']]);$pkg=$pkgStmt->fetch(PDO::FETCH_ASSOC);
 $courseType='';
 if($pkg && !empty($pkg['bank_draft_id'])){$stType=$pdo->prepare('SELECT course_type FROM qps_question_bank_drafts WHERE id=?');$stType->execute([(int)$pkg['bank_draft_id']]);$courseType=strtoupper((string)$stType->fetchColumn());}
 if($courseType===''){$stType=$pdo->prepare('SELECT papertype FROM courses WHERE UPPER(coursecode)=UPPER(?) LIMIT 1');$stType->execute([$bp['paper_code']]);$courseType=strtoupper((string)$stType->fetchColumn());}
 $headerData=[];
 $stHeader=$pdo->query('SELECT id,name FROM qps_exam_headers WHERE is_default=1 ORDER BY id DESC LIMIT 1');$header=$stHeader->fetch(PDO::FETCH_ASSOC);
 if($header){$stLines=$pdo->prepare('SELECT line_no,line_text FROM qps_exam_header_lines WHERE header_id=? ORDER BY line_no');$stLines->execute([(int)$header['id']]);$headerData=['name'=>$header['name'],'lines'=>$stLines->fetchAll(PDO::FETCH_ASSOC)];}
 $paperTypeData=[];
 $stTypes=$pdo->prepare("SELECT setting_value FROM qps_settings WHERE setting_key='paper_types_json'");$stTypes->execute();$allTypes=json_decode((string)$stTypes->fetchColumn(),true);
 if(is_array($allTypes)){foreach($allTypes as $type){if(strtoupper((string)($type['code']??''))===$courseType){$paperTypeData=$type;break;}}}
 $headerJson=json_encode($headerData,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 $paperTypeJson=json_encode($paperTypeData,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 $actor=qps_workflow_actor();$pdo->beginTransaction();
 try{
   $find=$pdo->prepare("SELECT id FROM qps_final_papers WHERE package_id <=> ? AND paper_code=? AND semester=? AND academic_year=? AND exam_type=? LIMIT 1");
   $find->execute([$pkg['id']??null,$bp['paper_code'],$bp['semester'],$bp['academic_year'],$bp['exam_type']]);$paperId=(int)$find->fetchColumn();
   if($paperId){
      $pdo->prepare("UPDATE qps_final_papers SET final_blueprint_id=?,questions_json=?,header_json=?,paper_type_json=?,content_hash=?,created_by=? WHERE id=?")->execute([$blueprintId,$questionsJson,$headerJson,$paperTypeJson,$contentHash,$actor['code'],$paperId]);
      $pdo->prepare("DELETE FROM qps_paper_sets WHERE final_paper_id=?")->execute([$paperId]);
   }else{
      $pdo->prepare("INSERT INTO qps_final_papers(package_id,final_blueprint_id,paper_code,semester,academic_year,exam_type,questions_json,header_json,paper_type_json,content_hash,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$pkg['id']??null,$blueprintId,$bp['paper_code'],$bp['semester'],$bp['academic_year'],$bp['exam_type'],$questionsJson,$headerJson,$paperTypeJson,$contentHash,$actor['code']]);
      $paperId=(int)$pdo->lastInsertId();
   }
   for($si=0;$si<$setCount;$si++){
      $label=qps_set_letter($si);$ordered=$canonical;$seed=random_int(1,PHP_INT_MAX);mt_srand($seed);
      if($si>0){
        $groups=[];
        foreach($canonical as $q){
          $type=strtoupper($q['question_type']);$section=strtoupper($q['section_type']);
          $eligible=in_array($type,['MCQ','PARAGRAPH','ESSAY'],true)&&empty($q['is_compulsory'])&&strpos($section,'D')!==0;
          if($eligible){$g=implode('|',[$q['section_type'],$type,$q['marks'],$q['k_level'],$q['co_level']]);$groups[$g][]=$q;}
        }
        foreach($groups as $group){if(count($group)<2)continue;shuffle($group);$targetKeys=array_keys(array_filter($canonical,static fn($q)=>implode('|',[$q['section_type'],$q['question_type'],$q['marks'],$q['k_level'],$q['co_level']])===implode('|',[$group[0]['section_type'],$group[0]['question_type'],$group[0]['marks'],$group[0]['k_level'],$group[0]['co_level']]) && in_array($q['question_type'],['MCQ','PARAGRAPH','ESSAY'],true) && empty($q['is_compulsory']) && strpos(strtoupper($q['section_type']),'D')!==0));
          foreach($targetKeys as $j=>$idx)$ordered[$idx]=$group[$j];
        }
      }
      $positions=[];
      foreach($ordered as $i=>$q){
        $target=$canonical[$i];$shuffleOptions=($si>0&&$q['question_type']==='MCQ'&&!empty($q['options']));
        [$optionOrder,$answer]=qps_shuffle_options($q,$shuffleOptions?1:0);
        $printNumber=(string)$target['slot_number'].(!empty($target['option_label'])?'.'.strtolower((string)$target['option_label']):'');
        $positions[]=['slot_key'=>$q['slot_key'],'print_number'=>$printNumber,'option_order'=>$optionOrder,'answer_key'=>$answer];
      }
      $ordering=['positions'=>$positions];$orderingJson=json_encode($ordering,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      $setKey=substr(preg_replace('/[^A-Za-z0-9_-]/','_', (string)$bp['paper_code']),0,40).'-'.$label.'-'.substr(hash('sha256',$contentHash.$label.$orderingJson),0,16);
      $pdo->prepare("INSERT INTO qps_paper_sets(final_paper_id,set_label,set_key,ordering_json,seed,created_by) VALUES(?,?,?,?,?,?)")
        ->execute([$paperId,$label,$setKey,$orderingJson,$seed,$actor['code']]);
   }
   if($pkg && $pkg['status']==='ALLOTTED'){
      // State transition is recorded after commit to avoid nested transactions.
   }
   qps_audit($pdo,'COE_PAPER_SETS_SAVED','FINAL_PAPER',(string)$paperId,['set_count'=>$setCount,'paper_code'=>$bp['paper_code']]);
   $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 if($pkg && $pkg['status']==='ALLOTTED') qps_transition($pdo,(int)$pkg['id'],'COE_GENERATE','Generated '.$setCount.' saved paper set(s).');
 $st=$pdo->prepare('SELECT set_label,set_key FROM qps_paper_sets WHERE final_paper_id=? ORDER BY set_label');$st->execute([$paperId]);
 echo json_encode(['success'=>true,'paper_id'=>$paperId,'sets'=>$st->fetchAll(PDO::FETCH_ASSOC),'message'=>'Paper sets generated and saved.'],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(400);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
