<?php
/**
 * COE Staff Question Bank Template Downloader v4.
 * Production template generation is PHP-only.
 * Required fields:
 * Q.No, Unit, Sub-Unit, K-Level, CO, Section, Marks, Question.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

requireAuth();

$lang = strtolower(trim($_GET['lang'] ?? 'english'));
if (!in_array($lang, ['english','tamil','hindi','french'], true)) $lang = 'english';

$format = strtolower(trim($_GET['format'] ?? 'csv'));
if (!in_array($format, ['csv','xlsx','docx'], true)) $format = 'csv';

$courseCode = trim((string)($_GET['course'] ?? $_GET['course_code'] ?? ''));
$courseTitle = '';

try {
    $pdo = getDBConnection();
    if ($courseCode !== '') {
        $st = $pdo->prepare("SELECT coursetitle FROM courses WHERE UPPER(coursecode)=? LIMIT 1");
        $st->execute([strtoupper($courseCode)]);
        $courseTitle = (string)($st->fetchColumn() ?: '');
    }
} catch (Throwable $e) {}

$prefix = 'HCC_Staff_Simple_Question_Bank_Template';
if ($lang === 'tamil') $prefix = 'HCC_Staff_Vina_Vanki_Simple_Template';
if ($lang === 'hindi') $prefix = 'HCC_Staff_Hindi_Simple_Question_Bank_Template';
if ($lang === 'french') $prefix = 'HCC_Staff_Francais_Simple_Question_Bank_Template';
if ($courseCode !== '') $prefix .= '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $courseCode);

$headers = match ($lang) {
    'tamil' => ['Q.No','Unit','Sub-Unit','Section','Marks','K-Level','CO','Question Type','Question','Option A','Option B','Option C','Option D','Answer Key','Image URL','Formula LaTeX','Assertion','Reason','Match Column A','Match Column B','Match Options'],
    'hindi' => ['Q.No','Unit','Sub-Unit','Section','Marks','K-Level','CO','Question Type','Question','Option A','Option B','Option C','Option D','Answer Key','Image URL','Formula LaTeX','Assertion','Reason','Match Column A','Match Column B','Match Options'],
    'french' => ['Q.No','Unit','Sub-Unit','Section','Marks','K-Level','CO','Question Type','Question','Option A','Option B','Option C','Option D','Answer Key','Image URL','Formula LaTeX','Assertion','Reason','Match Column A','Match Column B','Match Options'],
    default => ['Q.No','Unit','Sub-Unit','Section','Marks','K-Level','CO','Question Type','Question','Option A','Option B','Option C','Option D','Answer Key','Image URL','Formula LaTeX','Assertion','Reason','Match Column A','Match Column B','Match Options']
};

$rows = [
    [1,1,'1.1','SECTION-A',1,'K1','CO1','MCQ','Which of the following is correct?','Option A','Option B','Option C','Option D','B','','','','','','',''],
    [2,1,'1.2','SECTION-A',2,'K2','CO2','VSA','Explain the concept in one or two sentences.','','','','','','','','','','','',''],
    [3,2,'2.1','SECTION-B',5,'K3','CO3','PARAGRAPH','Solve the following problem and show the required steps.','','','','','','','','','','','',''],
    [4,3,'3.1','SECTION-C',10,'K4','CO4','ESSAY','Analyse the case study and include the required diagram.','','','','','','','','','','','',''],
    [5,4,'4.1','SECTION-D',10,'K5','CO5','ESSAY','Evaluate the given situation and justify your answer.','','','','','','','','','','','',''],
    [6,5,'5.1','SECTION-D',10,'K6','CO6','ESSAY','Design a suitable solution for the given problem.','','','','','','','','','','','','']
];

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $prefix . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    // Course code is written once in the file header, never repeated per question.
    if ($courseCode !== '') fputcsv($out, ['COURSE CODE', $courseCode]);
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Composer dependencies are not installed. Run: composer install --no-dev --optimize-autoloader";
    exit;
}
require_once $autoload;

if ($format === 'xlsx') {
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        throw new RuntimeException('PhpSpreadsheet is not installed.');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Question Bank');

    $allRows = array_merge([$headers], $rows);
    foreach ($allRows as $r => $row) {
        foreach ($row as $c => $value) {
            $sheet->setCellValueByColumnAndRow($c + 1, $r + 1, $value);
        }
    }

    foreach (range(1, count($headers)) as $col) {
        $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
    }
    $sheet->getColumnDimensionByColumn(9)->setWidth(55);
    $sheet->getColumnDimensionByColumn(10)->setWidth(45);
    $sheet->getColumnDimensionByColumn(12)->setWidth(35);
    $sheet->getColumnDimensionByColumn(13)->setWidth(30);
    $sheet->freezePane('A2');
    $sheet->getAutoFilter()->setRangeByColumnAndRow(1, 1, count($headers), count($allRows));

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $prefix . '.xlsx"');
    header('Cache-Control: no-store');
    $writer->save('php://output');
    exit;
}

if ($format === 'docx') {
    if (!class_exists('\PhpOffice\PhpWord\PhpWord')) {
        throw new RuntimeException('PHPWord is not installed.');
    }

    $word = new \PhpOffice\PhpWord\PhpWord();
    $word->setDefaultFontName('Aptos');
    $word->setDefaultFontSize(10);
    $section = $word->addSection(['marginTop'=>700,'marginBottom'=>700,'marginLeft'=>800,'marginRight'=>800]);
    $section->addText('HOLY CROSS COLLEGE (AUTONOMOUS)', ['bold'=>true,'size'=>15], ['alignment'=>'center']);
    $section->addText('Standard Staff Question Bank Template — V5', ['bold'=>true,'size'=>12], ['alignment'=>'center','spaceAfter'=>120]);
    if ($courseCode !== '') $section->addText('Course: '.$courseCode.($courseTitle !== '' ? ' — '.$courseTitle : ''), ['bold'=>true]);
    $section->addText('One logical question = one block. Embedded Word images are preserved. Excel/CSV users should use the standard 21-column format.', ['bold'=>true,'color'=>'1D4ED8']);

    foreach ($rows as $row) {
        $section->addText('Q'.(string)$row[0].'. [UNIT:'.$row[1].'] [SUB:'.$row[2].'] [SECTION:'.$row[3].'] [MARKS:'.$row[4].'] [K:'.$row[5].'] [CO:'.$row[6].'] [TYPE:'.$row[7].']', ['bold'=>true,'size'=>9,'color'=>'334155']);
        $section->addText((string)$row[8]);
        if ((string)$row[9] !== '') $section->addText((string)$row[9]);
        if ((string)$row[10] !== '') $section->addText('Answer: '.(string)$row[10]);
        if ((string)$row[11] !== '') $section->addText('Image URL: '.(string)$row[11]);
        if ((string)$row[12] !== '') $section->addText('Formula: '.(string)$row[12]);
        $section->addTextBreak(1);
    }
    $section->addText('Standard fields: q_number, unit_no, sub_unit, section_type, marks, k_level, co_level, question_type, question_text, options, answer_key, image_url, formula_latex.', ['italic'=>true]);

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $prefix . '.docx"');
    header('Cache-Control: no-store');
    $writer->save('php://output');
    exit;
}

http_response_code(400);
echo 'Unsupported template format.';
