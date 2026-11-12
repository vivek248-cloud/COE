<?php
/**
 * Dean Question Paper Templates API - compatible with the existing hccweb schema.
 * Existing column mapping:
 * templates.name, sections.section_title, slots.co.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

requireDean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function qpt_reply(bool $success, string $message = '', array $data = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function qpt_log_error(Throwable $e, string $where): void {
    error_log('[Dean templates][' . $where . '] ' . $e->getMessage());
}
function qpt_request(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    return is_array($json) ? array_merge($_POST, $json) : $_POST;
}
function qpt_staff_code(): string {
    $code = trim((string)(getCurrentUser()['staff_code'] ?? ''));
    if ($code === '') {
        qpt_reply(false, 'Unable to identify the logged-in Dean.', [], 403);
    }
    return $code;
}
function qpt_get_owned(PDO $pdo, int $id, string $staffCode): ?array {
    $st = $pdo->prepare('SELECT * FROM question_paper_templates WHERE id = ? AND created_by = ? AND is_active = 1 LIMIT 1');
    $st->execute([$id, $staffCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function qpt_class_sections($value): array {
    if (is_array($value)) return array_values($value);
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? array_values($decoded) :
        array_values(array_filter(array_map('trim', explode(',', $value)), static fn($v) => $v !== ''));
}
function qpt_has_assignments(PDO $pdo, int $templateId): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM question_paper_template_slots s
         INNER JOIN question_paper_template_sections sec ON sec.id = s.section_id
         WHERE sec.template_id = ? AND (s.unit_id IS NOT NULL OR s.question_id IS NOT NULL)'
    );
    $st->execute([$templateId]);
    return (int)$st->fetchColumn() > 0;
}
function qpt_list(PDO $pdo, string $staffCode): never {
    $st = $pdo->prepare(
        'SELECT t.id, t.name AS template_name, t.total_marks, t.academic_year,
                t.year_of_programme, t.semester, t.class_sections, t.status,
                t.created_at, t.updated_at,
                COUNT(DISTINCT sec.id) AS section_count,
                COUNT(DISTINCT s.id) AS slot_count
         FROM question_paper_templates t
         LEFT JOIN question_paper_template_sections sec
           ON sec.template_id = t.id AND sec.is_active = 1
         LEFT JOIN question_paper_template_slots s
           ON s.section_id = sec.id AND s.is_active = 1
         WHERE t.created_by = ? AND t.is_active = 1
         GROUP BY t.id
         ORDER BY t.updated_at DESC, t.id DESC'
    );
    $st->execute([$staffCode]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['class_sections'] = qpt_class_sections($row['class_sections']);
        $row['total_marks'] = (int)$row['total_marks'];
        $row['semester'] = (int)$row['semester'];
    }
    unset($row);
    qpt_reply(true, 'Templates loaded.', $rows);
}
function qpt_get(PDO $pdo, string $staffCode, array $input): never {
    $id = (int)($input['template_id'] ?? $input['id'] ?? 0);
    $template = $id > 0 ? qpt_get_owned($pdo, $id, $staffCode) : null;
    if (!$template) qpt_reply(false, 'Template not found.', [], 404);

    $st = $pdo->prepare(
        'SELECT id, template_id, section_type, section_title AS section_name, display_order AS sort_order
         FROM question_paper_template_sections
         WHERE template_id = ? AND is_active = 1 ORDER BY display_order, id'
    );
    $st->execute([$id]);
    $sections = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare(
        'SELECT s.id, s.section_id, s.slot_label, s.marks, s.k_level,
                s.co AS co_level, s.display_order AS sort_order, s.unit_id, s.question_id
         FROM question_paper_template_slots s
         INNER JOIN question_paper_template_sections sec ON sec.id = s.section_id
         WHERE sec.template_id = ? AND sec.is_active = 1 AND s.is_active = 1
         ORDER BY sec.display_order, s.display_order, s.id'
    );
    $st->execute([$id]);
    $slots = $st->fetchAll(PDO::FETCH_ASSOC);

    $template['template_name'] = $template['name'];
    $template['class_sections'] = qpt_class_sections($template['class_sections']);
    $template['total_marks'] = (int)$template['total_marks'];
    $template['semester'] = (int)$template['semester'];
    $template['sections'] = $sections;
    $template['slots'] = $slots;
    qpt_reply(true, 'Template loaded.', $template);
}
function qpt_validate(array $in): array {
    $name = trim((string)($in['template_name'] ?? $in['name'] ?? ''));
    $marks = filter_var($in['total_marks'] ?? null, FILTER_VALIDATE_INT);
    $year = trim((string)($in['academic_year'] ?? ''));
    $programme = trim((string)($in['year_of_programme'] ?? ''));
    $semester = filter_var($in['semester'] ?? null, FILTER_VALIDATE_INT);
    $classSections = $in['class_sections'] ?? [];
    $sectionsIn = $in['sections'] ?? [];
    $slotsIn = $in['slots'] ?? [];

    if ($name === '') qpt_reply(false, 'Template name is required.', [], 422);
    if ($marks === false || $marks < 1 || $marks > 200) qpt_reply(false, 'Total marks must be an integer from 1 to 200.', [], 422);
    if (!preg_match('/^\d{4}-\d{4}$/', $year)) qpt_reply(false, 'Enter academic year in YYYY-YYYY format.', [], 422);
    if (!in_array($programme, ['I','II','III','IV'], true)) qpt_reply(false, 'Select a valid programme year.', [], 422);
    if ($semester === false || $semester < 1 || $semester > 8) qpt_reply(false, 'Select a semester from 1 to 8.', [], 422);
    if (!is_array($classSections) || !$classSections) qpt_reply(false, 'Select at least one class section.', [], 422);
    if (!is_array($sectionsIn) || !$sectionsIn) qpt_reply(false, 'Add at least one paper section.', [], 422);
    if (!is_array($slotsIn) || !$slotsIn) qpt_reply(false, 'Add at least one question slot.', [], 422);

    $sections = [];
    foreach ($sectionsIn as $i => $sec) {
        $clientId = (string)($sec['id'] ?? '');
        $label = trim((string)($sec['section_name'] ?? ''));
        if ($clientId === '' || $label === '') qpt_reply(false, 'Every paper section needs a name.', [], 422);
        if (isset($sections[$clientId])) qpt_reply(false, 'Duplicate section identifier.', [], 422);
        $sections[$clientId] = ['name' => $label, 'order' => (int)($sec['sort_order'] ?? $i + 1)];
    }

    $slots = [];
    $sum = 0;
    foreach ($slotsIn as $i => $slot) {
        $label = trim((string)($slot['slot_label'] ?? ''));
        $slotMarks = filter_var($slot['marks'] ?? null, FILTER_VALIDATE_INT);
        $k = trim((string)($slot['k_level'] ?? ''));
        $co = trim((string)($slot['co_level'] ?? ''));
        $sectionId = (string)($slot['section_id'] ?? '');
        if ($label === '') qpt_reply(false, 'Every slot needs a question label.', [], 422);
        if ($slotMarks === false || $slotMarks < 1 || $slotMarks > 200) qpt_reply(false, 'Slot marks must be a whole number from 1 to 200.', [], 422);
        if (!preg_match('/^K[1-6]$/', $k)) qpt_reply(false, 'Every slot needs a valid K-Level.', [], 422);
        if (!preg_match('/^CO[1-6]$/', $co)) qpt_reply(false, 'Every slot needs a valid CO-Level.', [], 422);
        if (!isset($sections[$sectionId])) qpt_reply(false, 'A slot references an unknown section.', [], 422);
        $sum += $slotMarks;
        $slots[] = ['label'=>$label, 'marks'=>$slotMarks, 'k'=>$k, 'co'=>$co,
            'section_client_id'=>$sectionId, 'order'=>(int)($slot['sort_order'] ?? $i + 1)];
    }
    if ($sum !== $marks) {
        $diff = abs($marks - $sum);
        qpt_reply(false, $sum > $marks ? "Allocated marks exceed total marks by {$diff}." : "Allocated marks are short by {$diff}.",
            ['allocated_marks'=>$sum, 'total_marks'=>$marks, 'difference'=>$diff], 422);
    }
    $classSections = array_values(array_unique(array_filter(
        array_map(static fn($v) => trim((string)$v), $classSections),
        static fn($v) => $v !== ''
    )));
    return compact('name','marks','year','programme','semester','classSections','sections','slots');
}
function qpt_save(PDO $pdo, string $staffCode, array $in): never {
    $p = qpt_validate($in);
    $id = (int)($in['template_id'] ?? $in['id'] ?? 0);
    $id = $id > 0 ? $id : null;
    if ($id !== null) {
        if (!qpt_get_owned($pdo, $id, $staffCode)) qpt_reply(false, 'Template not found or access denied.', [], 404);
        if (qpt_has_assignments($pdo, $id)) qpt_reply(false, 'This template already has staff assignments and its structure cannot be changed.', [], 409);
    }

    $sql = 'SELECT id FROM question_paper_templates WHERE created_by = ? AND name = ? AND academic_year = ?';
    $params = [$staffCode, $p['name'], $p['year']];
    if ($id !== null) { $sql .= ' AND id <> ?'; $params[] = $id; }
    $st = $pdo->prepare($sql . ' LIMIT 1');
    $st->execute($params);
    if ($st->fetchColumn()) qpt_reply(false, 'A template with this name already exists for this Dean and academic year.', [], 409);

    $pdo->beginTransaction();
    try {
        $classJson = json_encode($p['classSections'], JSON_UNESCAPED_UNICODE);
        if ($id === null) {
            $st = $pdo->prepare(
                "INSERT INTO question_paper_templates
                 (name, paper_type, total_marks, created_by, academic_year, year_of_programme, semester, class_sections, status)
                 VALUES (?, 'end', ?, ?, ?, ?, ?, ?, 'published')"
            );
            $st->execute([$p['name'],$p['marks'],$staffCode,$p['year'],$p['programme'],$p['semester'],$classJson]);
            $templateId = (int)$pdo->lastInsertId();
        } else {
            $st = $pdo->prepare(
                'UPDATE question_paper_templates
                 SET name=?, total_marks=?, academic_year=?, year_of_programme=?, semester=?, class_sections=?, updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND created_by=?'
            );
            $st->execute([$p['name'],$p['marks'],$p['year'],$p['programme'],$p['semester'],$classJson,$id,$staffCode]);
            $templateId = $id;
            // Soft-delete previous rows to retain existing references/history.
            $st = $pdo->prepare(
                'UPDATE question_paper_template_slots s
                 INNER JOIN question_paper_template_sections sec ON sec.id=s.section_id
                 SET s.is_active=0 WHERE sec.template_id=?'
            );
            $st->execute([$templateId]);
            $st = $pdo->prepare('UPDATE question_paper_template_sections SET is_active=0 WHERE template_id=?');
            $st->execute([$templateId]);
        }

        $sectionMap = [];
        $insertSection = $pdo->prepare(
            'INSERT INTO question_paper_template_sections
             (template_id, section_type, section_title, display_order, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $index = 0;
        foreach ($p['sections'] as $clientId => $section) {
            // Legacy schema requires one-character section_type. Preserve a useful A/B/C code.
            $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $section['name']), -1));
            if ($code === '') $code = chr(65 + ($index % 26));
            $insertSection->execute([$templateId,$code,$section['name'],$section['order']]);
            $sectionMap[$clientId] = (int)$pdo->lastInsertId();
            $index++;
        }

        $insertSlot = $pdo->prepare(
            'INSERT INTO question_paper_template_slots
             (section_id, slot_label, marks, co, k_level, display_order, is_active, unit_id, question_id)
             VALUES (?, ?, ?, ?, ?, ?, 1, NULL, NULL)'
        );
        foreach ($p['slots'] as $slot) {
            $insertSlot->execute([
                $sectionMap[$slot['section_client_id']], $slot['label'], $slot['marks'],
                $slot['co'], $slot['k'], $slot['order']
            ]);
        }
        $pdo->commit();

        if (function_exists('qps_audit')) {
            try { qps_audit($pdo,'dean_template_save','template',(string)$templateId,['name'=>$p['name']]); }
            catch (Throwable $auditError) { qpt_log_error($auditError,'audit'); }
        }
        qpt_reply(true, $id === null ? 'Template saved successfully.' : 'Template updated successfully.', ['id'=>$templateId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        qpt_log_error($e, 'save');
        qpt_reply(false, 'Unable to save the template because of a database error. Check the PHP error log.', [], 500);
    }
}
function qpt_delete(PDO $pdo, string $staffCode, array $in): never {
    $id = (int)($in['template_id'] ?? $in['id'] ?? 0);
    if ($id < 1 || !qpt_get_owned($pdo,$id,$staffCode)) qpt_reply(false,'Template not found.',[],404);
    if (qpt_has_assignments($pdo,$id)) qpt_reply(false,'This template has staff assignments and cannot be deleted.',[],409);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'UPDATE question_paper_template_slots s
             INNER JOIN question_paper_template_sections sec ON sec.id=s.section_id
             SET s.is_active=0 WHERE sec.template_id=?'
        );
        $st->execute([$id]);
        $st = $pdo->prepare('UPDATE question_paper_template_sections SET is_active=0 WHERE template_id=?');
        $st->execute([$id]);
        $st = $pdo->prepare('UPDATE question_paper_templates SET is_active=0 WHERE id=? AND created_by=?');
        $st->execute([$id,$staffCode]);
        $pdo->commit();
        qpt_reply(true,'Template deleted successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        qpt_log_error($e,'delete');
        qpt_reply(false,'Unable to delete template. Check the PHP error log.',[],500);
    }
}

try {
    $pdo = getDBConnection();
    $input = qpt_request();
    $sessionToken = (string)($_SESSION['qps_dean_templates_csrf'] ?? '');
    $requestToken = (string)($input['csrf'] ?? '');
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken,$requestToken)) {
        qpt_reply(false,'Invalid CSRF token. Refresh the page and try again.',[],403);
    }
    $staffCode = qpt_staff_code();
    switch ((string)($input['action'] ?? '')) {
        case 'list': qpt_list($pdo,$staffCode);
        case 'get':
        case 'load': qpt_get($pdo,$staffCode,$input);
        case 'save': qpt_save($pdo,$staffCode,$input);
        case 'delete': qpt_delete($pdo,$staffCode,$input);
        default: qpt_reply(false,'Unknown template action.',[],400);
    }
} catch (Throwable $e) {
    // Keep the complete diagnostic in the PHP error log. On localhost, also
    // return the exact exception message so schema/configuration mismatches
    // can be identified without guessing.
    qpt_log_error($e, 'request: ' . $e->getFile() . ':' . $e->getLine());
    $isLocal = in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ['127.0.0.1', '::1'], true)
        || in_array(strtolower((string)($_SERVER['HTTP_HOST'] ?? '')),
        ['localhost', 'localhost:80', 'localhost:8080'], true);

    $message = 'Unable to complete the template operation. Check the PHP error log.';
    if ($isLocal) {
        $message .= ' Details: ' . $e->getMessage()
            . ' ( at ' . basename($e->getFile()) . ':' . $e->getLine() . ' )';
    }
    qpt_reply(false, $message, [], 500);
}
