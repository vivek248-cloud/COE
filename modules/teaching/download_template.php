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
    'tamil' => ['வினா எண்','அலகு','துணை அலகு','K-நிலை','CO','பகுதி','மதிப்பெண்','வினா'],
    'hindi' => ['प्रश्न सं.','यूनिट','उप-यूनिट','K-स्तर','CO','सेक्शन','अंक','प्रश्न'],
    'french' => ['N° Q','Unité','Sous-unité','Niveau K','CO','Section','Points','Question'],
    default => ['Q.No','Unit','Sub-Unit','K-Level','CO','Section','Marks','Question']
};

$rows = match ($lang) {
    'tamil' => [
        [1,1,'1.1','K1','CO1','A',1,'பின்வருவனவற்றில் சரியான விடையைத் தேர்ந்தெடுக்கவும். (a) ஒன்று (b) இரண்டு (c) மூன்று (d) நான்கு'],
        [2,1,'1.2','K2','CO1','A',2,'இந்தக் கருத்தைச் சுருக்கமாக விளக்குக.'],
        [3,1,'1.3','K3','CO2','B',5,'இந்தத் தலைப்பின் முக்கியத்துவத்தை விளக்குக.'],
        [4,1,'1.4','K4','CO3','C',10,'இந்தத் தலைப்பை பகுப்பாய்வு செய்து பொருத்தமான எடுத்துக்காட்டுகளுடன் விளக்குக.']
    ],
    'hindi' => [
        [1,1,'1.1','K1','CO1','A',1,'सही उत्तर चुनिए। (a) एक (b) दो (c) तीन (d) चार'],
        [2,1,'1.2','K2','CO1','A',2,'इस विषय को संक्षेप में समझाइए।'],
        [3,1,'1.3','K3','CO2','B',5,'इस विषय के महत्व पर चर्चा कीजिए।'],
        [4,1,'1.4','K4','CO3','C',10,'इस विषय का विश्लेषण उदाहरण सहित कीजिए।']
    ],
    'french' => [
        [1,1,'1.1','K1','CO1','A',1,'Choisissez la bonne réponse. (a) Un (b) Deux (c) Trois (d) Quatre'],
        [2,1,'1.2','K2','CO1','A',2,'Expliquez brièvement ce sujet.'],
        [3,1,'1.3','K3','CO2','B',5,'Discutez l’importance de ce sujet.'],
        [4,1,'1.4','K4','CO3','C',10,'Analysez ce sujet avec des exemples appropriés.']
    ],
    default => [
        [1,1,'1.1','K1','CO1','A',1,'Which of the following data structures follows LIFO? (a) Queue (b) Stack (c) Tree (d) Graph'],
        [2,1,'1.2','K2','CO1','A',2,'Explain the given concept briefly.'],
        [3,1,'1.3','K3','CO2','B',5,'Discuss the significance of the given topic.'],
        [4,1,'1.4','K4','CO3','C',10,'Analyse the topic with suitable examples.']
    ]
};

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $prefix . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
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

    $section = $word->addSection([
        'marginTop' => 700,
        'marginBottom' => 700,
        'marginLeft' => 800,
        'marginRight' => 800
    ]);

    $section->addText(
        'HOLY CROSS COLLEGE (AUTONOMOUS)',
        ['bold' => true, 'size' => 15],
        ['alignment' => 'center']
    );
    $section->addText(
        'Staff Question Bank — v4 Structured Template',
        ['bold' => true, 'size' => 12],
        ['alignment' => 'center', 'spaceAfter' => 120]
    );

    if ($courseCode !== '') {
        $section->addText('Course: ' . $courseCode . ($courseTitle !== '' ? ' — ' . $courseTitle : ''), ['bold' => true]);
    }

    $section->addText(
        'Required order: Q.No → Unit → Sub-Unit → K-Level → CO → Section → Marks → Question',
        ['bold' => true, 'color' => '1D4ED8'],
        ['spaceAfter' => 160]
    );

    $table = $section->addTable([
        'borderSize' => 6,
        'borderColor' => 'CBD5E1',
        'cellMargin' => 70
    ]);

    $table->addRow();
    foreach ($headers as $h) {
        $table->addCell(1100)->addText($h, ['bold' => true, 'size' => 9]);
    }

    foreach ($rows as $row) {
        $table->addRow();
        foreach ($row as $value) {
            $table->addCell(1100)->addText((string)$value, ['size' => 9]);
        }
    }

    $section->addTextBreak(1);
    $section->addText('Rules:', ['bold' => true]);
    foreach ([
        'One row/block represents exactly one question.',
        'Do not leave Unit, Sub-Unit, K-Level, CO, Section or Marks blank.',
        'CO is entered explicitly by staff and is not inferred from K-Level.',
        'Use Unicode for Tamil, Hindi and French text.',
        'The importer validates the data before saving it.'
    ] as $rule) {
        $section->addListItem($rule, 0);
    }

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $prefix . '.docx"');
    header('Cache-Control: no-store');
    $writer->save('php://output');
    exit;
}

http_response_code(400);
echo 'Unsupported template format.';
