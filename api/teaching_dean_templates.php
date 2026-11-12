<?php
/** Read-only published Dean templates for authenticated Teaching Staff. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';
require_once __DIR__ . '/../includes/workflow.php';

function qps_td_reply(bool $success, string $message = '', array $data = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(['success'=>$success,'message'=>$message,'data'=>$data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (!isLoggedIn() || qps_workflow_role() !== 'STAFF') qps_td_reply(false, 'Only authenticated Teaching Staff can view published Dean templates.', [], 403);
$csrf = (string)($_SESSION['qps_review_csrf'] ?? '');
$input = array_merge($_GET, $_POST);
$raw = file_get_contents('php://input');
$json = json_decode((string)$raw, true);
if (is_array($json)) $input = array_merge($input, $json);
if ($csrf === '' || !hash_equals($csrf, (string)($input['csrf'] ?? ''))) qps_td_reply(false, 'Security token expired. Refresh the page.', [], 403);
$action = trim((string)($input['action'] ?? 'list'));
try {
    $pdo = getDBConnection();
    if ($action === 'catalog') {
        $st = $pdo->query("SELECT t.id,t.name AS template_name,t.academic_year,UPPER(t.year_of_programme) AS year_of_programme,t.total_marks,t.semester,COUNT(DISTINCT sec.id) AS section_count,COUNT(DISTINCT sl.id) AS slot_count FROM question_paper_templates t LEFT JOIN question_paper_template_sections sec ON sec.template_id=t.id AND sec.is_active=1 LEFT JOIN question_paper_template_slots sl ON sl.section_id=sec.id AND sl.is_active=1 WHERE t.is_active=1 AND LOWER(t.status)='published' GROUP BY t.id ORDER BY t.updated_at DESC,t.id DESC");
        $rows=$st->fetchAll(PDO::FETCH_ASSOC); foreach($rows as &$row){$row['id']=(int)$row['id'];$row['total_marks']=(int)$row['total_marks'];$row['semester']=(int)$row['semester'];$row['slot_count']=(int)$row['slot_count'];} unset($row);
        qps_td_reply(true,'Published Dean templates loaded.',$rows);
    }
    if ($action === 'options') {
        // One authoritative list of available combinations from published, active Dean templates.
        $st = $pdo->query("SELECT DISTINCT academic_year, UPPER(year_of_programme) AS year_of_programme, total_marks, semester FROM question_paper_templates WHERE is_active=1 AND LOWER(status)='published' AND academic_year<>'' AND year_of_programme<>'' AND total_marks>0 ORDER BY academic_year DESC, year_of_programme ASC, total_marks ASC, semester ASC");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['total_marks'] = (int)$row['total_marks'];
            $row['semester'] = (int)$row['semester'];
        }
        unset($row);
        qps_td_reply(true, 'Published Dean template options loaded.', $rows);
    }
    if ($action === 'list') {
        $academicYear = trim((string)($input['academic_year'] ?? ''));
        $programmeYear = strtoupper(trim((string)($input['year_of_programme'] ?? '')));
        $totalMarks = (int)($input['total_marks'] ?? 0);
        $semester = (int)($input['semester'] ?? 0);
        if ($academicYear === '' || !preg_match('/^(I|II|III|IV)$/', $programmeYear) || $totalMarks < 1 || $semester < 1) qps_td_reply(false, 'Academic Year, Semester, Year of Programme and Total Marks are required.', [], 422);
        $st = $pdo->prepare("SELECT t.id, t.name AS template_name, t.academic_year, t.year_of_programme, t.total_marks, t.semester, t.class_sections, COUNT(DISTINCT sec.id) AS section_count, COUNT(DISTINCT sl.id) AS slot_count FROM question_paper_templates t LEFT JOIN question_paper_template_sections sec ON sec.template_id=t.id AND sec.is_active=1 LEFT JOIN question_paper_template_slots sl ON sl.section_id=sec.id AND sl.is_active=1 WHERE t.is_active=1 AND LOWER(t.status)='published' AND t.academic_year=? AND UPPER(t.year_of_programme)=? AND t.total_marks=? AND t.semester=? GROUP BY t.id ORDER BY t.updated_at DESC, t.id DESC");
        $st->execute([$academicYear, $programmeYear, $totalMarks, $semester]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['id']=(int)$row['id']; $row['total_marks']=(int)$row['total_marks']; $row['slot_count']=(int)$row['slot_count']; $row['section_count']=(int)$row['section_count']; }
        unset($row);
        qps_td_reply(true, 'Published Dean templates loaded.', $rows);
    }
    if ($action === 'get') {
        $id = (int)($input['template_id'] ?? $input['id'] ?? 0);
        if ($id < 1) qps_td_reply(false, 'Template ID is required.', [], 422);
        $st = $pdo->prepare("SELECT id, name AS template_name, academic_year, year_of_programme, total_marks, semester, class_sections, updated_at FROM question_paper_templates WHERE id=? AND is_active=1 AND LOWER(status)='published' LIMIT 1");
        $st->execute([$id]);
        $template = $st->fetch(PDO::FETCH_ASSOC);
        if (!$template) qps_td_reply(false, 'Published Dean template not found.', [], 404);
        $st = $pdo->prepare('SELECT id, template_id, section_type, section_title AS section_name, display_order AS sort_order FROM question_paper_template_sections WHERE template_id=? AND is_active=1 ORDER BY display_order,id');
        $st->execute([$id]);
        $sections = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare('SELECT sl.id, sl.section_id, sl.slot_label, sl.marks, sl.k_level, sl.co AS co_level, sl.display_order AS sort_order, sl.unit_id FROM question_paper_template_slots sl INNER JOIN question_paper_template_sections sec ON sec.id=sl.section_id WHERE sec.template_id=? AND sec.is_active=1 AND sl.is_active=1 ORDER BY sec.display_order,sl.display_order,sl.id');
        $st->execute([$id]);
        $slots = $st->fetchAll(PDO::FETCH_ASSOC);
        $template['id']=(int)$template['id']; $template['total_marks']=(int)$template['total_marks'];
        foreach ($sections as &$section) { $section['id']=(int)$section['id']; $section['template_id']=(int)$section['template_id']; }
        unset($section);
        foreach ($slots as &$slot) { $slot['id']=(int)$slot['id']; $slot['section_id']=(int)$slot['section_id']; $slot['marks']=(int)$slot['marks']; $slot['sort_order']=(int)$slot['sort_order']; }
        unset($slot);
        $template['sections']=$sections; $template['slots']=$slots;
        qps_td_reply(true, 'Dean template loaded.', $template);
    }
    qps_td_reply(false, 'Unsupported action.', [], 400);
} catch (Throwable $e) {
    error_log('[Teaching Dean templates] '.$e->getMessage());
    qps_td_reply(false, 'Unable to load Dean templates. Check the server error log.', [], 500);
}
