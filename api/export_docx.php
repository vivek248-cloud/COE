<?php
/**
 * Official Question Paper DOCX Download Endpoint
 * Holy Cross College (Autonomous) QPS
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paper_rebuild.php';

requireAuth();
$pdo = getDBConnection();

$setKey = trim((string)($_GET['set_key'] ?? ''));
$paperId = isset($_GET['paper_id']) ? intval($_GET['paper_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

try {
    if ($setKey !== '') {
        $rebuilt = qps_rebuild_paper($pdo, $setKey);
        $groups = [];
        foreach ($rebuilt['questions'] as $q) {
            $section = (string)($q['section_type'] ?? 'SECTION-A');
            $text = (string)($q['question_text'] ?? '');
            if (!empty($q['options']) && is_array($q['options'])) {
                foreach (array_values($q['options']) as $i=>$option) $text .= "\n" . chr(65+$i) . ') ' . (string)$option;
            }
            $groups[$section][] = [
                'display_qno'=>$q['print_number']??$q['q_number']??'',
                'q_number'=>$q['print_number']??$q['q_number']??'',
                'question_text'=>$text,
                'k_level'=>$q['k_level']??'',
                'co_level'=>$q['co_level']??'',
                'marks'=>$q['marks']??0,
                'image_url'=>$q['image_url']??''
            ];
        }
        $sections=[];
        $paperSectionConfigs=$rebuilt['paper_type']['sections']??[];
        foreach($groups as $section=>$qs){
            $code=preg_replace('/^SECTION[-_]?/i','',$section);$cfg=null;
            foreach($paperSectionConfigs as $candidate){if(strtoupper((string)($candidate['code']??''))===strtoupper($code)){$cfg=$candidate;break;}}
            $sections[]=['section_name'=>$cfg['title']??str_replace(['SECTION-','_'],['Section ',' '],$section),
                'choice_formula'=>$cfg['choice_formula']??'','instruction'=>$cfg['instruction']??'',
                'total_marks'=>array_sum(array_map(static fn($q)=>(int)($q['marks']??0),$qs)),'questions'=>$qs];
        }
        $paper=[
            'paper_code'=>$rebuilt['paper_code'],'course_title'=>$rebuilt['course_title'],
            'set_name'=>$rebuilt['set_name'],'semester'=>$rebuilt['semester'],
            'academic_year'=>$rebuilt['academic_year'],'exam_type'=>$rebuilt['exam_type'],
            'total_marks'=>$rebuilt['total_marks'],'sections'=>$sections,
            'institution'=>$rebuilt['header']['institution']??'HOLY CROSS COLLEGE (AUTONOMOUS), TIRUCHIRAPPALLI – 620 002',
            'header_lines'=>$rebuilt['header']['lines']??[]
        ];
        $paperCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $paper['paper_code']);
        $setName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $paper['set_name']);
        $fileId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $setKey);
    } else {
        if (!$paperId) { http_response_code(400); die('Question paper ID or set key required.'); }
        $stmt = $pdo->prepare("SELECT * FROM generated_papers WHERE id = ?");
        $stmt->execute([$paperId]);
        $row = $stmt->fetch();
        if (!$row) { http_response_code(404); die('Generated paper not found.'); }
        $paper = json_decode($row['paper_data_json'], true) ?: [];
        $paper['id'] = $row['id'];
        $paperCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $paper['paper_code'] ?? ($row['paper_code'] ?? 'HCC_PAPER'));
        $setName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $paper['set_name'] ?? ($row['set_name'] ?? 'SET_A'));
        $fileId = (string)$paperId;
    }

    // Create temporary files
    $tmpDir = sys_get_temp_dir() . '/hcc_docx_' . uniqid();
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

    $jsonFile = $tmpDir . '/paper_' . $fileId . '.json';
    $docxFile = $tmpDir . '/' . $paperCode . '_' . $setName . '.docx';

    file_put_contents($jsonFile, json_encode($paper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $cmd = 'python3 ' . escapeshellarg(__DIR__ . '/../docx_generator.py') . ' ' . escapeshellarg($jsonFile) . ' ' . escapeshellarg($docxFile);
    exec($cmd . ' 2>&1', $output, $retCode);

    if ($retCode !== 0 || !file_exists($docxFile)) {
        http_response_code(500);
        die('Error rendering DOCX: ' . implode("\n", $output));
    }

    $filename = $paperCode . '_' . $setName . '_HolyCrossCollege.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($docxFile));
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');

    readfile($docxFile);

    @unlink($jsonFile);
    @unlink($docxFile);
    @rmdir($tmpDir);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    die('Server error: ' . $e->getMessage());
}
