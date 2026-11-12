<?php
/** Central, transaction-safe Staff -> HOD -> Dean -> COE workflow. */
declare(strict_types=1);
require_once __DIR__.'/question_rules.php';

function qps_workflow_role(): string {
    if (!empty($_SESSION['qps_static_role'])) return strtoupper((string)$_SESSION['qps_static_role']);
    if (!empty($_SESSION['qps_dean_authenticated']) && function_exists('isDean') && isDean()) return 'DEAN';
    $r=strtoupper((string)($_SESSION['user']['role']??''));
    if (in_array($r,['COE_ADMIN','COE_STAFF','COE'],true)) return 'COE';
    $portal=strtoupper((string)($_SESSION['qps_portal_role']??''));
    if($portal==='STAFF' && !empty($_SESSION['user'])) return 'STAFF';
    if($portal==='HOD' && ($r==='HOD' || !empty($_SESSION['user']['is_hod'])) && empty($_SESSION['user']['is_erp_staff'])) return 'HOD';
    if ($r==='HOD' || (!empty($_SESSION['user']['is_hod']) && empty($_SESSION['user']['is_erp_staff']))) return 'HOD';
    if (in_array($r,['TEACHING_STAFF','TEACHER','FACULTY'],true)) return 'STAFF';
    return 'GUEST';
}
function qps_workflow_actor(): array {
    $u=$_SESSION['user']??[];
    return ['code'=>(string)($u['staff_code']??$_SESSION['qps_static_username']??'SYSTEM'),
        'name'=>(string)($u['name']??$_SESSION['qps_static_name']??$u['staff_code']??'System'),
        'role'=>qps_workflow_role()];
}
function qps_workflow_ip(): string {
    // Do not trust forwarded headers unless deployment explicitly configures trusted proxies.
    return substr((string)($_SERVER['REMOTE_ADDR']??'127.0.0.1'),0,45);
}
function qps_ensure_workflow_schema(PDO $pdo): void {
    if (function_exists('qps_add_column_if_missing')) {
        qps_add_column_if_missing($pdo,'questions','is_used','TINYINT(1) NOT NULL DEFAULT 0');
        qps_add_column_if_missing($pdo,'questions','times_used','INT UNSIGNED NOT NULL DEFAULT 0');
        qps_add_column_if_missing($pdo,'questions','last_used_exam','VARCHAR(120) NULL');
        qps_add_column_if_missing($pdo,'questions','last_used_semester','VARCHAR(50) NULL');
        qps_add_column_if_missing($pdo,'questions','last_used_year','VARCHAR(20) NULL');
        // Legacy generated_papers readers/writers expect these aliases; preserve all existing columns.
        qps_add_column_if_missing($pdo,'generated_papers','set_name','VARCHAR(20) NULL');
        qps_add_column_if_missing($pdo,'generated_papers','paper_data_json','LONGTEXT NULL');
    }
}

function qps_previous_academic_year(string $academicYear): string {
    if(!preg_match('/(19|20)\d{2}/',$academicYear,$m)) return '';
    $start=(int)$m[0];$short=(bool)preg_match('/^\s*\d{4}\s*-\s*\d{2}\s*$/',$academicYear);
    return $short?sprintf('%04d-%02d',$start-1,($start)%100):sprintf('%04d-%04d',$start-1,$start);
}
function qps_refresh_question_is_used(PDO $pdo,string $paperCode,string $semester,string $academicYear): void {
    $previous=qps_previous_academic_year($academicYear);
    if($previous==='') return;
    $sql="UPDATE questions q
      SET q.is_used=CASE WHEN EXISTS(
        SELECT 1 FROM question_usage_history h
        WHERE h.question_id=q.id AND UPPER(h.paper_code)=UPPER(?)
          AND h.academic_year=? AND TRIM(COALESCE(h.exam_session,''))=TRIM(?)
      ) THEN 1 ELSE 0 END
      WHERE UPPER(TRIM(q.paper_code))=UPPER(TRIM(?))";
    $st=$pdo->prepare($sql);$st->execute([$paperCode,$previous,$semester,$paperCode]);
}

function qps_workflow_require_role(array $allowed): void {
    $role=qps_workflow_role();
    if (!in_array($role,$allowed,true)) {
        http_response_code(403);
        throw new RuntimeException('Access denied for role '.$role.'.');
    }
}
function qps_package_by_id(PDO $pdo,int $id,bool $lock=false): ?array {
    $st=$pdo->prepare('SELECT * FROM qps_submission_packages WHERE id=?'.($lock?' FOR UPDATE':''));
    $st->execute([$id]);$row=$st->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}
function qps_create_or_update_package(PDO $pdo,array $data): int {
    $required=['paper_code','semester','academic_year','exam_type'];
    foreach($required as $k) if(trim((string)($data[$k]??''))==='') throw new RuntimeException("Missing package field: {$k}.");
    $actor=qps_workflow_actor();
    $sql="INSERT INTO qps_submission_packages
      (paper_code,semester,academic_year,exam_type,dept_code,bank_draft_id,bank_id,qbb_id,final_blueprint_id,status,current_owner_role,submitted_by,staff_code)
      VALUES (?,?,?,?,?,?,?,?,?,'DRAFT','STAFF',?,?)
      ON DUPLICATE KEY UPDATE dept_code=VALUES(dept_code),bank_draft_id=VALUES(bank_draft_id),bank_id=VALUES(bank_id),
      qbb_id=VALUES(qbb_id),final_blueprint_id=VALUES(final_blueprint_id),updated_at=CURRENT_TIMESTAMP";
    $st=$pdo->prepare($sql);
    $st->execute([$data['paper_code'],$data['semester'],$data['academic_year'],$data['exam_type'],$data['dept_code']??null,
        $data['bank_draft_id']??null,$data['bank_id']??null,$data['qbb_id']??null,$data['final_blueprint_id']??null,
        $actor['code'],$actor['code']]);
    $id=(int)$pdo->lastInsertId();
    if($id===0){
        $st=$pdo->prepare('SELECT id FROM qps_submission_packages WHERE paper_code=? AND semester=? AND academic_year=? AND exam_type=?');
        $st->execute([$data['paper_code'],$data['semester'],$data['academic_year'],$data['exam_type']]);$id=(int)$st->fetchColumn();
    }
    if($id<=0) throw new RuntimeException('Unable to create submission package.');
    return $id;
}
function qps_transition(PDO $pdo,int $packageId,string $action,string $note=''): array {
    $actor=qps_workflow_actor();
    $map=[
      'STAFF_SUBMIT'=>['STAFF',['DRAFT','STAFF_CORRECTION'],'SUBMITTED_TO_HOD','HOD'],
      'HOD_RETURN'=>['HOD',['SUBMITTED_TO_HOD','HOD_RECHECK'],'HOD_REMARKS','STAFF'],
      'HOD_FORWARD'=>['HOD',['SUBMITTED_TO_HOD','HOD_RECHECK'],'SUBMITTED_TO_DEAN','DEAN'],
      'STAFF_RESUBMIT'=>['STAFF',['HOD_REMARKS','STAFF_CORRECTION'],'SUBMITTED_TO_HOD','HOD'],
      'DEAN_RETURN'=>['DEAN',['SUBMITTED_TO_DEAN'],'DEAN_REMARKS','HOD'],
      'HOD_FORWARD_DEAN_REMARKS'=>['HOD',['DEAN_REMARKS'],'STAFF_CORRECTION','STAFF'],
      'STAFF_RESUBMIT_DEAN'=>['STAFF',['STAFF_CORRECTION'],'HOD_RECHECK','HOD'],
      'HOD_REVERIFY'=>['HOD',['HOD_RECHECK'],'SUBMITTED_TO_DEAN','DEAN'],
      'DEAN_APPROVE'=>['DEAN',['SUBMITTED_TO_DEAN'],'SUBMITTED_TO_COE','COE'],
      'COE_APPROVE'=>['COE',['SUBMITTED_TO_COE'],'COE_APPROVED','COE'],
      'COE_ALLOT'=>['COE',['COE_APPROVED'],'ALLOTTED','COE'],
      'COE_GENERATE'=>['COE',['ALLOTTED'],'PAPER_GENERATED','COE']
    ];
    if(!isset($map[$action])) throw new RuntimeException('Unknown workflow action.');
    [$requiredRole,$fromStates,$to,$owner]=$map[$action];
    if($actor['role']!==$requiredRole) throw new RuntimeException('Only '.$requiredRole.' can perform this workflow action.');
    $pdo->beginTransaction();
    try {
        $pkg=qps_package_by_id($pdo,$packageId,true);
        if(!$pkg) throw new RuntimeException('Submission package not found.');
        if (strtoupper((string)($pkg['current_owner_role'] ?? '')) !== $actor['role']) throw new RuntimeException('This package is currently owned by another role.');
        if(!in_array($pkg['status'],$fromStates,true)) throw new RuntimeException('Invalid workflow transition from '.$pkg['status'].'. Refresh and try again.');
        if(in_array($action,['HOD_FORWARD','HOD_REVERIFY','DEAN_APPROVE'],true)){
            qps_assert_no_open_corrections($pdo, $packageId);
        }
        if(in_array($action,['HOD_RETURN','DEAN_RETURN'],true)){
            $st=$pdo->prepare("SELECT COUNT(*) FROM qps_remarks WHERE package_id=? AND status='OPEN' AND author_role=?");
            $st->execute([$packageId,$actor['role']]);
            if((int)$st->fetchColumn()<1) throw new RuntimeException('Add at least one review remark before returning the course.');
        }
        if($action==='HOD_FORWARD_DEAN_REMARKS'){
            $st=$pdo->prepare("SELECT COUNT(*) FROM qps_remarks WHERE package_id=? AND status='OPEN' AND author_role='DEAN' AND to_role='HOD'");
            $st->execute([$packageId]);
            if((int)$st->fetchColumn()<1) throw new RuntimeException('There are no open Dean remarks to forward.');
        }
        if($action==='STAFF_SUBMIT' || $action==='STAFF_RESUBMIT' || $action==='STAFF_RESUBMIT_DEAN'){
            if(empty($pkg['bank_draft_id']) && empty($pkg['bank_id'])) throw new RuntimeException('Question bank is missing.');
            if(empty($pkg['qbb_id'])) throw new RuntimeException('Question bank blueprint is missing.');
            if(empty($pkg['final_blueprint_id'])) throw new RuntimeException('Final question paper blueprint is missing.');
        }
        $round=(int)$pkg['round_no'] + (in_array($action,['STAFF_RESUBMIT','STAFF_RESUBMIT_DEAN'],true)?1:0);
        $st=$pdo->prepare('UPDATE qps_submission_packages SET status=?,current_owner_role=?,round_no=?,updated_at=NOW() WHERE id=?');
        $st->execute([$to,$owner,$round,$packageId]);
        $st=$pdo->prepare('INSERT INTO qps_workflow_events(package_id,from_status,to_status,actor_code,actor_name,actor_role,ip_address,note) VALUES(?,?,?,?,?,?,?,?)');
        $st->execute([$packageId,$pkg['status'],$to,$actor['code'],$actor['name'],$actor['role'],qps_workflow_ip(),$note]);
        if($action==='HOD_FORWARD_DEAN_REMARKS'){
            $pdo->prepare("UPDATE qps_remarks SET to_role='STAFF' WHERE package_id=? AND status='OPEN' AND to_role='HOD' AND author_role='DEAN'")->execute([$packageId]);
        }
        if(function_exists('qps_audit_transition')) qps_audit_transition($pdo,'WORKFLOW_'.$action,'SUBMISSION_PACKAGE',(string)$packageId,(string)$pkg['status'],$to,['note'=>$note]);
        if($action==='COE_APPROVE' && !empty($pkg['bank_draft_id'])){
            // Preserve records for audit; mark approved only after COE approval.
            $pdo->prepare("UPDATE qps_question_bank_drafts SET status='APPROVED',coe_reviewed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$pkg['bank_draft_id']]);
        }
        $pdo->commit();
        return ['package_id'=>$packageId,'from'=>$pkg['status'],'status'=>$to,'owner'=>$owner,'round_no'=>$round];
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}


/** Shared correction counter used by all review portals. */
function qps_open_correction_count(PDO $pdo, int $packageId, ?string $targetType = null): int {
    $sql = "SELECT COUNT(*) FROM qps_remarks WHERE package_id=? AND status='OPEN'";
    $params = [$packageId];
    if ($targetType !== null && $targetType !== '') {
        $sql .= " AND target_type=?";
        $params[] = strtoupper($targetType);
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/** Shared guard for all forward/approval actions. */
function qps_assert_no_open_corrections(PDO $pdo, int $packageId): void {
    $count = qps_open_correction_count($pdo, $packageId);
    if ($count > 0) throw new RuntimeException("There are {$count} open correction(s). Resolve them before continuing.");
}

/** Structured status transition audit, retaining the existing qps_audit_log contract. */
function qps_audit_transition(PDO $pdo, string $action, string $entityType, string $entityId, ?string $from, string $to, array $details = []): void {
    $actor = qps_workflow_actor();
    $json = $details;
    $json['from_status'] = $from;
    $json['to_status'] = $to;
    $json['actor_code'] = $actor['code'];
    $json['actor_role'] = $actor['role'];
    $json['ip_address'] = qps_workflow_ip();
    $st = $pdo->prepare(
        "INSERT INTO qps_audit_log (staff_code,staff_name,role,action,entity_type,entity_id,details,ip_address,from_status,to_status)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    $st->execute([
        $actor['code'], $actor['name'], $actor['role'], $action, $entityType, $entityId,
        json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        qps_workflow_ip(), $from, $to
    ]);
}
