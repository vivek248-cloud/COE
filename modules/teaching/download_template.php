<?php
/**
 * Holy Cross College (Autonomous), Tiruchirappalli
 * Dedicated Master Question Bank Template Downloader
 * Supports: English, Tamil (தமிழ்), French (Français)
 * Formats: DOCX (Word), XLSX (Excel), CSV (UTF-8 with BOM), JSON
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$lang = strtolower(trim($_GET['lang'] ?? 'english'));
if (!in_array($lang, ['english', 'tamil', 'hindi', 'french'])) {
    $lang = 'english';
}

$format = strtolower(trim($_GET['format'] ?? 'csv'));
if (!in_array($format, ['csv', 'xlsx', 'docx', 'json'])) {
    $format = 'csv';
}

$courseCode = trim($_GET['course'] ?? $_GET['course_code'] ?? '');
$courseTitle = '';

if (!empty($courseCode)) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT coursetitle FROM courses WHERE UPPER(coursecode) = ? LIMIT 1");
        $stmt->execute([strtoupper($courseCode)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $courseTitle = $row['coursetitle'];
        }
    } catch (Throwable $e) {}
}

$prefix = 'HCC_Question_Bank_Template';
if ($lang === 'tamil') {
    $prefix = 'HCC_Vina_Vanki_Tamil_Template';
} elseif ($lang === 'hindi') {
    $prefix = 'HCC_Hindi_Question_Bank_Template';
} elseif ($lang === 'french') {
    $prefix = 'HCC_Banque_Questions_Francais_Template';
}

if (!empty($courseCode)) {
    $prefix .= '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $courseCode);
}

// -------------------------------------------------------------
// 1. DOCX & XLSX Formats via python template generator
// -------------------------------------------------------------
if ($format === 'docx' || $format === 'xlsx') {
    $tmpDir = sys_get_temp_dir() . '/hcc_tpl_' . uniqid();
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

    $outFile = $tmpDir . '/' . $prefix . '.' . $format;
    $script = __DIR__ . '/../../includes/template_generator.py';

    $cmd = sprintf(
        'python3 %s --lang %s --format %s --output %s --course_code %s --course_title %s',
        escapeshellarg($script),
        escapeshellarg($lang),
        escapeshellarg($format),
        escapeshellarg($outFile),
        escapeshellarg($courseCode),
        escapeshellarg($courseTitle)
    );

    exec($cmd . ' 2>&1', $output, $retCode);

    if ($retCode === 0 && file_exists($outFile)) {
        if ($format === 'docx') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        } else {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        header('Content-Disposition: attachment; filename="' . basename($outFile) . '"');
        header('Content-Length: ' . filesize($outFile));
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        readfile($outFile);
        @unlink($outFile);
        @rmdir($tmpDir);
        exit;
    }
}

// -------------------------------------------------------------
// 2. JSON Format
// -------------------------------------------------------------
if ($format === 'json') {
    $meta = [
        'institution' => 'Holy Cross College (Autonomous), Tiruchirappalli',
        'template_type' => 'Master Question Bank (OBE Matrix)',
        'language' => $lang,
        'course_code' => $courseCode,
        'course_title' => $courseTitle,
        'target_pool' => 275,
        'units' => ['I', 'II', 'III', 'IV', 'V'],
        'bloom_levels' => ['K1', 'K2', 'K3', 'K4', 'K5', 'K6'],
        'sections' => ['Part A (MC)', 'Part A (VSA)', 'Part B', 'Part C', 'Part D']
    ];

    if ($lang === 'tamil') {
        $questions = [
            [
                'q_number' => 1,
                'unit' => 'I',
                'sub_unit' => '1.1',
                'section' => 'Part A (MC)',
                'k_level' => 'K1',
                'co' => 'CO1',
                'question_type' => 'Multiple Choice',
                'question_text' => 'தொல்காப்பியத்தின் பொருளதிகாரம் எத்தனை இயல்களைக் கொண்டுள்ளது?',
                'options' => ['A' => '7 இயல்கள்', 'B' => '8 இயல்கள்', 'C' => '9 இயல்கள்', 'D' => '10 இயல்கள்'],
                'answer_key' => 'C',
                'marks' => 1,
                'formula_latex' => ''
            ],
            [
                'q_number' => 2,
                'unit' => 'I',
                'sub_unit' => '1.2',
                'section' => 'Part A (VSA)',
                'k_level' => 'K1',
                'co' => 'CO1',
                'question_type' => 'Very Short Answer',
                'question_text' => 'செம்மொழித் தகுதிப்பாடுகள் குறித்து பேராசிரியர் மணவை முஸ்தபா வரையறுத்த முதன்மை இலக்கணங்கள் யாவை?',
                'options' => null,
                'answer_key' => 'தொன்மை, தனித்தன்மை, பொதுமைப்பண்பு முதலான 11 தகுதிகள்.',
                'marks' => 2,
                'formula_latex' => ''
            ]
        ];
    } elseif ($lang === 'hindi') {
        $questions = [
            ['q_number'=>1,'unit'=>'I','sub_unit'=>'1.1','section'=>'Part A','k_level'=>'K1','co'=>'CO1','question_type'=>'बहुविकल्पीय','question_text'=>'निम्नलिखित में से सही उत्तर चुनिए।','options'=>['A'=>'विकल्प 1','B'=>'विकल्प 2','C'=>'विकल्प 3','D'=>'विकल्प 4'],'answer_key'=>'A','marks'=>1,'formula_latex'=>''],
            ['q_number'=>2,'unit'=>'I','sub_unit'=>'1.2','section'=>'Part B','k_level'=>'K2','co'=>'CO2','question_type'=>'लघु उत्तरीय','question_text'=>'दिए गए विषय को संक्षेप में समझाइए।','options'=>null,'answer_key'=>'अपेक्षित उत्तर यहाँ लिखें।','marks'=>5,'formula_latex'=>'']
        ];
    } elseif ($lang === 'french') {
        $questions = [
            [
                'q_number' => 1,
                'unit' => 'I',
                'sub_unit' => '1.1',
                'section' => 'Part A (MC)',
                'k_level' => 'K1',
                'co' => 'CO1',
                'question_type' => 'Multiple Choice',
                'question_text' => 'Quel est le participe passé régulier du verbe « choisir » en français ?',
                'options' => ['A' => 'Choisi', 'B' => 'Choisissant', 'C' => 'Choisit', 'D' => 'Choisie'],
                'answer_key' => 'A',
                'marks' => 1,
                'formula_latex' => ''
            ],
            [
                'q_number' => 2,
                'unit' => 'I',
                'sub_unit' => '1.2',
                'section' => 'Part A (VSA)',
                'k_level' => 'K1',
                'co' => 'CO1',
                'question_type' => 'Very Short Answer',
                'question_text' => 'Définissez la règle d accord du participe passé avec l auxiliaire « avoir ».',
                'options' => null,
                'answer_key' => 'Accord avec le COD si placé avant le verbe.',
                'marks' => 2,
                'formula_latex' => ''
            ]
        ];
    } else {
        $questions = [
            [
                'q_number' => 1,
                'unit' => 'I',
                'sub_unit' => '1.1',
                'section' => 'Part A (MC)',
                'k_level' => 'K1',
                'co' => 'CO1',
                'question_type' => 'Multiple Choice',
                'question_text' => 'Which of the following data structures follows the Last-In-First-Out (LIFO) principle?',
                'options' => ['A' => 'Queue', 'B' => 'Stack', 'C' => 'Linked List', 'D' => 'Tree'],
                'answer_key' => 'B',
                'marks' => 1,
                'formula_latex' => ''
            ],
            [
                'q_number' => 2,
                'unit' => 'I',
                'sub_unit' => '1.2',
                'section' => 'Part A (VSA)',
                'k_level' => 'K1',
                'co' => 'CO1',
                'question_type' => 'Very Short Answer',
                'question_text' => 'Define time complexity of an algorithm and state Big-O notation for linear search.',
                'options' => null,
                'answer_key' => 'Time complexity measures execution steps; linear search is O(n).',
                'marks' => 2,
                'formula_latex' => 'O(n)'
            ]
        ];
    }

    $payload = [
        'metadata' => $meta,
        'questions' => $questions
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $prefix . '.json"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// -------------------------------------------------------------
// 3. CSV Format (with UTF-8 BOM for accurate Tamil & French in Excel)
// -------------------------------------------------------------
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $prefix . '.csv"');

// Output UTF-8 BOM so Excel opens Tamil and French characters perfectly
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

if ($lang === 'tamil') {
    fputcsv($output, [
        'வினா எண்',
        'அலகு (Unit)',
        'துணை அலகு (Sub-Unit)',
        'பகுதி (Section)',
        'அறிவாற்றல் நிலை (K-Level)',
        'பாடம் விளைவு (CO)',
        'வினா வகை',
        'வினா உரை (Question Text)',
        'தெரிவு A',
        'தெரிவு B',
        'தெரிவு C',
        'தெரிவு D',
        'விடைக்குறிப்பு (Answer Key)',
        'மதிப்பெண்',
        'சூத்திரம் / குறிப்பு'
    ]);

    $sampleQuestions = [
        [1, 'I', '1.1', 'பகுதி அ (MC)', 'K1', 'CO1', 'பலவுள் தெரிவு', 'தொல்காப்பியத்தின் பொருளதிகாரம் எத்தனை இயல்களைக் கொண்டுள்ளது?', '7 இயல்கள்', '8 இயல்கள்', '9 இயல்கள்', '10 இயல்கள்', 'C', 1, ''],
        [2, 'I', '1.1', 'பகுதி அ (MC)', 'K1', 'CO1', 'பொருத்துக', 'பொருத்துக: (1) குறிஞ்சி (2) முல்லை (3) மருதம் (4) நெய்தல் உடன் (அ) வயல் (ஆ) மலை (இ) காடு (ஈ) கடல்', '(1)-(ஆ), (2)-(இ), (3)-(அ), (4)-(ஈ)', '(1)-(அ), (2)-(ஆ), (3)-(இ), (4)-(ஈ)', '(1)-(ஈ), (2)-(அ), (3)-(இ), (4)-(ஆ)', '(1)-(இ), (2)-(ஆ), (3)-(ஈ), (4)-(அ)', 'A', 1, ''],
        [3, 'I', '1.2', 'பகுதி அ (MC)', 'K2', 'CO1', 'கூற்று-காரணம்', 'கூற்று (A): எட்டுத்தொகை நூல்களுள் அகநூல்கள் ஆறு உள்ளன. காரணம் (R): பதிற்றுப்பத்தும் புறநானூறும் புறப்பொருள் பற்றிய நூல்களாகும்.', 'கூற்று (A) மற்றும் காரணம் (R) இரண்டும் சரி', 'கூற்று (A) சரி, ஆனால் காரணம் (R) தவறு', 'கூற்று (A) தவறு, ஆனால் காரணம் (R) சரி', 'இரண்டும் தவறு', 'A', 1, ''],
        [4, 'I', '1.2', 'பகுதி அ (VSA)', 'K1', 'CO1', 'குறுவினா', 'செம்மொழித் தகுதிப்பாடுகள் குறித்து பேராசிரியர் மணவை முஸ்தபா வரையறுத்த முதன்மை இலக்கணங்கள் யாவை?', '', '', '', '', 'தொன்மை, தனித்தன்மை, பொதுமைப்பண்பு, நடுவுநிலைமை, தாய்மைப்பண்பு முதலான 11 தகுதிகள்.', 2, ''],
        [5, 'I', '1.3', 'பகுதி அ (VSA)', 'K2', 'CO1', 'குறுவினா', 'சங்க இலக்கியத்தில் நிலவிய உள்ளுறை உவமம் மற்றும் இறைச்சி ஆகியவற்றின் வேறுபாட்டினை விளக்குக.', '', '', '', '', 'உள்ளுறை உவமம் கருப்பொருளின் வழி உணர்த்தும்; இறைச்சி குறிப்புப் பொருளால் வெளிப்படும்.', 2, ''],
        [6, 'I', '1.3', 'பகுதி ஆ', 'K1', 'CO1', 'சிறுவினா', 'முல்லைப்பாட்டில் விவரிக்கப்படும் கார்கால மாலைப்பொழுதின் இயற்கை வனப்பினை விளக்குக.', '', '', '', '', 'நப்பூதனாரின் முல்லைப்பாட்டு கார்கால வர்ணனை மற்றும் விருச்சி கேட்டல் நிகழ்வுகள்.', 5, ''],
        [7, 'II', '2.1', 'பகுதி இ', 'K1', 'CO2', 'விரிவான வினா', 'பக்தி இலக்கியக் கால கட்டத்தில் திருநாவுக்கரசர் மற்றும் மாணிக்கவாசகரின் தமிழ்த் தொண்டினை விரிவாக விவரிக்க.', '', '', '', '', 'தேவாரப் பதிகங்கள், திருவாசகத் தத்துவ மேன்மை மற்றும் மக்கள் சமயப் புரட்சி.', 8, ''],
        [8, 'III', '3.1', 'பகுதி ஈ', 'K2', 'CO3', 'கட்டுரை வினா', 'தற்காலத் தமிழ் உரைநடை மற்றும் சிறுகதை வளர்ச்சியில் புதுமைப்பித்தன் மற்றும் ஜெயகாந்தனின் படைப்புப் பங்களிப்பினை மதிப்பிடுக.', '', '', '', '', 'யதார்த்தவாத சிறுகதை மரபு, மனித மன முரண்கள், சமூக விழிப்புணர்வுப் படைப்புகள்.', 10, '']
    ];
} elseif ($lang === 'hindi') {
    fputcsv($output, [
        'प्रश्न सं.', 'यूनिट', 'उप-यूनिट', 'सेक्शन', 'K-स्तर', 'CO', 'प्रश्न प्रकार', 'प्रश्न',
        'विकल्प A', 'विकल्प B', 'विकल्प C', 'विकल्प D', 'उत्तर कुंजी', 'अंक', 'सूत्र / LaTeX'
    ]);
    $sampleQuestions = [
        [1,'I','1.1','Part A','K1','CO1','बहुविकल्पीय','निम्नलिखित में से सही उत्तर चुनिए।','विकल्प 1','विकल्प 2','विकल्प 3','विकल्प 4','A',1,''],
        [2,'I','1.2','Part B','K2','CO2','लघु उत्तरीय','दिए गए विषय को संक्षेप में समझाइए।','','','','','अपेक्षित उत्तर यहाँ लिखें।',5,'']
    ];
} elseif ($lang === 'french') {
    fputcsv($output, [
        'N° Q',
        'Unité',
        'Sous-unité',
        'Section',
        'Niveau K',
        'CO',
        'Type de question',
        'Texte de la question',
        'Option A',
        'Option B',
        'Option C',
        'Option D',
        'Clé de réponse',
        'Points',
        'Formule / Remarque'
    ]);

    $sampleQuestions = [
        [1, 'I', '1.1', 'Partie A (QCM)', 'K1', 'CO1', 'Choix Multiple', 'Quel est le participe passé régulier du verbe « choisir » en français ?', 'Choisi', 'Choisissant', 'Choisit', 'Choisie', 'A', 1, ''],
        [2, 'I', '1.1', 'Partie A (QCM)', 'K1', 'CO1', 'Appariement', 'Associez: (1) Le Louvre (2) La Sorbonne (3) L Élysée avec (a) Université (b) Musée (c) Présidence', '(1)-(b), (2)-(a), (3)-(c)', '(1)-(a), (2)-(b), (3)-(c)', '(1)-(c), (2)-(a), (3)-(b)', '(1)-(b), (2)-(c), (3)-(a)', 'A', 1, ''],
        [3, 'I', '1.2', 'Partie A (QCM)', 'K2', 'CO1', 'Assertion-Raison', 'Assertion (A): Le subjonctif s emploie après « bien que ». Raison (R): « Bien que » exprime une concession hypothétique.', 'A et R sont vrais et R explique A', 'A et R sont vrais mais R n explique pas A', 'A est vrai mais R est faux', 'A est faux mais R est vrai', 'A', 1, ''],
        [4, 'I', '1.2', 'Partie A (VSA)', 'K1', 'CO1', 'Réponse Très Courte', 'Définissez la règle d accord du participe passé avec l auxiliaire « avoir ».', '', '', '', '', 'Le participe passé s accorde en genre et en nombre avec le COD si celui-ci est placé avant le verbe.', 2, ''],
        [5, 'I', '1.3', 'Partie B', 'K1', 'CO1', 'Réponse Courte', 'Expliquez l usage des pronoms relatifs composés (auquel, duquel, lequel) avec trois exemples illustratifs.', '', '', '', '', 'Règles de contraction avec les prépositions à et de + exemples contextualisés.', 5, ''],
        [6, 'II', '2.1', 'Partie C', 'K1', 'CO2', 'Descriptive', 'Décrivez l impact de la Révolution Française de 1789 sur la diffusion de la langue et des valeurs républicaines.', '', '', '', '', 'Unification linguistique par l abbé Grégoire, Déclaration des droits de l homme.', 8, ''],
        [7, 'III', '3.1', 'Partie D', 'K2', 'CO3', 'Dissertation', '« La Francophonie moderne : vecteur de diversité culturelle ou héritage linguistique ? » Développez votre réflexion.', '', '', '', '', 'Dissertation en trois parties sur le rayonnement culturel et défis contemporains.', 10, '']
    ];
} else {
    fputcsv($output, [
        'Q.No',
        'Unit',
        'Sub-Unit',
        'Section',
        'K-Level',
        'CO',
        'Question Type',
        'Question Text',
        'Option A',
        'Option B',
        'Option C',
        'Option D',
        'Answer Key',
        'Marks',
        'Formula / LaTeX'
    ]);

    $sampleQuestions = [
        [1, 'I', '1.1', 'Part A (MC)', 'K1', 'CO1', 'Multiple Choice', 'Which of the following data structures follows the Last-In-First-Out (LIFO) principle?', 'Queue', 'Stack', 'Linked List', 'Tree', 'B', 1, ''],
        [2, 'I', '1.1', 'Part A (MC)', 'K1', 'CO1', 'Match Following', 'Match: (i) Stack (ii) Queue (iii) Binary Tree with (a) FIFO (b) Hierarchical (c) LIFO', '(i)-(c), (ii)-(a), (iii)-(b)', '(i)-(a), (ii)-(c), (iii)-(b)', '(i)-(b), (ii)-(a), (iii)-(c)', '(i)-(c), (ii)-(b), (iii)-(a)', 'A', 1, ''],
        [3, 'I', '1.2', 'Part A (MC)', 'K2', 'CO1', 'Assertion-Reason', 'Assertion (A): Binary search requires a sorted array. Reason (R): Binary search uses divide-and-conquer strategy.', 'Both A and R are true and R is correct explanation of A', 'Both A and R are true but R is NOT correct explanation', 'A is true but R is false', 'A is false but R is true', 'B', 1, ''],
        [4, 'I', '1.2', 'Part A (VSA)', 'K1', 'CO1', 'Very Short Answer', 'Define time complexity of an algorithm and state Big-O notation for linear search.', '', '', '', '', 'Time complexity measures execution steps. Big-O for linear search is O(n).', 2, 'O(n)'],
        [5, 'I', '1.3', 'Part A (VSA)', 'K2', 'CO1', 'Very Short Answer', 'Differentiate between call by value and call by reference with a memory diagram outline.', '', '', '', '', 'Call by value passes copy; call by reference passes memory address.', 2, ''],
        [6, 'I', '1.3', 'Part B', 'K1', 'CO1', 'Short Answer', 'Explain the memory representation of single-dimensional and multi-dimensional arrays with address calculation formulas.', '', '', '', '', 'Base address calculation formula: Loc(A[i]) = Base(A) + w * (i - lower_bound)', 5, 'Loc(A[i]) = B + w(i - lb)'],
        [7, 'II', '2.1', 'Part C', 'K1', 'CO2', 'Descriptive', 'Describe the implementation of circular queue operations (enqueue, dequeue, display) using arrays with boundary condition checks.', '', '', '', '', 'Detailed algorithm with front/rear index wrap-around formulas.', 8, 'rear = (rear + 1) % MAX'],
        [8, 'III', '3.1', 'Part D', 'K2', 'CO3', 'Comprehensive / Essay', 'Elaborate on AVL Tree rotations (LL, RR, LR, RL) with balance factor criteria and construct an AVL tree for numbers: 15, 20, 24, 10, 13, 7, 30, 36, 25.', '', '', '', '', 'Complete AVL construction with step-by-step balance factors and rotation diagrams.', 10, 'BF = h_L - h_R']
    ];
}

foreach ($sampleQuestions as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit;
