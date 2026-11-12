<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system.php';

requireTeachingStaff();
$pdo = getDBConnection();
$user = getCurrentUser();
$staffCode = trim((string)($user['staff_code'] ?? ''));
$courseCode = strtoupper(trim((string)($_GET['course_code'] ?? $_GET['paper_code'] ?? '')));

if ($courseCode === '') {
    http_response_code(400);
    exit('Course code is required.');
}
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('The PHP ZipArchive extension is required to generate DOCX files.');
}

try {
    // Authorization is checked independently in the download endpoint.
    $access = $pdo->prepare(
        'SELECT 1 FROM timetablefaculty WHERE UPPER(fid)=UPPER(?) AND UPPER(papercode)=UPPER(?) LIMIT 1'
    );
    $access->execute([$staffCode, $courseCode]);
    if (!$access->fetchColumn()) {
        http_response_code(403);
        exit('You are not allocated to this course.');
    }

    $courseStmt = $pdo->prepare(
        'SELECT coursecode, coursetitle, dept_code, qpattern, type FROM courses WHERE UPPER(coursecode)=UPPER(?) LIMIT 1'
    );
    $courseStmt->execute([$courseCode]);
    $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) {
        http_response_code(404);
        exit('Course not found.');
    }

    // Match the same active academic context used by the Page 3 screen.
    $academicYear = qps_setting($pdo, 'default_academic_year', DEFAULT_ACADEMIC_YEAR);
    $regulation = qps_setting($pdo, 'default_regulation', DEFAULT_REGULATION);
    $paperType = hcc_paper_types()[$course['qpattern']] ?? (string)$course['qpattern'];
    $examType = hcc_exam_types()[$course['type']] ?? 'Odd Semester End Examination';

    // Live data: no blueprint snapshot is read or stored.
    $questionStmt = $pdo->prepare("
        SELECT unit_number, sub_unit_number, marks, blooms_level, co_code,
               question_type, question_text, updated_at
        FROM questions_draft
        WHERE staff_id = ? AND UPPER(course_code) = UPPER(?) AND is_active = 1
          AND regulation = ? AND academic_year = ? AND exam_type = ? AND paper_type = ?
        ORDER BY unit_number, sub_unit_number, marks, blooms_level, co_code, id
    ");
    $questionStmt->execute([$staffCode, $courseCode, $regulation, $academicYear, $examType, $paperType]);
    $questions = $questionStmt->fetchAll(PDO::FETCH_ASSOC);

    $matrix = [];
    $sectionCounts = [];
    $totalMarks = 0;
    foreach ($questions as $q) {
        $unit = (string)($q['unit_number'] ?? '');
        $sub = trim((string)($q['sub_unit_number'] ?? ''));
        $marks = (int)($q['marks'] ?? 0);
        $k = strtoupper(trim((string)($q['blooms_level'] ?? '')));
        $co = strtoupper(trim((string)($q['co_code'] ?? '')));
        $type = strtoupper(trim((string)($q['question_type'] ?? 'Other')));
        $key = implode('|', [$unit, $sub, $marks, $k, $co]);
        if (!isset($matrix[$key])) {
            $matrix[$key] = ['unit'=>$unit, 'sub'=>$sub, 'marks'=>$marks, 'k'=>$k, 'co'=>$co, 'count'=>0];
        }
        $matrix[$key]['count']++;
        $totalMarks += $marks;
        $section = $marks === 1 ? '1 Mark' : ($marks === 5 ? '5 Marks' : ($marks === 10 ? '10 Marks' : ($marks > 10 ? 'Above 10 Marks' : 'Other')));
        $sectionCounts[$section] = ($sectionCounts[$section] ?? 0) + 1;
    }
    $matrixRows = array_values($matrix);
    usort($matrixRows, static fn($a,$b) => [$a['unit'],$a['sub'],$a['marks'],$a['k'],$a['co']] <=> [$b['unit'],$b['sub'],$b['marks'],$b['k'],$b['co']]);

    $xml = static fn($v): string => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $run = static function(string $text, bool $bold = false) use ($xml): string {
        return '<w:r>'.($bold ? '<w:rPr><w:b/></w:rPr>' : '').'<w:t xml:space="preserve">'.$xml($text).'</w:t></w:r>';
    };
    $para = static function(string $text, bool $bold = false) use ($run): string {
        return '<w:p>'.$run($text, $bold).'</w:p>';
    };
    $table = static function(array $rows) use ($run): string {
        $out = '<w:tbl><w:tblPr><w:tblBorders>'.
            '<w:top w:val="single" w:sz="6"/><w:left w:val="single" w:sz="6"/>'.
            '<w:bottom w:val="single" w:sz="6"/><w:right w:val="single" w:sz="6"/>'.
            '<w:insideH w:val="single" w:sz="4"/><w:insideV w:val="single" w:sz="4"/>'.
            '</w:tblBorders></w:tblPr>';
        foreach ($rows as $ri => $row) {
            $out .= '<w:tr>';
            foreach ($row as $cell) {
                $out .= '<w:tc><w:tcPr><w:tcW w:w="1600" w:type="dxa"/></w:tcPr><w:p>'.
                    $run((string)$cell, $ri === 0).'</w:p></w:tc>';
            }
            $out .= '</w:tr>';
        }
        return $out.'</w:tbl>';
    };

    $body = $para('HOLY CROSS COLLEGE (AUTONOMOUS)', true);
    $body .= $para('MASTER BLUEPRINT — LIVE COMPILED QUESTION BANK', true);
    $body .= $para((string)$course['coursecode'].' — '.(string)$course['coursetitle'], true);
    $body .= $para('Department: '.(string)($course['dept_code'] ?? '—'));
    $body .= $para('Academic Year: '.$academicYear.' | Regulation: '.$regulation.' | Exam: '.$examType.' | Paper Type: '.$paperType);
    $body .= $para('Generated: '.date('d M Y, h:i A'));
    $body .= $para('Total Questions: '.count($questions).' | Total Question Marks: '.$totalMarks);
    $body .= $para('Blueprint Matrix', true);

    $rows = [['Unit','Sub Unit','Marks','K-Level','CO-Level','Question Count']];
    foreach ($matrixRows as $r) {
        $rows[] = [$r['unit'], $r['sub'], (string)$r['marks'], $r['k'], $r['co'], (string)$r['count']];
    }
    $rows[] = ['','','','','Grand Total',(string)count($questions)];
    $body .= $table($rows);
    $body .= $para('Per-Section Question Count', true);
    $sectionRows = [['Section / Marks','Question Count']];
    foreach ($sectionCounts as $section => $count) {
        $sectionRows[] = [$section, (string)$count];
    }
    $sectionRows[] = ['Grand Total', (string)count($questions)];
    $body .= $table($sectionRows);
    if (!$questions) {
        $body .= $para('No active draft questions are available for this course.');
    }

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'.
        '<w:body>'.$body.
        '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="900" w:right="900" w:bottom="900" w:left="900"/></w:sectPr>'.
        '</w:body></w:document>';
    $contentTypes = '<?xml version="1.0" encoding="UTF-8"?>'.
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
        '<Default Extension="xml" ContentType="application/xml"/>'.
        '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'.
        '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8"?>'.
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'.
        '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'qps_docx_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create DOCX archive.');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('word/document.xml', $document);
    $zip->close();

    $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $courseCode).'-Master-Blueprint.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.(string)filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
} catch (Throwable $e) {
    error_log('[QPS live blueprint DOCX] '.$e->getMessage());
    http_response_code(500);
    exit('Unable to generate the Master Blueprint DOCX.');
}
