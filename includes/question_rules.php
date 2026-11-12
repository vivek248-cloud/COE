<?php

/** Shared Dean rule resolver and final-blueprint validator. */

declare(strict_types=1);



function qps_programme_levels(): array { return ['1st UG','2nd UG','3rd UG','1st PG','2nd PG']; }

function qps_semester_options(): array { return ['Semester 1','Semester 2','Semester 3','Semester 4','Semester 5','Semester 6','Semester 7','Semester 8']; }



function qps_resolve_programme_level(array $course): string {

          $allowed=qps_programme_levels();

          foreach (['programme_year','programme_level','programme','degree_level','level'] as $key) {

                    $value=trim((string)($course[$key]??''));

                    if(in_array($value,$allowed,true)) return $value;

                    foreach($allowed as $level) if($value!=='' && stripos($value,$level)!==false) return $level;

          }

          $degree=strtoupper(trim((string)($course['degree_level']??$course['level']??$course['programme']??'')));

          $pg=(bool)preg_match('/PG|POST.?GRAD|MASTER|M\.?SC|M\.?A\b|MCA|MBA|MCOM|PGDCA/',$degree);

          $ug=(bool)preg_match('/UG|UNDER.?GRAD|BACHELOR/',$degree);

          if(!$pg&&!$ug)return '';

          $sem=(string)($course['semester']??'');$n=0;

          if(preg_match('/(?:SEM(?:ESTER)?[\s_-]*)?([1-8])\b/i',$sem,$m))$n=(int)$m[1];

          elseif(preg_match('/SEMESTER[\s_-]*(VIII|VII|VI|V|IV|III|II|I)\b/i',$sem,$m))$n=['I'=>1,'II'=>2,'III'=>3,'IV'=>4,'V'=>5,'VI'=>6,'VII'=>7,'VIII'=>8][strtoupper($m[1])]??0;

          if($n<1)return '';

          if($pg)return $n<=2?'1st PG':($n<=4?'2nd PG':'');

          return $n<=2?'1st UG':($n<=4?'2nd UG':($n<=6?'3rd UG':''));

}

function qps_year_start(string $year): int { return preg_match('/\b(19|20)\d{2}\b/',$year,$m)?(int)$m[0]:0; }




function qps_allowed_total_marks(PDO $pdo): array
{
    // Collect standard and configured paper-type marks.
    $types = function_exists('hcc_paper_types')
        ? hcc_paper_types($pdo)
        : [];

    $defaults = function_exists('hcc_default_paper_types')
        ? hcc_default_paper_types()
        : [];

    $marks = [];

    foreach ([$defaults, $types] as $source) {
        foreach ($source as $type) {
            $n = (int)($type['total_marks'] ?? 0);

            if ($n > 0 && $n <= 300) {
                $marks[$n] = $n;
            }
        }
    }

    // Include Total Marks configured in published Dean templates.
    try {
        $st = $pdo->query("
            SELECT DISTINCT total_marks
            FROM question_paper_templates
            WHERE is_active = 1
              AND LOWER(status) = 'published'
              AND total_marks > 0
              AND total_marks <= 300
        ");

        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $n = (int)$value;

            if ($n > 0 && $n <= 300) {
                $marks[$n] = $n;
            }
        }
    } catch (Throwable $e) {
        error_log(
            'Dean template total marks lookup: ' . $e->getMessage()
        );
    }

    $marks = array_values($marks);
    rsort($marks, SORT_NUMERIC);

    return $marks;
}




/** Resolve and validate the paper's total marks against both the selected value and course record. */

function qps_course_total_marks(PDO $pdo, array $course): array {

          $allowed = qps_allowed_total_marks($pdo);

          // The Teaching selector is the source of truth. The course catalog's

          // maxmark is only a fallback for legacy drafts with no selected context.

          $selectedRaw = $course['selected_total_marks'] ?? null;

          $draftTotalRaw = $course['draft_total_marks'] ?? $course['total_marks'] ?? null;

          $draftMaxRaw = $course['draft_max_marks'] ?? $course['max_marks'] ?? null;

          $courseRaw = $course['course_maxmark'] ?? $course['maxmark'] ?? null;

          $selected = is_numeric($selectedRaw) ? (int)$selectedRaw : 0;

          $draftTotal = is_numeric($draftTotalRaw) ? (int)$draftTotalRaw : 0;

          $draftMax = is_numeric($draftMaxRaw) ? (int)$draftMaxRaw : 0;

          $courseMarks = is_numeric($courseRaw) ? (int)$courseRaw : 0;

          $marks = $selected > 0 ? $selected : ($draftTotal > 0 ? $draftTotal : ($draftMax > 0 ? $draftMax : $courseMarks));

          if ($marks < 1) return ['marks'=>0,'error'=>'Total Marks is missing. Select Total Marks in the Teaching course context.'];

          if (!in_array($marks, $allowed, true)) return ['marks'=>0,'error'=>'Total Marks must be one of the configured values: '.implode(', ', $allowed).'.'];

          return ['marks'=>$marks,'error'=>''];

}



/** Build the academic context from the saved staff draft, with course metadata only as a legacy fallback. */

function qps_rule_context_from_course(PDO $pdo, string $paperCode, string $semester, string $academicYear): array {

          $st = $pdo->prepare('SELECT programme_year,programme_level,degree_level,max_marks,total_marks,language,semester,academic_year FROM qps_question_bank_drafts WHERE UPPER(paper_code)=UPPER(?) AND semester=? AND academic_year=? ORDER BY id DESC LIMIT 1');

          $st->execute([$paperCode, $semester, $academicYear]);

          $draft = $st->fetch(PDO::FETCH_ASSOC) ?: [];

          $sc = $pdo->prepare('SELECT programme,level,maxmark,papertype,coursetype1,coursetype FROM courses WHERE UPPER(coursecode)=UPPER(?) LIMIT 1');

          $sc->execute([$paperCode]);

          $course = $sc->fetch(PDO::FETCH_ASSOC) ?: [];



          $programmeYear = trim((string)($draft['programme_year'] ?? ''));

          if ($programmeYear === '') $programmeYear = trim((string)($draft['programme_level'] ?? ''));

          if ($programmeYear === '') $programmeYear = qps_resolve_programme_level([

                    'programme'=>$course['programme'] ?? '', 'level'=>$course['level'] ?? '', 'semester'=>$semester

          ]);

          $ctx = [

                    'paper_code'=>$paperCode,

                    'semester'=>$semester,

                    'academic_year'=>$academicYear,

                    'programme_year'=>$programmeYear,

                    'programme_level'=>$programmeYear,

                    'degree_level'=>$draft['degree_level'] ?? '',

                    'programme'=>$course['programme'] ?? '',

                    'level'=>$course['level'] ?? '',

                    'draft_total_marks'=>$draft['total_marks'] ?? null,

                    'draft_max_marks'=>$draft['max_marks'] ?? null,

                    'total_marks'=>$draft['total_marks'] ?? null,

                    'course_maxmark'=>$course['maxmark'] ?? null,

                    'language'=>strtoupper(trim((string)($draft['language'] ?? 'EN'))),

                    'paper_type_code'=>$course['papertype'] ?? $course['coursetype1'] ?? $course['coursetype'] ?? ''

          ];

          $ctx['programme_year'] = $programmeYear;

          $ctx['programme_level'] = $programmeYear;

          $ctx['total_marks_source'] = ((int)($ctx['draft_total_marks'] ?? 0) > 0 ? 'draft' : 'course');

          return $ctx;

}



function qps_rules_for_course(PDO $pdo, array $course): array {

          $semester=trim((string)($course['semester']??''));$year=trim((string)($course['academic_year']??''));

          $programme=trim((string)($course['programme_year']??$course['programme_level']??''));if($programme==='')$programme=qps_resolve_programme_level($course);$markResult=qps_course_total_marks($pdo,$course);$marks=(int)$markResult['marks'];

          $missing=[];if($semester==='')$missing[]='Semester';if($year==='')$missing[]='Academic Year';if($programme==='')$missing[]='Year of Programme';if($marks<1)$missing[]='Total Marks';

          $base=['found'=>false,'message'=>'','rules'=>[],'count'=>0,'matched_year'=>null,'matched_semester'=>null,'total_marks'=>$marks];

          if($markResult['error']!=='' && !str_contains(strtolower($markResult['error']),'is missing')){$base['message']=$markResult['error'];return $base;}

          if($missing){$base['message']='Missing required rule context: '.implode(', ',$missing).'.';return $base;}

          if(!in_array($programme,qps_programme_levels(),true)){$base['message']='Year of Programme is invalid.';return $base;}

          $st=$pdo->prepare("SELECT question_number,k_level,co_level,academic_year,semester,programme_year,programme_level,total_marks FROM question_rules WHERE semester IN (?, '*') AND COALESCE(NULLIF(programme_year,''),programme_level)=? AND total_marks=? AND academic_year<=? ORDER BY question_number ASC");

          $st->execute([$semester,$programme,$marks,$year]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);

          $groups=[];foreach($rows as $r){$key=$r['semester'].'|'.$r['academic_year'];$groups[$key][]=$r;}

          $chosen=null;foreach($groups as $group){$r=$group[0];$rank=[($r['semester']===$semester?1:0),($r['academic_year']===$year?1:0),qps_year_start((string)$r['academic_year'])];if($chosen===null||$rank>$chosen['rank'])$chosen=['rank'=>$rank,'rows'=>$group,'year'=>$r['academic_year'],'semester'=>$r['semester']];}

          if(!$chosen){$base['message']="No Dean question rules found for {$semester}, {$year}, {$programme}, {$marks} marks.";return $base;}

          $map=[];foreach($chosen['rows'] as $r)$map[(int)$r['question_number']]=['k_level'=>strtoupper((string)$r['k_level']),'co_level'=>strtoupper((string)$r['co_level'])];

          ksort($map,SORT_NUMERIC);$base['found']=count($map)>0;$base['rules']=$map;$base['count']=count($map);$base['matched_year']=$chosen['year'];$base['matched_semester']=$chosen['semester'];

          if(!$base['found'])$base['message']='The matching Dean rule set is empty.';return $base;

}



/** @return array{errors:array,warnings:array,slots:array,rules:array,rule_count:int,total_marks:int} */

function qps_validate_slots(PDO $pdo,array $course,array $slots,string $mode='draft'): array {

          $errors=[];$warnings=[];$normal=[];$seen=[];$seenKeys=[];$resolved=qps_rules_for_course($pdo,$course);

          if(!$resolved['found'])return ['errors'=>[$resolved['message']?:'No matching Dean question rules exist.'],'warnings'=>[],'slots'=>$slots,'rules'=>[],'rule_count'=>0,'total_marks'=>(int)($resolved['total_marks']??0)];

          $ruleNums=array_keys($resolved['rules']);sort($ruleNums,SORT_NUMERIC);$isSubmit=strtolower($mode)==='submit';

          foreach($slots as $index=>$slot){if(!is_array($slot))continue;

               $number=(int)($slot['slot_number']??$slot['question_number']??($index+1));$key=(string)($slot['slot_key']??('Q'.$number));

               $option=strtolower(trim((string)($slot['option_label']??'')));$group=trim((string)($slot['choice_group']??''));$section=strtoupper(trim((string)($slot['section_type']??$slot['section']??'')));$marks=(int)($slot['marks']??0);

               if($number<1){$errors[]="{$key}: invalid question number.";continue;}

               if($key===''||isset($seenKeys[$key])){$errors[]="{$key}: duplicate or missing slot key.";continue;}$seenKeys[$key]=true;

               if(isset($seen[$number])){$errors[]="Duplicate question number Q{$number}.";continue;}else{$seen[$number]=true;}

               if(!isset($resolved['rules'][$number])){$errors[]="Q{$number}: no K-Level / CO-Level rule exists for this course.";continue;}

               $rule=$resolved['rules'][$number];$k=strtoupper(trim((string)($slot['k_level']??'')));$co=strtoupper(trim((string)($slot['co_level']??'')));

               if($k!==''&&$k!==$rule['k_level'])$errors[]="Q{$number}: submitted K-Level {$k} does not match Dean rule {$rule['k_level']}.";

               if($co!==''&&$co!==$rule['co_level'])$errors[]="Q{$number}: submitted CO-Level {$co} does not match Dean rule {$rule['co_level']}.";

               $slot['slot_number']=$number;$slot['k_level']=$rule['k_level'];$slot['co_level']=$rule['co_level'];$slot['slot_key']=$key;$slot['unit_no']=(int)($slot['unit_no']??$slot['unit']??0);$slot['sub_unit']=trim((string)($slot['sub_unit']??$slot['subunit']??''));$slot['question_type']=strtoupper(trim((string)($slot['question_type']??$slot['type']??'')));$slot['marks']=$marks;

               if($slot['unit_no']<1)$errors[]="Q{$number}: select a valid unit.";if($slot['sub_unit']==='')$errors[]="Q{$number}: select a sub-unit.";if($slot['question_type']==='')$errors[]="Q{$number}: select a question type.";if($marks<1)$errors[]="Q{$number}: marks must be greater than zero.";$normal[]=$slot;

          }

          if($isSubmit){

               if(count($normal)!==$resolved['count'])$errors[]='Final blueprint has '.count($normal).' slots; Dean rules for '.$resolved['total_marks'].' marks define '.$resolved['count'].'.';

               foreach($ruleNums as $n)if(!isset($seen[$n]))$errors[]="Dean rule Q{$n} has no final blueprint slot.";

               $sum=array_sum(array_map(static fn($s)=>(int)($s['marks']??0),$normal));if($sum!==(int)$resolved['total_marks'])$errors[]='Final blueprint total is '.$sum.' marks; expected '.$resolved['total_marks'].'.';

               $draftId=(int)($course['bank_draft_id']??$course['draft_id']??0);

               if($draftId>0){$programmeYear=trim((string)($course['programme_year']??$course['programme_level']??''));$language=strtoupper(trim((string)($course['language']??'EN')));$st=$pdo->prepare("SELECT unit_no,sub_unit,question_type,marks,k_level,co_level FROM qps_question_bank_draft_questions WHERE draft_id=? AND semester=? AND academic_year=? AND programme_year=? AND total_marks=? AND UPPER(language)=UPPER(?) AND (correction_status IS NULL OR correction_status NOT IN ('NEEDS_CORRECTION','REMARKED'))");$st->execute([$draftId,(string)($course['semester']??''),(string)($course['academic_year']??''),$programmeYear,(int)$resolved['total_marks'],$language]);$bank=$st->fetchAll(PDO::FETCH_ASSOC);foreach($normal as $slot){$match=false;foreach($bank as $q){if((int)$q['unit_no']===(int)$slot['unit_no']&&(string)$q['sub_unit']===(string)$slot['sub_unit']&&strtoupper((string)$q['question_type'])===$slot['question_type']&&(int)$q['marks']===(int)$slot['marks']&&strtoupper((string)$q['k_level'])===$slot['k_level']&&strtoupper((string)$q['co_level'])===$slot['co_level']){$match=true;break;}}if(!$match)$errors[]="Q{$slot['slot_number']}: no clean bank question matches the slot requirements.";}}

               else $warnings[]='Bank-question matching was not run because bank_draft_id was not supplied.';

          }else{

               if(count($normal)!==$resolved['count'])$errors[]='Dean template has '.count($normal).' slots; matching question_rules define '.$resolved['count'].' question numbers.';
               foreach($ruleNums as $n)if(!isset($seen[$n]))$errors[]="Dean rule Q{$n} has no matching template slot.";

               $sum=array_sum(array_map(static fn($s)=>(int)($s['marks']??0),$normal));if($sum!==(int)$resolved['total_marks'])$errors[]='Dean template slot marks total '.$sum.'; expected '.$resolved['total_marks'].'.';

          }

          return ['errors'=>array_values(array_unique($errors)),'warnings'=>$warnings,'slots'=>$normal,'rules'=>$resolved['rules'],'rule_count'=>$resolved['count'],'total_marks'=>$resolved['total_marks']];

}
