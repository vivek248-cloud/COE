<?php
/** Rebuild a generated paper from canonical questions and compact set ordering. */
declare(strict_types=1);
function qps_rebuild_paper(PDO $pdo,string $setKey): array {
    $st=$pdo->prepare("SELECT s.*,p.questions_json,p.header_json,p.paper_type_json,p.paper_code,p.semester,p.academic_year,p.exam_type,p.final_blueprint_id,fb.course_title,fb.total_marks
      FROM qps_paper_sets s JOIN qps_final_papers p ON p.id=s.final_paper_id LEFT JOIN qps_final_blueprints fb ON fb.id=p.final_blueprint_id WHERE s.set_key=? LIMIT 1");
    $st->execute([$setKey]);$row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row) throw new RuntimeException('Saved paper set was not found.');
    $questions=json_decode((string)$row['questions_json'],true);
    $ordering=json_decode((string)$row['ordering_json'],true);
    if(!is_array($questions)||!is_array($ordering))throw new RuntimeException('Saved paper data is invalid.');
    $bySlot=[];
    foreach($questions as $q){$key=(string)($q['slot_key']??$q['slot_number']??'');if($key!=='')$bySlot[$key]=$q;}
    $rebuilt=[];
    foreach(($ordering['positions']??[]) as $position){
        $slot=(string)($position['slot_key']??'');
        if(!isset($bySlot[$slot]))continue;
        $q=$bySlot[$slot];
        if(isset($position['option_order'])&&is_array($position['option_order'])&&isset($q['options'])&&is_array($q['options'])){
            $opts=[];foreach($position['option_order'] as $ix){if(array_key_exists((int)$ix,$q['options']))$opts[]=$q['options'][(int)$ix];}
            if($opts)$q['options']=$opts;
            if(isset($position['answer_key']))$q['answer_key']=$position['answer_key'];
        }
        $q['print_number']=$position['print_number']??(count($rebuilt)+1);
        $rebuilt[]=$q;
    }
    if(!$rebuilt)$rebuilt=array_values($questions);
    return ['paper_code'=>$row['paper_code'],'semester'=>$row['semester'],'academic_year'=>$row['academic_year'],
      'exam_type'=>$row['exam_type'],'course_title'=>$row['course_title']??$row['paper_code'],'total_marks'=>$row['total_marks']??array_sum(array_map(static fn($q)=>(int)($q['marks']??0),$rebuilt)),'set_name'=>$row['set_label'],'set_key'=>$row['set_key'],
      'blueprint_id'=>$row['final_blueprint_id'],'header'=>json_decode((string)$row['header_json'],true)?:[],
      'paper_type'=>json_decode((string)$row['paper_type_json'],true)?:[],'questions'=>$rebuilt];
}
