<?php

declare(strict_types=1);

/**
 * Question Paper System
 * Question Bank Helpers
 *
 * Handles:
 * - CSV parsing
 * - DOCX parsing
 * - Question normalization
 * - Question hashing
 * - Validation
 * - Default marks
 * - Duplicate checking
 *
 * Authentication is intentionally NOT handled here.
 */


/* =========================================================
   OPTIONAL CONFIG LOAD
========================================================= */

if (!defined('MAX_CSV_ROWS')) {
    $configPath = dirname(__DIR__) . '/config/config.php';

    if (is_file($configPath)) {
        require_once $configPath;
    }
}


/* =========================================================
   ALLOWED VALUES
========================================================= */

/**
 * Return all supported question formats.
 */
function qbank_allowed_formats(): array
{
    if (defined('ALLOWED_QUESTION_FORMATS')) {
        return ALLOWED_QUESTION_FORMATS;
    }

    return [
        'AR',
        'Match',
        'MCQ',
        'VSA',
        'Paragraph Answer',
        'Essay',
    ];
}


/**
 * Return allowed Bloom levels for a format.
 */
function qbank_allowed_bloom(?string $format = null): array
{
    $levels = defined('ALLOWED_BLOOM_LEVELS')
        ? ALLOWED_BLOOM_LEVELS
        : [
            'AR' => ['K2'],
            'Match' => ['K1'],
            'MCQ' => ['K1', 'K2', 'K3'],
            'VSA' => ['K1', 'K2', 'K3', 'K4'],
            'Paragraph Answer' => ['K1', 'K2', 'K3', 'K4', 'K5'],
            'Essay' => ['K1', 'K2', 'K3', 'K4', 'K5'],
        ];

    if ($format === null) {
        $result = [];

        foreach ($levels as $formatLevels) {
            foreach ($formatLevels as $level) {
                $result[] = $level;
            }
        }

        return array_values(array_unique($result));
    }

    return $levels[$format] ?? [];
}


/**
 * Return default marks for a question format.
 */
function qbank_default_marks(string $format): float
{
    $defaults = defined('DEFAULT_MARKS')
        ? DEFAULT_MARKS
        : [
            'AR' => 1,
            'Match' => 1,
            'MCQ' => 1,
            'VSA' => 2,
            'Paragraph Answer' => 5,
            'Essay' => 10,
        ];

    return isset($defaults[$format])
        ? (float) $defaults[$format]
        : 0.0;
}


/**
 * Check whether a Bloom level is valid for a format.
 */
function qbank_bloom_allowed(
    string $format,
    string $bloom
): bool {
    return in_array(
        trim($bloom),
        qbank_allowed_bloom($format),
        true
    );
}


/* =========================================================
   TEXT HELPERS
========================================================= */

/**
 * Normalize question text for duplicate detection.
 *
 * Example:
 *
 * "What is   Machine Learning?"
 *
 * becomes:
 *
 * "what is machine learning?"
 */
function qbank_normalize_text(?string $text): string
{
    $text = (string) $text;

    // Remove UTF-8 BOM.
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;

    // Decode HTML entities if any.
    $text = html_entity_decode(
        $text,
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    // Normalize common whitespace characters.
    $text = str_replace(
        [
            "\r\n",
            "\r",
            "\n",
            "\t",
            "\xC2\xA0",
        ],
        ' ',
        $text
    );

    // Collapse repeated whitespace.
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    // Normalize spaces around punctuation.
    $text = preg_replace('/\s+([,.!?;:])/', '$1', $text) ?? $text;

    // Normalize quotation marks.
    $text = str_replace(
        [
            '“',
            '”',
            '‘',
            '’',
            '–',
            '—',
        ],
        [
            '"',
            '"',
            "'",
            "'",
            '-',
            '-',
        ],
        $text
    );

    return strtolower(trim($text));
}


/**
 * Generate SHA-256 hash for normalized question text.
 */
function qbank_hash(?string $text): string
{
    $normalized = qbank_normalize_text($text);

    return hash('sha256', $normalized);
}


/**
 * Remove BOM from a string.
 */
function qbank_remove_bom(string $value): string
{
    return preg_replace(
        '/^\xEF\xBB\xBF/',
        '',
        $value
    ) ?? $value;
}


/**
 * Normalize a CSV/DOCX header.
 */
function qbank_normalize_header(string $header): string
{
    $header = qbank_remove_bom($header);

    $header = trim($header);

    $header = strtolower($header);

    $header = str_replace(
        [
            '_',
            '-',
            '.',
        ],
        ' ',
        $header
    );

    $header = preg_replace('/\s+/u', ' ', $header) ?? $header;

    return trim($header);
}


/**
 * Convert user supplied format to the system's canonical format.
 */
function qbank_normalize_format(?string $format): string
{
    $format = trim((string) $format);

    if ($format === '') {
        return '';
    }

    $map = [
        'ar'               => 'AR',
        'assertion reason' => 'AR',

        'match'            => 'Match',
        'match the following' => 'Match',

        'mcq'              => 'MCQ',
        'multiple choice'  => 'MCQ',
        'multiple choice question' => 'MCQ',

        'vsa'              => 'VSA',
        'very short answer' => 'VSA',

        'paragraph'        => 'Paragraph Answer',
        'paragraph answer' => 'Paragraph Answer',

        'essay'            => 'Essay',
        'long answer'      => 'Essay',
    ];

    $key = strtolower($format);

    return $map[$key] ?? $format;
}


/**
 * Normalize Bloom level.
 */
function qbank_normalize_bloom(?string $bloom): string
{
    $bloom = strtoupper(trim((string) $bloom));

    if ($bloom === '') {
        return '';
    }

    $bloom = str_replace(
        [
            'BLOOM',
            ':',
            'LEVEL',
            ' ',
        ],
        '',
        $bloom
    );

    return $bloom;
}


/* =========================================================
   CSV PARSER
========================================================= */

/**
 * Detect CSV delimiter.
 */
function qbank_detect_csv_delimiter(string $path): string
{
    $sample = '';

    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return ',';
    }

    while (!feof($handle) && strlen($sample) < 10000) {
        $line = fgets($handle);

        if ($line === false) {
            break;
        }

        $sample .= $line;
    }

    fclose($handle);

    $delimiters = [
        ','  => substr_count($sample, ','),
        ';'  => substr_count($sample, ';'),
        "\t" => substr_count($sample, "\t"),
    ];

    arsort($delimiters);

    $delimiter = array_key_first($delimiters);

    return $delimiter ?: ',';
}


/**
 * Map CSV header aliases to database fields.
 */
function qbank_csv_header_map(): array
{
    return [
        'question no'      => 'question_no',
        'question number'  => 'question_no',
        'qno'              => 'question_no',
        'q no'             => 'question_no',

        'question'         => 'question_text',
        'question text'    => 'question_text',
        'question_text'    => 'question_text',

        'unit'             => 'unit',

        'format'           => 'question_format',
        'question format'  => 'question_format',
        'question_format'  => 'question_format',

        'bloom'            => 'bloom_level',
        'bloom level'      => 'bloom_level',
        'bloom_level'      => 'bloom_level',

        'marks'            => 'marks',
        'mark'             => 'marks',

        'option a'         => 'option_a',
        'option_a'         => 'option_a',

        'option b'         => 'option_b',
        'option_b'         => 'option_b',

        'option c'         => 'option_c',
        'option_c'         => 'option_c',

        'option d'         => 'option_d',
        'option_d'         => 'option_d',

        'answer'           => 'answer',
        'correct answer'   => 'answer',
    ];
}


/**
 * Parse CSV question bank.
 */
function qbank_parse_csv(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException(
            'CSV file was not found.'
        );
    }

    if (!is_readable($path)) {
        throw new RuntimeException(
            'CSV file cannot be read.'
        );
    }

    $handle = fopen($path, 'rb');

    if ($handle === false) {
        throw new RuntimeException(
            'Unable to open CSV file.'
        );
    }

    $delimiter = qbank_detect_csv_delimiter($path);

    $rawHeaders = fgetcsv(
        $handle,
        0,
        $delimiter
    );

    if ($rawHeaders === false) {
        fclose($handle);

        throw new RuntimeException(
            'CSV file is empty.'
        );
    }

    $headers = [];

    foreach ($rawHeaders as $index => $header) {
        $normalized = qbank_normalize_header(
            (string) $header
        );

        $headers[$index] = $normalized;
    }

    $headerMap = qbank_csv_header_map();

    $rows = [];

    $lineNumber = 1;

    $questionCounter = 1;

    $maxRows = defined('MAX_CSV_ROWS')
        ? (int) MAX_CSV_ROWS
        : 5000;

    while (!feof($handle)) {
        $lineNumber++;

        $data = fgetcsv(
            $handle,
            0,
            $delimiter
        );

        if ($data === false) {
            continue;
        }

        // Ignore completely empty rows.
        $hasValue = false;

        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                $hasValue = true;
                break;
            }
        }

        if (!$hasValue) {
            continue;
        }

        $row = [
            'question_no'     => '',
            'question_text'   => '',
            'unit'            => '',
            'question_format' => '',
            'bloom_level'     => '',
            'marks'           => '',
            'option_a'        => '',
            'option_b'        => '',
            'option_c'        => '',
            'option_d'        => '',
            'answer'          => '',
            '_source_line'    => $lineNumber,
            '_error'          => null,
        ];

        foreach ($data as $index => $value) {
            if (!isset($headers[$index])) {
                continue;
            }

            $header = $headers[$index];

            if ($header === '') {
                continue;
            }

            $field = $headerMap[$header] ?? null;

            if ($field === null) {
                continue;
            }

            $row[$field] = trim(
                qbank_remove_bom((string) $value)
            );
        }

        // Auto-number when question number is missing.
        if ($row['question_no'] === '') {
            $row['question_no'] = $questionCounter;
        }

        $questionCounter++;

        $row['question_format'] =
            qbank_normalize_format(
                $row['question_format']
            );

        $row['bloom_level'] =
            qbank_normalize_bloom(
                $row['bloom_level']
            );

        $rows[] = $row;

        if (count($rows) >= $maxRows) {
            break;
        }
    }

    fclose($handle);

    if (empty($rows)) {
        throw new RuntimeException(
            'No question rows were found in the CSV file.'
        );
    }

    return $rows;
}


/* =========================================================
   DOCX HELPERS
========================================================= */

/**
 * Extract text recursively from a PHPWord element.
 */
function qbank_docx_element_text($element): string
{
    if ($element === null) {
        return '';
    }

    /*
     * Simple text element.
     */
    if (
        method_exists($element, 'getText')
        && !method_exists($element, 'getElements')
    ) {
        try {
            return trim((string) $element->getText());
        } catch (Throwable $e) {
            return '';
        }
    }

    /*
     * TextRun, Cell, etc.
     */
    if (method_exists($element, 'getElements')) {
        $parts = [];

        try {
            foreach ($element->getElements() as $child) {
                $text = qbank_docx_element_text($child);

                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        } catch (Throwable $e) {
            return '';
        }

        return trim(
            implode(' ', $parts)
        );
    }

    /*
     * Table.
     */
    if (method_exists($element, 'getRows')) {
        $rows = [];

        try {
            foreach ($element->getRows() as $row) {
                $cells = [];

                if (!method_exists($row, 'getCells')) {
                    continue;
                }

                foreach ($row->getCells() as $cell) {
                    $cells[] =
                        qbank_docx_element_text($cell);
                }

                $rows[] = implode(
                    ' | ',
                    array_filter(
                        $cells,
                        static fn($value) =>
                            trim((string) $value) !== ''
                    )
                );
            }
        } catch (Throwable $e) {
            return '';
        }

        return implode(
            "\n",
            array_filter(
                $rows,
                static fn($value) =>
                    trim((string) $value) !== ''
            )
        );
    }

    return '';
}


/**
 * Extract readable lines from DOCX.
 */
function qbank_docx_lines(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException(
            'DOCX file was not found.'
        );
    }

    if (!is_readable($path)) {
        throw new RuntimeException(
            'DOCX file cannot be read.'
        );
    }

    /*
     * Composer may already have been loaded by upload.php.
     * If not, attempt to load it here.
     */
    if (
        !class_exists(
            \PhpOffice\PhpWord\IOFactory::class
        )
    ) {
        $autoload = dirname(__DIR__) .
            '/vendor/autoload.php';

        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    if (
        !class_exists(
            \PhpOffice\PhpWord\IOFactory::class
        )
    ) {
        throw new RuntimeException(
            'PHPWord is not installed or could not be loaded.'
        );
    }

    try {
        $document =
            \PhpOffice\PhpWord\IOFactory::load(
                $path
            );
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Unable to read DOCX file: ' .
            $e->getMessage()
        );
    }

    $lines = [];

    foreach ($document->getSections() as $section) {
        if (!method_exists($section, 'getElements')) {
            continue;
        }

        foreach ($section->getElements() as $element) {
            $text = qbank_docx_element_text(
                $element
            );

            if ($text === '') {
                continue;
            }

            $parts = preg_split(
                '/\R/u',
                $text
            );

            foreach ($parts as $part) {
                $part = trim((string) $part);

                if ($part !== '') {
                    $lines[] = $part;
                }
            }
        }
    }

    return $lines;
}


/**
 * Determine whether a DOCX line is document metadata/header.
 */
function qbank_docx_is_ignored_line(string $line): bool
{
    $trimmed = trim($line);

    if ($trimmed === '') {
        return true;
    }

    /*
     * Common paper headers.
     */
    $patterns = [
        '/^question\s+paper\b/i',
        '/^question\s+paper\s*[\|\-:]/i',

        '/^total\s+marks?\s*:/i',
        '/^date\s*:/i',
        '/^time\s*:/i',

        '/^section\s+[a-z0-9]+/i',
        '/^part\s+[a-z0-9]+/i',

        '/^answer\s+all/i',
        '/^answer\s+any/i',
        '/^choose\s+the/i',

        '/^instructions?\s*:/i',
        '/^instructions?\b/i',

        '/^course\s*:/i',
        '/^course\s+code\s*:/i',

        '/^subject\s*:/i',
        '/^semester\s*:/i',

        '/^page\s+\d+/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $trimmed)) {
            return true;
        }
    }

    /*
     * Lines such as:
     *
     * u26ds001 - Machine Learning
     */
    if (
        preg_match(
            '/^[A-Za-z0-9_-]{3,20}\s*[-–—:]\s*.+$/u',
            $trimmed
        )
        && !preg_match(
            '/^(UNIT|FORMAT|BLOOM|MARKS)\s*:/i',
            $trimmed
        )
    ) {
        return true;
    }

    return false;
}


/**
 * Determine whether a DOCX line is a metadata line.
 */
function qbank_docx_parse_metadata(
    string $line,
    array &$state
): bool {
    $line = trim($line);

    if (
        preg_match(
            '/^UNIT\s*:\s*(.+)$/i',
            $line,
            $match
        )
    ) {
        $state['unit'] = trim($match[1]);

        return true;
    }

    if (
        preg_match(
            '/^FORMAT\s*:\s*(.+)$/i',
            $line,
            $match
        )
    ) {
        $state['question_format'] =
            qbank_normalize_format($match[1]);

        return true;
    }

    if (
        preg_match(
            '/^BLOOM(?:\s+LEVEL)?\s*:\s*(.+)$/i',
            $line,
            $match
        )
    ) {
        $state['bloom_level'] =
            qbank_normalize_bloom($match[1]);

        return true;
    }

    if (
        preg_match(
            '/^MARKS?\s*:\s*([0-9]+(?:\.[0-9]+)?)$/i',
            $line,
            $match
        )
    ) {
        $state['marks'] =
            (float) $match[1];

        return true;
    }

    return false;
}


/**
 * Determine whether a line is an MCQ option.
 */
function qbank_docx_parse_option(
    string $line,
    array &$options
): bool {
    $line = trim($line);

    if (
        preg_match(
            '/^\(?([A-D])\)?[\.\):\-]\s*(.+)$/iu',
            $line,
            $match
        )
    ) {
        $letter = strtolower(
            $match[1]
        );

        $options[$letter] = trim(
            $match[2]
        );

        return true;
    }

    /*
     * Also support:
     *
     * (A) Red
     * (B) Blue
     */
    if (
        preg_match(
            '/^\(([A-D])\)\s*(.+)$/iu',
            $line,
            $match
        )
    ) {
        $letter = strtolower(
            $match[1]
        );

        $options[$letter] = trim(
            $match[2]
        );

        return true;
    }

    return false;
}


/**
 * Extract marks from the end of a question.
 */
function qbank_docx_extract_marks(
    string &$question
): ?float {
    $patterns = [
        '/\[\s*([0-9]+(?:\.[0-9]+)?)\s*marks?\s*\]\s*$/i',
        '/\(\s*([0-9]+(?:\.[0-9]+)?)\s*marks?\s*\)\s*$/i',
        '/-\s*([0-9]+(?:\.[0-9]+)?)\s*marks?\s*$/i',
    ];

    foreach ($patterns as $pattern) {
        if (
            preg_match(
                $pattern,
                $question,
                $match
            )
        ) {
            $question = trim(
                preg_replace(
                    $pattern,
                    '',
                    $question
                ) ?? $question
            );

            return (float) $match[1];
        }
    }

    return null;
}


/**
 * Determine whether a line looks like an unnumbered question.
 */
/**
 * Determine whether a DOCX line looks like a question.
 */
function qbank_docx_is_question_line(
    string $line
): bool {
    $line = trim($line);

    if ($line === '') {
        return false;
    }

    /*
     * Supported numbered formats:
     *
     * 1. Question
     * 1) Question
     * Q1. Question
     * Q1) Question
     * q1. Question
     */
    if (
        preg_match(
            '/^(?:Q\s*)?\d+\s*[\.\)]\s+.+/iu',
            $line
        )
    ) {
        return true;
    }

    /*
     * Common question starters for unnumbered questions.
     */
    $starters = [
        'what ',
        'why ',
        'how ',
        'which ',
        'when ',
        'where ',
        'who ',
        'define ',
        'explain ',
        'describe ',
        'discuss ',
        'compare ',
        'differentiate ',
        'distinguish ',
        'write ',
        'list ',
        'state ',
        'mention ',
        'give ',
        'identify ',
        'calculate ',
        'derive ',
        'illustrate ',
        'analyse ',
        'analyze ',
        'evaluate ',
        'justify ',
        'prove ',
        'match ',
        'assertion ',
    ];

    $lower = strtolower($line);

    foreach ($starters as $starter) {
        if (str_starts_with($lower, $starter)) {
            return true;
        }
    }

    /*
     * Question ending with ?.
     */
    if (str_ends_with($line, '?')) {
        return true;
    }

    return false;
}


/* =========================================================
   DOCX PARSER
========================================================= */

/**
 * Parse DOCX question bank.
 *
 * Supported:
 * - numbered questions
 * - unnumbered question-like lines
 * - UNIT:
 * - FORMAT:
 * - BLOOM:
 * - MARKS:
 * - [10 marks]
 * - MCQ options A-D
 */
function qbank_parse_docx(string $path): array
{
    $lines = qbank_docx_lines($path);

    if (empty($lines)) {
        throw new RuntimeException(
            'No readable text was found in the DOCX file.'
        );
    }

    $rows = [];

    $state = [
        'unit'            => '',
        'question_format' => '',
        'bloom_level'     => '',
        'marks'           => null,
    ];

    $current = null;

    $questionCounter = 1;

    $maxRows = defined('MAX_CSV_ROWS')
        ? (int) MAX_CSV_ROWS
        : 5000;


    /*
     * Save current question.
     */
    $flushCurrent = static function () use (
        &$current,
        &$rows
    ): void {
        if ($current === null) {
            return;
        }

        $text = trim(
            (string) ($current['question_text'] ?? '')
        );

        if ($text === '') {
            $current = null;

            return;
        }

        $current['question_text'] = $text;

        $current['option_a'] =
            $current['option_a'] ?? '';

        $current['option_b'] =
            $current['option_b'] ?? '';

        $current['option_c'] =
            $current['option_c'] ?? '';

        $current['option_d'] =
            $current['option_d'] ?? '';

        $current['answer'] =
            $current['answer'] ?? '';

        $current['_error'] =
            $current['_error'] ?? null;

        $rows[] = $current;

        $current = null;
    };


    foreach ($lines as $lineIndex => $rawLine) {
        $line = trim($rawLine);

        if ($line === '') {
            continue;
        }

        /*
         * Metadata.
         */
        if (
            qbank_docx_parse_metadata(
                $line,
                $state
            )
        ) {
            continue;
        }

        /*
         * Ignore document headers/section headings.
         */
        if (
            qbank_docx_is_ignored_line($line)
        ) {
            continue;
        }

        /*
         * MCQ option.
         */
        $options = [];

        if (
            qbank_docx_parse_option(
                $line,
                $options
            )
        ) {
            if ($current !== null) {
                foreach ($options as $letter => $value) {
                    $field = 'option_' . $letter;

                    $current[$field] = $value;
                }
            }

            continue;
        }


        /*
         * Numbered question.
         */
        $questionNo = null;
        $questionText = $line;

        if (
            preg_match(
                '/^(\d+)\s*[\.\)]\s*(.+)$/u',
                $line,
                $match
            )
        ) {
            $questionNo = (int) $match[1];

            $questionText = trim(
                $match[2]
            );
        }


        /*
         * New question.
         */
        if (
            $questionNo !== null
            || qbank_docx_is_question_line($line)
        ) {
            $flushCurrent();

            $marks = qbank_docx_extract_marks(
                $questionText
            );

            if ($questionNo === null) {
                $questionNo =
                    $questionCounter;
            }

            $questionCounter =
                max(
                    $questionCounter + 1,
                    $questionNo + 1
                );

            $current = [
                'question_no'     => $questionNo,
                'question_text'   => $questionText,
                'unit'            => $state['unit'],
                'question_format' =>
                    $state['question_format'],
                'bloom_level' =>
                    $state['bloom_level'],
                'marks'           =>
                    $marks !== null
                        ? $marks
                        : $state['marks'],
                'option_a'        => '',
                'option_b'        => '',
                'option_c'        => '',
                'option_d'        => '',
                'answer'          => '',
                '_source_line'    => $lineIndex + 1,
                '_error'          => null,
            ];

            /*
             * Marks are question-specific.
             */
            if ($marks !== null) {
                $state['marks'] = null;
            }

            continue;
        }


        /*
         * Continuation paragraph.
         */
        if ($current !== null) {
            $current['question_text'] .=
                ' ' . $line;
        }
    }

    $flushCurrent();


    if (empty($rows)) {
        throw new RuntimeException(
            'No questions were detected in the DOCX file.'
        );
    }

    return array_slice(
        $rows,
        0,
        $maxRows
    );
}


/**
 * Validate question rows.
 *
 * Compatible with upload.php:
 *
 * $validation = qbank_validate_rows(
 *     $pdo,
 *     $preview,
 *     $bankId
 * );
 *
 * Returns:
 *
 * [
 *     'rows'   => [...],
 *     'errors' => [...]
 * ]
 */
function qbank_validate_rows(...$args): array
{
    $rows = null;
    $pdo = null;
    $bankId = null;

    /*
     * Identify arguments.
     */
    foreach ($args as $arg) {

        /*
         * Question rows.
         */
        if (is_array($arg)) {

            /*
             * The question preview is the array containing
             * question rows.
             */
            if ($rows === null) {
                $rows = $arg;
            }

            continue;
        }

        /*
         * Database connection.
         */
        if ($arg instanceof PDO) {
            $pdo = $arg;
            continue;
        }

        /*
         * Question bank ID.
         */
        if (
            is_int($arg)
            || (
                is_string($arg)
                && ctype_digit($arg)
            )
        ) {
            if ($bankId === null) {
                $bankId = (int) $arg;
            }
        }
    }

    /*
     * Always return the structure expected by upload.php.
     */
    if ($rows === null) {
        return [
            'rows'   => [],
            'errors' => [],
        ];
    }

    $errors = [];

    $formats = qbank_allowed_formats();

    $seenQuestionNumbers = [];

    foreach ($rows as $index => &$row) {

        $rowErrors = [];

        $sourceLine =
            $row['_source_line']
            ?? ($index + 2);


        /* =====================================================
           QUESTION NUMBER
        ===================================================== */

        $questionNoRaw = trim(
            (string) (
                $row['question_no']
                ?? ''
            )
        );

        if ($questionNoRaw === '') {

            $questionNo =
                $index + 1;

            $row['question_no'] =
                $questionNo;

        } elseif (!ctype_digit($questionNoRaw)) {

            $rowErrors[] =
                'Question number must be a positive integer.';

        } else {

            $questionNo =
                (int) $questionNoRaw;

            if ($questionNo <= 0) {
                $rowErrors[] =
                    'Question number must be greater than zero.';
            }

            $row['question_no'] =
                $questionNo;
        }


        /*
         * Check duplicate question numbers inside
         * this uploaded file.
         */
        if (
            isset($questionNo)
            && $questionNo > 0
        ) {

            if (
                isset(
                    $seenQuestionNumbers[
                        $questionNo
                    ]
                )
            ) {

                $rowErrors[] =
                    'Duplicate question number in this upload.';

            } else {

                $seenQuestionNumbers[
                    $questionNo
                ] = true;
            }
        }


        /* =====================================================
           QUESTION TEXT
        ===================================================== */

        $questionText = trim(
            (string) (
                $row['question_text']
                ?? ''
            )
        );

        if ($questionText === '') {

            $rowErrors[] =
                'Question text is required.';

        } elseif (
            mb_strlen(
                $questionText,
                'UTF-8'
            ) < 3
        ) {

            $rowErrors[] =
                'Question text is too short.';
        }

        $row['question_text'] =
            $questionText;


        /* =====================================================
           UNIT
        ===================================================== */

        $unit = trim(
            (string) (
                $row['unit']
                ?? ''
            )
        );

        if ($unit === '') {

            $rowErrors[] =
                'Unit is required.';
        }

        $row['unit'] =
            $unit;


        /* =====================================================
           QUESTION FORMAT
        ===================================================== */

        $format =
            qbank_normalize_format(
                $row['question_format']
                ?? ''
            );

        if ($format === '') {

            $rowErrors[] =
                'Question format is required.';

        } elseif (
            !in_array(
                $format,
                $formats,
                true
            )
        ) {

            $rowErrors[] =
                'Invalid question format: ' .
                $format;
        }

        $row['question_format'] =
            $format;


        /* =====================================================
           BLOOM LEVEL
        ===================================================== */

        $bloom =
            qbank_normalize_bloom(
                $row['bloom_level']
                ?? ''
            );

        if ($bloom === '') {

            $rowErrors[] =
                'Bloom level is required.';

        } elseif (
            $format !== ''
            && in_array(
                $format,
                $formats,
                true
            )
            && !qbank_bloom_allowed(
                $format,
                $bloom
            )
        ) {

            $allowed =
                qbank_allowed_bloom(
                    $format
                );

            $rowErrors[] =
                'Bloom level ' .
                $bloom .
                ' is not allowed for ' .
                $format .
                '. Allowed: ' .
                implode(
                    ', ',
                    $allowed
                );
        }

        $row['bloom_level'] =
            $bloom;


        /* =====================================================
           MARKS
        ===================================================== */

        $marksRaw =
            $row['marks']
            ?? '';

        if (
            $marksRaw === ''
            || $marksRaw === null
        ) {

            /*
             * Use default marks when the format is known.
             */
            if ($format !== '') {

                $defaultMarks =
                    qbank_default_marks(
                        $format
                    );

                if ($defaultMarks > 0) {

                    $row['marks'] =
                        $defaultMarks;

                } else {

                    $rowErrors[] =
                        'Marks are required.';
                }

            } else {

                $rowErrors[] =
                    'Marks are required.';
            }

        } elseif (
            !is_numeric($marksRaw)
            || (float) $marksRaw <= 0
        ) {

            $rowErrors[] =
                'Marks must be a positive number.';

        } else {

            $row['marks'] =
                (float) $marksRaw;
        }


        /* =====================================================
           NORMALIZED TEXT
        ===================================================== */

        $normalized =
            qbank_normalize_text(
                $questionText
            );

        $hash =
            qbank_hash(
                $questionText
            );

        $row['normalized_text'] =
            $normalized;

        $row['question_hash'] =
            $hash;


        /* =====================================================
           DUPLICATE CHECK
        ===================================================== */

        if (
            $pdo instanceof PDO
            && $bankId !== null
            && $questionText !== ''
            && $normalized !== ''
        ) {

            try {

                $sql = "
                    SELECT
                        id,
                        question_no,
                        question_text,
                        unit,
                        question_format,
                        bloom_level,
                        marks
                    FROM questions
                    WHERE question_bank_id = :bank_id
                      AND (
                            question_hash = :question_hash
                            OR normalized_text = :normalized_text
                      )
                    LIMIT 1
                ";

                $stmt =
                    $pdo->prepare($sql);

                $stmt->execute([
                    ':bank_id' =>
                        $bankId,

                    ':question_hash' =>
                        $hash,

                    ':normalized_text' =>
                        $normalized,
                ]);

                $existing =
                    $stmt->fetch();

                if ($existing) {

                    $rowErrors[] =
                        'Question already exists in this question bank.'
                        . ' Existing question ID: '
                        . $existing['id']
                        . '.';
                }

            } catch (Throwable $e) {

                /*
                 * Duplicate-check failure should be logged,
                 * not silently converted into a PHP warning.
                 */
                error_log(
                    'Question duplicate check failed: ' .
                    $e->getMessage()
                );
            }
        }


        /* =====================================================
           ROW ERROR
        ===================================================== */

        if (!empty($rowErrors)) {

            $message =
                'Line ' .
                $sourceLine .
                ': ' .
                implode(
                    ' ',
                    $rowErrors
                );

            $row['_error'] =
                $message;

            $errors[] =
                $message;

        } else {

            $row['_error'] =
                null;
        }
    }

    unset($row);


    /* =========================================================
       RETURN STRUCTURE EXPECTED BY upload.php
    ========================================================= */

    return [
        'rows'   => $rows,
        'errors' => $errors,
    ];
}

/* =========================================================
   ERROR HELPERS
========================================================= */

/**
 * Return only rows containing validation errors.
 */
function qbank_rows_with_errors(
    array $rows
): array {
    return array_values(
        array_filter(
            $rows,
            static function (array $row): bool {
                return !empty(
                    $row['_error']
                );
            }
        )
    );
}


/**
 * Return validation error messages.
 */
function qbank_validation_errors(
    array $rows
): array {
    $errors = [];

    foreach ($rows as $row) {
        if (
            !empty($row['_error'])
        ) {
            $errors[] =
                (string) $row['_error'];
        }
    }

    return $errors;
}


/* =========================================================
   UPLOAD STORAGE HELPER
========================================================= */

/**
 * Save an uploaded question-bank file.
 *
 * Returns the final uploaded path.
 */
function qbank_save_upload(
    array $file,
    ?string $destination = null
): string {
    if (
        !isset(
            $file['error'],
            $file['tmp_name'],
            $file['name']
        )
    ) {
        throw new RuntimeException(
            'Invalid upload data.'
        );
    }

    if (
        (int) $file['error']
        !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            'File upload failed. Error code: ' .
            (int) $file['error']
        );
    }

    $tmpName =
        (string) $file['tmp_name'];

    if (!is_uploaded_file($tmpName)) {
        /*
         * Allows this helper to work with controlled
         * test uploads as well.
         */
        if (!is_file($tmpName)) {
            throw new RuntimeException(
                'Uploaded temporary file was not found.'
            );
        }
    }

    if ($destination === null) {
        if (
            defined('QUESTION_BANK_UPLOAD_DIR')
        ) {
            $destination =
                QUESTION_BANK_UPLOAD_DIR;
        } elseif (
            defined('UPLOAD_STORAGE_PATH')
        ) {
            $destination =
                UPLOAD_STORAGE_PATH;
        } else {
            $destination =
                dirname(__DIR__) .
                '/storage/uploads';
        }
    }

    if (!is_dir($destination)) {
        if (
            !mkdir(
                $destination,
                0775,
                true
            )
            && !is_dir($destination)
        ) {
            throw new RuntimeException(
                'Unable to create upload directory.'
            );
        }
    }

    $originalName =
        basename(
            (string) $file['name']
        );

    $extension =
        strtolower(
            pathinfo(
                $originalName,
                PATHINFO_EXTENSION
            )
        );

    $allowed =
        ['csv', 'docx'];

    if (
        !in_array(
            $extension,
            $allowed,
            true
        )
    ) {
        throw new RuntimeException(
            'Only CSV and DOCX files are supported.'
        );
    }

    $safeName =
        preg_replace(
            '/[^A-Za-z0-9._-]/',
            '_',
            pathinfo(
                $originalName,
                PATHINFO_FILENAME
            )
        );

    if (
        !is_string($safeName)
        || $safeName === ''
    ) {
        $safeName = 'question_bank';
    }

    $filename =
        $safeName .
        '_' .
        date('Ymd_His') .
        '_' .
        bin2hex(
            random_bytes(4)
        ) .
        '.' .
        $extension;

    $target =
        rtrim(
            $destination,
            DIRECTORY_SEPARATOR
        ) .
        DIRECTORY_SEPARATOR .
        $filename;

    if (
        !move_uploaded_file(
            $tmpName,
            $target
        )
    ) {
        /*
         * Fallback for controlled/test files.
         */
        if (
            !copy(
                $tmpName,
                $target
            )
        ) {
            throw new RuntimeException(
                'Unable to save uploaded file.'
            );
        }
    }

    return $target;
}


/* =========================================================
   FORMAT DETECTION
========================================================= */

/**
 * Determine file format from extension.
 */
function qbank_detect_file_type(
    string $path
): string {
    $extension =
        strtolower(
            pathinfo(
                $path,
                PATHINFO_EXTENSION
            )
        );

    return match ($extension) {
        'csv'  => 'csv',
        'docx' => 'docx',
        default => '',
    };
}


/**
 * Parse a question bank file automatically.
 */
function qbank_parse_file(
    string $path
): array {
    $type =
        qbank_detect_file_type(
            $path
        );

    return match ($type) {
        'csv' => qbank_parse_csv($path),
        'docx' => qbank_parse_docx($path),
        default => throw new RuntimeException(
            'Unsupported question bank file format.'
        ),
    };
}