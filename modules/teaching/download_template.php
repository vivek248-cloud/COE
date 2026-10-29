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
    if (!class_exists('\\PhpOffice\PhpWord\PhpWord')) {
        throw new RuntimeException('PHPWord is not installed. Run: composer install --no-dev --optimize-autoloader');
    }

    $word = new \PhpOffice\PhpWord\PhpWord();
    $word->setDefaultFontName('Aptos');
    $word->setDefaultFontSize(11);
    $section = $word->addSection(['marginTop'=>720,'marginBottom'=>720,'marginLeft'=>900,'marginRight'=>900]);

    $section->addText('HOLY CROSS COLLEGE (AUTONOMOUS)', ['bold'=>true,'size'=>15], ['alignment'=>'center','spaceAfter'=>60]);
    $section->addText('STAFF QUESTION BANK – STANDARD WORD TEMPLATE', ['bold'=>true,'size'=>12], ['alignment'=>'center','spaceAfter'=>120]);
    if ($courseCode !== '') $section->addText('Course / Paper Code: '.$courseCode.($courseTitle !== '' ? ' — '.$courseTitle : ''), ['bold'=>true], ['spaceAfter'=>80]);
    $section->addText('Instructions: Keep question numbers continuous (1, 2, 3, …). Enter Unit, Sub-Unit, Section, K-Level, CO and Marks for every question. Keep K-Level and Marks on the right-side metadata line. Insert diagrams/images directly below the question when required.', ['italic'=>true,'color'=>'334155'], ['spaceAfter'=>160]);

    $metaStyle = ['bold'=>true,'size'=>9,'color'=>'1E3A8A'];
    $questionStyle = ['size'=>11];
    $sampleQuestions = [
        [1,1,'1.1','SECTION-A',1,'K1','CO1','MCQ','Define the concept and state one important feature.'],
        [2,1,'1.2','SECTION-A',1,'K2','CO1','VSA','Explain the concept briefly in one or two sentences.'],
        [3,2,'2.1','SECTION-B',5,'K2','CO2','VSA','Differentiate between the two concepts with suitable examples.'],
        [4,3,'3.1','SECTION-B',5,'K3','CO3','PARAGRAPH','Explain the process and illustrate the important steps.'],
        [5,4,'4.1','SECTION-C',10,'K4','CO4','ESSAY','Analyse the given problem and justify your answer with suitable examples.'],
        [6,5,'5.1','SECTION-D',10,'K5','CO5','ESSAY','Evaluate the given situation and propose a suitable solution with justification.']
    ];

    foreach ($sampleQuestions as $row) {
        [$no,$unit,$sub,$sec,$marks,$k,$co,$type,$question] = $row;
        $section->addText('Q.NO: '.$no, ['bold'=>true,'size'=>11], ['spaceAfter'=>40]);
        $section->addText('UNIT: '.$unit, ['bold'=>true,'size'=>9,'color'=>'475569'], ['alignment'=>'right','spaceAfter'=>10]);
        $section->addText('SUB-UNIT: '.$sub, ['bold'=>true,'size'=>9,'color'=>'475569'], ['alignment'=>'right','spaceAfter'=>10]);
        $section->addText('SECTION: '.$sec, ['bold'=>true,'size'=>9,'color'=>'475569'], ['alignment'=>'right','spaceAfter'=>10]);
        $section->addText('K-LEVEL: '.$k, $metaStyle, ['alignment'=>'right','spaceAfter'=>10]);
        $section->addText('CO: '.$co, $metaStyle, ['alignment'=>'right','spaceAfter'=>10]);
        $section->addText('MARKS: '.$marks, $metaStyle, ['alignment'=>'right','spaceAfter'=>10]);
        $section->addText('QUESTION TYPE: '.$type, $metaStyle, ['alignment'=>'right','spaceAfter'=>70]);
        $section->addText('QUESTION: '.$question, $questionStyle, ['spaceAfter'=>50]);
        if ($type === 'MCQ') {
            $section->addText('(a) Option A', ['size'=>10], ['leftIndent'=>240]);
            $section->addText('(b) Option B', ['size'=>10], ['leftIndent'=>240]);
            $section->addText('(c) Option C', ['size'=>10], ['leftIndent'=>240]);
            $section->addText('(d) Option D', ['size'=>10], ['leftIndent'=>240]);
            $section->addText('ANSWER KEY: B', ['bold'=>true,'size'=>9,'color'=>'166534'], ['spaceBefore'=>40]);
        }
        $section->addText('[OPTIONAL IMAGE / DIAGRAM — insert image here if required]', ['italic'=>true,'size'=>9,'color'=>'64748B'], ['spaceBefore'=>80,'spaceAfter'=>140]);
        $section->addTextBreak(1);
    }

    $section->addText('STAFF ENTRY FORMAT', ['bold'=>true,'size'=>11,'color'=>'0F172A'], ['spaceBefore'=>100,'spaceAfter'=>60]);
    $section->addText('For each question, edit the existing block and continue the numbering. Do not restart numbering at a new unit or section. Use SECTION-A / SECTION-B / SECTION-C / SECTION-D exactly. Use K1–K6 and the approved CO code. If a question needs an image, insert it at the IMAGE / DIAGRAM placeholder.', ['size'=>10], ['spaceAfter'=>80]);
    $section->addText('Parser fields: Q.NO, UNIT, SUB-UNIT, SECTION, K-LEVEL, CO, MARKS, QUESTION TYPE, QUESTION, OPTION A-D, ANSWER KEY, IMAGE.', ['italic'=>true,'size'=>9,'color'=>'475569']);

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $prefix . '.docx"');
    header('Cache-Control: no-store');
    $writer->save('php://output');
    exit;
}

http_response_code(400);
echo 'Unsupported template format.';
