<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/question_importer.php';

$blocks = [
    ['text' => 'QUESTION_START • HI-0001'],
    ['text' => 'SECTION | A'],
    ['text' => 'QUESTION_NO |'],
    ['text' => 'QUESTION_TYPE | MCQ'],
    ['text' => 'UNIT | 1'],
    ['text' => 'SUB_UNIT | 1.1'],
    ['text' => 'K_LEVEL | K1'],
    ['text' => 'MARKS | 1'],
    ['text' => 'QUESTION_TEXT | 1. Who headed the Boundary Commission?'],
    ['text' => 'OPTION_A | Mountbatten'],
    ['text' => 'OPTION_B | Radcliffe'],
    ['text' => 'OPTION_C | James Bolt'],
    ['text' => 'OPTION_D | Richardson'],
    ['text' => 'ANSWER_KEY | B'],
    ['text' => 'QUESTION_END'],
    ['text' => 'QUESTION_START • HI-0002'],
    ['text' => 'SECTION | A'],
    ['text' => 'QUESTION_NO |'],
    ['text' => 'QUESTION_TYPE | MATCH'],
    ['text' => 'UNIT | 1'],
    ['text' => 'SUB_UNIT | 1.2'],
    ['text' => 'K_LEVEL | K2'],
    ['text' => 'MARKS | 1'],
    ['text' => 'QUESTION_TEXT | 1. Match the following: 1. A 2. B 3. C 4. D'],
    ['text' => 'ANSWER_KEY | A'],
    ['text' => 'QUESTION_END'],
];

$rows = qps_parse_staff_docx_v4($blocks);

if (count($rows) !== 2) {
    fwrite(STDERR, "FAIL: expected 2 logical questions, got " . count($rows) . PHP_EOL);
    exit(1);
}

if (($rows[0]['q_number'] ?? 0) !== 1 || ($rows[1]['q_number'] ?? 0) !== 2) {
    fwrite(STDERR, "FAIL: deterministic question numbering is incorrect." . PHP_EOL);
    exit(1);
}

if (($rows[1]['question_type'] ?? '') !== 'MATCH') {
    fwrite(STDERR, "FAIL: MATCH question type was not preserved." . PHP_EOL);
    exit(1);
}

if (($rows[0]['answer_key'] ?? '') !== 'B' || ($rows[1]['answer_key'] ?? '') !== 'A') {
    fwrite(STDERR, "FAIL: answer keys were not extracted." . PHP_EOL);
    exit(1);
}

echo "PASS: standard 2-column DOCX blocks -> 2 logical questions, no MATCH row splitting." . PHP_EOL;
