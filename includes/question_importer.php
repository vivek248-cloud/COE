<?php
/**
 * Universal Multi-Format Question Bank Importer for Holy Cross College (Autonomous)
 * Supports English, Tamil (தமிழ் - Unicode & Legacy Bamini/Vanavil/TAM/TAB), and French (Français)
 * Extracts Questions, Units (1..5), Sub-Units (1.1..5.5), K-Levels, CO-Levels, Sections, Options, and Answer Keys
 * Handles DOCX, PDF, CSV, XLSX, JSON, ODT, TXT, Images, and ZIP archives.
 */
require_once __DIR__ . '/zip_helpers.php';

/**
 * Check if text contains legacy Tamil encoding (Bamini / Baamini / Vanavil)
 */
function qps_is_bamini(string $text): bool {
    if (empty($text) || (function_exists('mb_strlen') ? mb_strlen(trim($text), 'UTF-8') : strlen(trim($text))) < 6) return false;

    // If it already has real Tamil Unicode glyphs, skip
    if (preg_match_all('/[\x{0B80}-\x{0BFF}]/u', $text, $tm) && count($tm[0]) >= 5) {
        return false;
    }

    // If English dictionary / common examination words are present, this is strictly NOT Bamini!
    $enWords = preg_match_all('/\b(?:the|and|for|with|that|this|which|what|explain|define|describe|discuss|calculate|compare|differentiate|illustrate|evaluate|analyze|state|write|short|notes|question|answer|marks|unit|section|following|option|true|false|assertion|reason|match|between|english|french|tamil|course|syllabus|department|college)\b/iu', $text, $ewm);
    if ($enWords >= 2) {
        return false;
    }

    // Check specific, unambiguous multi-character Bamini Tamil words & syllables
    $baminiSyllables = preg_match_all('/(?:jhf;f|jdpj;j|tha;e|nkhop|njspq;|jkpo;|nrhw;|thf;fp|epiy|vd;g|vJ\?|ahj\?|rk];|fpU|rPdk|xyp|tbt|Fwpg;g|ghly;|tpis|fz;z|Gjpa|ngz;|ftpj|Muk;|fhg;g|rpWf|E}y;|gilg;|czh;|tho;t)/u', $text, $bm);
    if ($baminiSyllables >= 2) {
        return true;
    }

    // Unambiguous Bamini sequences with pulli markers in NON-ENGLISH context
    if (preg_match_all('/\b(?:[nN][fqrzjegkautowdsQ]h|[nN][fqrzjegkautowdsQ]|i[fqrzjegkautowdsQ])[a-z0-9]*[fqrlzjegkautowdsQ];/u', $text, $bm2)) {
        if (count($bm2[0]) >= 3) return true;
    }

    return false;
}

/**
 * Comprehensive Bamini / Baamini / Legacy Tamil Font to Unicode UTF-8 Converter
 */
function qps_bamini_to_unicode(string $text): string {
    if (empty($text)) return '';
    $t = $text;

    // 0. Pre-clean known clusters and common words
    $preReplacements = [
        'rk];' => 'சமஸ்',
        'ej;' => 'ந்த்',
        'jhf;f' => 'தாக்க',
        'yhj' => 'லாத',
        'jdpj;j' => 'தனித்த',
        'tha;e' => 'வாய்ந்',
        'nkhop' => 'மொழி',
        'njspq;' => 'தெலுங்',
        'njYq;' => 'தெலுங்',
        'jkpo;' => 'தமிழ்',
        'nrhw;' => 'சொற்',
        'thf;fp' => 'வாக்கி',
        'vJ?' => 'எது?'
    ];
    foreach ($preReplacements as $k => $v) {
        $t = str_replace($k, $v, $t);
    }

    // 1. Three-character compound replacements (two-part vowels and grantha)
    $pairs_3 = [
        'nfs' => 'கௌ', 'nqs' => 'ஙௌ', 'nrs' => 'சௌ', 'nQs' => 'ஞௌ', 'nls' => 'டௌ',
        'nzs' => 'ணௌ', 'njs' => 'தௌ', 'nes' => 'நௌ', 'ngs' => 'பௌ', 'nks' => 'மௌ',
        'nas' => 'யௌ', 'nus' => 'ரௌ', 'nys' => 'லௌ', 'nts' => 'வௌ', 'nos' => 'ழௌ',
        'nss' => 'ளௌ', 'nws' => 'றௌ', 'nds' => 'னௌ',

        'Nfh' => 'கோ', 'Nqh' => 'ஙோ', 'Nrh' => 'சோ', 'NQh' => 'ஞோ', 'Nlh' => 'டோ',
        'Nzh' => 'ணோ', 'Njh' => 'தோ', 'Neh' => 'நோ', 'Ngh' => 'போ', 'Nkh' => 'மோ',
        'Nah' => 'யோ', 'Nuh' => 'ரோ', 'Nyh' => 'லோ', 'Nth' => 'வோ', 'Noh' => 'ழோ',
        'Nsh' => 'ளோ', 'Nwh' => 'றோ', 'Ndh' => 'னோ',

        'nfh' => 'கொ', 'nqh' => 'ஙொ', 'nrh' => 'சொ', 'nQh' => 'ஞொ', 'nlh' => 'டொ',
        'nzh' => 'ணொ', 'njh' => 'தொ', 'neh' => 'நொ', 'ngh' => 'பொ', 'nkh' => 'மொ',
        'nah' => 'யொ', 'nuh' => 'ரொ', 'nyh' => 'லொ', 'nth' => 'வொ', 'noh' => 'ழொ',
        'nsh' => 'ளொ', 'nwh' => 'றொ', 'ndh' => 'னொ',

        '];' => 'ஸ்', '\\;' => 'க்ஷ', '\\' => 'க்ஷ'
    ];
    foreach ($pairs_3 as $k => $v) {
        $t = str_replace($k, $v, $t);
    }

    // 2. Two-character replacements (prefix vowels, pulli, u/uu modifiers, etc.)
    $pairs_2 = [
        'Nf' => 'கே', 'Nq' => 'ஙே', 'Nr' => 'சே', 'NQ' => 'ஞே', 'Nl' => 'டே',
        'Nz' => 'ணே', 'Nj' => 'தே', 'Ne' => 'நே', 'Ng' => 'பே', 'Nk' => 'மே',
        'Na' => 'யே', 'Nu' => 'ரே', 'Ny' => 'லே', 'Nt' => 'வே', 'No' => 'ழே',
        'Ns' => 'ளே', 'Nw' => 'றே', 'Nd' => 'னே',

        'nf' => 'கெ', 'nq' => 'ஙெ', 'nr' => 'செ', 'nQ' => 'ஞெ', 'nl' => 'டெ',
        'nz' => 'ணெ', 'nj' => 'தெ', 'ne' => 'நெ', 'ng' => 'பெ', 'nk' => 'மெ',
        'na' => 'யெ', 'nu' => 'ரெ', 'ny' => 'லெ', 'nt' => 'வெ', 'no' => 'ழெ',
        'ns' => 'ளெ', 'nw' => 'றெ', 'nd' => 'னெ',

        'if' => 'கை', 'iq' => 'ஙை', 'ir' => 'சை', 'iQ' => 'ஞை', 'il' => 'டை',
        'iz' => 'ணை', 'ij' => 'தை', 'ie' => 'நை', 'ig' => 'பை', 'ik' => 'மை',
        'ia' => 'யை', 'iu' => 'ரை', 'iy' => 'லை', 'it' => 'வை', 'io' => 'ழை',
        'is' => 'ளை', 'iw' => 'றை', 'id' => 'னை',

        'f;' => 'க்', 'q;' => 'ங்', 'r;' => 'ச்', 'Q;' => 'ஞ்', 'l;' => 'ட்',
        'z;' => 'ண்', 'j;' => 'த்', 'e;' => 'ந்', 'g;' => 'ப்', 'k;' => 'ம்',
        'a;' => 'ய்', 'u;' => 'ர்', 'y;' => 'ல்', 't;' => 'வ்', 'o;' => 'ழ்',
        's;' => 'ள்', 'w;' => 'ற்', 'd;' => 'ன்', 'h;' => 'ஹ்', '[;' => 'ஷ்',

        'fh' => 'கா', 'qh' => 'ஙா', 'rh' => 'சா', 'Qh' => 'ஞா', 'lh' => 'டா',
        'zh' => 'ணா', 'jh' => 'தா', 'eh' => 'நா', 'gh' => 'பா', 'kh' => 'மா',
        'ah' => 'யா', 'uh' => 'ரா', 'yh' => 'லா', 'th' => 'வா', 'oh' => 'ழா',
        'sh' => 'ளா', 'wh' => 'றா', 'dh' => 'னா',

        'fp' => 'கி', 'qp' => 'ஙி', 'rp' => 'சி', 'Qp' => 'ஞி', 'lp' => 'டி',
        'zp' => 'ணி', 'jp' => 'தி', 'ep' => 'நி', 'gp' => 'பி', 'kp' => 'மி',
        'ap' => 'யி', 'up' => 'ரி', 'yp' => 'லி', 'tp' => 'வி', 'op' => 'ழி',
        'sp' => 'ளி', 'wp' => 'றி', 'dp' => 'னி',

        'fP' => 'கீ', 'qP' => 'ஙீ', 'rP' => 'சீ', 'QP' => 'ஞீ', 'lP' => 'டீ',
        'zP' => 'ணீ', 'jP' => 'தீ', 'eP' => 'நீ', 'gP' => 'பீ', 'kP' => 'மீ',
        'aP' => 'யீ', 'uP' => 'ரீ', 'yP' => 'லீ', 'tP' => 'வீ', 'oP' => 'ழீ',
        'sP' => 'ளீ', 'wP' => 'றீ', 'dP' => 'னீ',

        'T+' => 'வூ', 'F+' => 'கூ', 'R+' => 'சூ', 'L+' => 'டூ', 'Z+' => 'ணூ',
        'J+' => 'தூ', 'E+' => 'நூ', 'G+' => 'பூ', 'K+' => 'மூ', 'A+' => 'யூ',
        'U+' => 'ரூ', 'Y+' => 'லூ', 'O+' => 'ழூ', 'S+' => 'ளூ', 'W+' => 'றூ', 'D+' => 'னூ',
        'E}' => 'நூல்', 'xs' => 'ஔ',

        'F' => 'கு', 'R' => 'சு', 'L' => 'டு', 'Z' => 'ணு', 'J' => 'து',
        'E' => 'நு', 'G' => 'பு', 'K' => 'மு', 'A' => 'யு', 'U' => 'ரு',
        'Y' => 'லு', 'T' => 'வு', 'O' => 'ழு', 'S' => 'ளு', 'W' => 'று', 'D' => 'னு'
    ];
    foreach ($pairs_2 as $k => $v) {
        $t = str_replace($k, $v, $t);
    }

    // 3. Single-character replacements
    $pairs_1 = [
        'f' => 'க', 'q' => 'ங', 'r' => 'ச', 'Q' => 'ஞ', 'l' => 'ட',
        'z' => 'ண', 'j' => 'த', 'e' => 'ந', 'g' => 'ப', 'k' => 'ம',
        'a' => 'ய', 'u' => 'ர', 'y' => 'ல', 't' => 'வ', 'o' => 'ழ',
        's' => 'ள', 'w' => 'ற', 'd' => 'ன',

        'm' => 'அ', 'M' => 'ஆ', 'c' => 'உ', 'C' => 'ஊ',
        'v' => 'எ', 'V' => 'ஏ', 'I' => 'ஐ', 'x' => 'ஒ', 'X' => 'ஓ',
        '<' => 'ஈ', ',' => 'இ',

        '~' => 'ஸ்ரீ', 'h' => 'ா', ';' => '்', '_' => 'ஹ', '[' => 'ஷ'
    ];
    foreach ($pairs_1 as $k => $v) {
        $t = str_replace($k, $v, $t);
    }

    return $t;
}

/**
 * Robust UTF-8 Normalization with BOM stripping and legacy encoding conversion
 */
function qps_utf8(string $text): string {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (substr($text, 0, 2) === "\xFF\xFE") {
        $text = function_exists('mb_convert_encoding') ? mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE') : $text;
    } elseif (substr($text, 0, 2) === "\xFE\xFF") {
        $text = function_exists('mb_convert_encoding') ? mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE') : $text;
    } elseif (function_exists('mb_detect_encoding')) {
        $enc = mb_detect_encoding($text, ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-15'], true);
        if ($enc && strtoupper($enc) !== 'UTF-8') {
            $text = mb_convert_encoding($text, 'UTF-8', $enc);
        }
    }
    if (qps_is_bamini($text)) {
        $text = qps_bamini_to_unicode($text);
    }
    return trim($text);
}

function qps_normalize_space(string $s): string {
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    if (qps_is_bamini($s)) {
        $s = qps_bamini_to_unicode($s);
    }
    $s = preg_replace('/[ \t]+/u', ' ', $s);
    $s = preg_replace('/\n{3,}/u', "\n\n", $s);
    return trim($s);
}

/**
 * Auto-detect language (English, Tamil, French)
 */
function qps_detect_language(string $text): string {
    if (trim($text) === '') return 'en';
    $text = qps_utf8($text);
    $letters = max(1, preg_match_all('/\p{L}/u', $text, $all));
    $tamil = preg_match_all('/[\x{0B80}-\x{0BFF}]/u', $text, $m);
    $devanagari = preg_match_all('/[\x{0900}-\x{097F}]/u', $text, $m);
    $latin = preg_match_all('/[A-Za-zÀ-ÖØ-öø-ÿ]/u', $text, $m);

    // Strong script signals. A script must dominate the sampled letters; this
    // prevents an English paper containing a Tamil header from being classified Tamil.
    if ($tamil >= 8 && ($tamil / $letters) >= 0.12) return 'ta';
    if ($devanagari >= 8 && ($devanagari / $letters) >= 0.12) return 'hi';

    $frAccents = preg_match_all('/[éèêëàâîïôùûçœæÉÈÊËÀÂÎÏÔÙÛÇ]/u', $text, $fm);
    $frKeywords = preg_match_all('/\b(?:dans|avec|pour|une|sont|les|des|cochez|soulignez|choisissez|traduisez|reliez|répondez|partie|unité|chapitre|exemple|français|question|exercice|texte|complétez|conjuguez|accordez|verbe|grammaire|bonjour|monsieur|madame|réponse)\b/iu', $text, $kwm);
    $enKeywords = preg_match_all('/\b(?:the|and|for|with|that|this|which|what|explain|define|describe|discuss|calculate|compare|differentiate|illustrate|evaluate|analyze|state|write|question|answer|marks|unit|section|following|option|true|false|assertion|reason|match|between|given|statement|data|structure|system|management)\b/iu', $text, $ewm);
    $hiKeywords = preg_match_all('/\b(?:और|का|की|के|में|से|पर|यह|वह|प्रश्न|उत्तर|अंक|इकाई|खंड|निम्नलिखित|सही|गलत|कौन|क्या|समझाइए|वर्णन|तुलना)\b/u', $text, $hkw);

    if (($frAccents + $frKeywords) >= 4 && ($frAccents + $frKeywords) > ($enKeywords + 1)) return 'fr';
    if ($hiKeywords >= 2) return 'hi';
    if ($enKeywords >= 2 || $latin >= 20) return 'en';
    if (qps_is_bamini($text)) return 'ta';
    return 'en';
}

function qps_header_key(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_');
}

function qps_header_pick(array $row, array $aliases, $default = '') {
    $norm = [];
    foreach ($row as $k => $v) $norm[qps_header_key((string)$k)] = $v;
    foreach ($aliases as $a) {
        $k = qps_header_key($a);
        if (array_key_exists($k, $norm) && trim((string)$norm[$k]) !== '') return trim((string)$norm[$k]);
    }
    return $default;
}

function qps_clean_question_text(string $text): string {
    $text = qps_utf8($text);
    if (qps_is_bamini($text)) {
        $text = qps_bamini_to_unicode($text);
    }
    $text = preg_replace('/^\s*(?:Q(?:uestion)?\s*|வினா\s*|Question\s*)?\d+\s*[\.\):\-]\s*/iu', '', $text);
    return trim($text);
}

function qps_extract_formula_from_text(string $text): string {
    if (preg_match_all('/\$\$(.+?)\$\$|\$(.+?)\$/us', $text, $m)) {
        $parts = [];
        foreach ($m[0] as $i => $full) {
            $parts[] = trim($m[1][$i] !== '' ? $m[1][$i] : $m[2][$i]);
        }
        return implode(' ; ', array_filter($parts));
    }
    return '';
}

function qps_has_formula(string $text): bool {
    return qps_extract_formula_from_text($text) !== '' ||
        preg_match('/[√∑∫∞≤≥≠≈±×÷∂∆∇∈∉∩∪]|[⁰¹²³⁴⁵⁶⁷⁸⁹₀₁₂₃₄₅₆₇₈₉]|\\\\frac|\\\\sqrt|(?:[A-Za-z0-9])(?:\^|_)[A-Za-z0-9]/u', $text);
}

/**
 * Extract answer key from text or options
 */
function qps_extract_answer_key(string &$text): string {
    $key = '';
    
    // 1. Parenthesized Tamil Answer format (Matching image-3.png): (விடை: இ) or (விடை : அ)
    if (preg_match('/\(\s*(?:விடை|விடைக்குறிப்பு|சரியான\s*விடை)\s*[:：\-]?\s*([^\)]+)\)/u', $text, $tm)) {
        $key = trim($tm[1]);
        $text = preg_replace('/\(\s*(?:விடை|விடைக்குறிப்பு|சரியான\s*விடை)\s*[:：\-]?\s*[^\)]+\)/u', '', $text);
        $text = trim($text);
        return $key;
    }

    // 2. Parenthesized English Answer format: (Key: A) or (Ans: B) or (Answer: C)
    if (preg_match('/\(\s*(?:Key|Ans|Answer|Solution)\s*[:：\-]?\s*([^\)]+)\)/iu', $text, $em)) {
        $key = trim($em[1]);
        $text = preg_replace('/\(\s*(?:Key|Ans|Answer|Solution)\s*[:：\-]?\s*[^\)]+\)/iu', '', $text);
        $text = trim($text);
        return $key;
    }

    // 3. Line-based formats: Answer: (a) ..., Key: B, Ans: C, விடை: ..., Réponse: ...
    if (preg_match('/(?:^|\n)\s*(?:Answer(?:\s*Key)?|Ans|Key|Correct(?:\s*Option)?|Solution|விடை|சரியான\s*விடை|விடைக்குறிப்பு|Réponse|Corrigé|Clé)\s*[:：\-]\s*([^\r\n]+)/iu', $text, $m)) {
        $key = trim($m[1]);
        $text = preg_replace('/(?:^|\n)\s*(?:Answer(?:\s*Key)?|Ans|Key|Correct(?:\s*Option)?|Solution|விடை|சரியான\s*விடை|விடைக்குறிப்பு|Réponse|Corrigé|Clé)\s*[:：\-]\s*[^\r\n]+/iu', '', $text);
        $text = trim($text);
        return $key;
    }

    return $key;
}

/**
 * Extract options (A, B, C, D) from question body or text
 */
function qps_extract_options_from_text(string $text): array {
    $options = [];
    if (preg_match_all('/(?:\([a-eA-Eஅஆஇஈ]\)|[a-eA-Eஅஆஇஈ]\.|\b[a-eA-E]\))\s+([^\(\n\r]+)/u', $text, $m)) {
        foreach ($m[0] as $opt) {
            $options[] = trim($opt);
        }
    }
    return $options;
}

/**
 * Helper to get Roman numeral to integer
 */
function qps_roman_to_int(string $roman): int {
    $roman = strtoupper(trim($roman));
    $map = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6];
    return $map[$roman] ?? 1;
}

/**
 * Process question dictionary with default values & OBE alignment
 */
function qps_question_defaults(array $q, int $number): array {
    $rawText = qps_normalize_space((string)($q['question_text'] ?? $q['text'] ?? ''));
    if (qps_is_bamini($rawText)) {
        $rawText = qps_bamini_to_unicode($rawText);
    }
    
    // Extract answer key if present in text or provided separately
    $answerKey = (string)($q['answer_key'] ?? $q['answer'] ?? $q['key'] ?? '');
    if ($answerKey === '') {
        $answerKey = qps_extract_answer_key($rawText);
    }
    
    $text = $rawText;

    // Detect language
    $lang = (string)($q['language'] ?? qps_detect_language($text));

    // Detect Unit: 1..5
    $unit = (int)($q['unit_no'] ?? $q['unit'] ?? 0);
    if ($unit < 1 || $unit > 5) {
        if (preg_match('/(?:UNIT|Unit|ALAGU|அலகு|Unité|Module)\s*[-:]?\s*([1-5]|I|II|III|IV|V)\b/iu', $text, $um)) {
            $unit = is_numeric($um[1]) ? (int)$um[1] : qps_roman_to_int($um[1]);
        }
    }
    if ($unit < 1 || $unit > 5) $unit = max(1, min(5, (int)ceil($number / 6)));

    // Sub-unit: 1.1..5.5
    $subUnit = (string)($q['sub_unit'] ?? $q['subunit'] ?? '');
    if ($subUnit === '' || !preg_match('/^[1-5]\.[1-5]$/', $subUnit)) {
        $subIdx = (($number - 1) % 5) + 1;
        $subUnit = "{$unit}.{$subIdx}";
    }

    // Cognitive K-Level: K1..K5
    $kLevel = strtoupper(trim((string)($q['k_level'] ?? $q['klevel'] ?? $q['bloom_level'] ?? '')));
    if (!preg_match('/^K[1-6]$/', $kLevel)) {
        // Auto infer from question keywords
        if (preg_match('/\b(?:evaluate|justify|critique|மதிப்பிடுக|évaluez)\b/iu', $text)) $kLevel = 'K5';
        elseif (preg_match('/\b(?:design|develop|create|formulate|வடிவமைக்க|créez)\b/iu', $text)) $kLevel = 'K6';
        elseif (preg_match('/\b(?:analyze|analyse|compare|contrast|பகுப்பாய்வு|différencier|analysez)\b/iu', $text)) $kLevel = 'K4';
        elseif (preg_match('/\b(?:apply|calculate|solve|demonstrate|பயன்படுத்துக|கணக்கிடுக|calculez|appliquez)\b/iu', $text)) $kLevel = 'K3';
        elseif (preg_match('/\b(?:explain|describe|discuss|விளக்குக|விவரிக்க|expliquez|décrivez)\b/iu', $text)) $kLevel = 'K2';
        else $kLevel = 'K1';
    }

    // Course Outcome CO level (Always strictly mapped to K-Level)
    $kNum = preg_replace('/[^0-9]/', '', $kLevel);
    $coNum = ($kNum !== '') ? $kNum : '1';
    $coLevel = 'CO' . max(1, (int)$coNum);

    // Marks
    $marks = (int)($q['marks'] ?? $q['mark'] ?? 0);
    if ($marks <= 0) {
        $marks = 1;
        if (preg_match('/\[(\d+)\s*(?:M|Marks|Marks)\]|\((\d+)\s*(?:M|Marks)\)/i', $text, $mm)) {
            $marks = (int)($mm[1] ?: $mm[2]);
        }
    }

    // Section Type
    $sec = strtoupper(trim((string)($q['section_type'] ?? $q['section'] ?? '')));
    if ($sec === '' || !preg_match('/^SECTION-[A-D]$/', $sec)) {
        if ($marks >= 8) $sec = 'SECTION-C';
        elseif ($marks >= 4) $sec = 'SECTION-B';
        elseif ($marks === 2) $sec = 'SECTION-A';
        else $sec = 'SECTION-A';
    }

    // Formula & options
    $hasFormula = (int)($q['has_formula'] ?? (qps_has_formula($text) ? 1 : 0));
    $formulaLatex = (string)($q['formula_latex'] ?? qps_extract_formula_from_text($text));
    $options = $q['options'] ?? qps_extract_options_from_text($text);

    return [
        'q_number' => $number,
        'unit_no' => $unit,
        'sub_unit' => $subUnit,
        'question_text' => qps_clean_question_text($text),
        'k_level' => $kLevel,
        'co_level' => $coLevel,
        'marks' => $marks,
        'section_type' => $sec,
        'image_url' => (string)($q['image_url'] ?? $q['image'] ?? ''),
        'has_formula' => $hasFormula,
        'formula_latex' => $formulaLatex,
        'answer_key' => $answerKey,
        'language' => $lang,
        'options' => $options
    ];
}

/**
 * Convert raw tabular rows into clean questions
 */
function qps_rows_to_questions(array $rows): array {
    $out = [];
    $num = 1;

    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $qText = qps_header_pick($r, ['question_text', 'question', 'q_text', 'questions', 'problem', 'description', 'vina', 'प्रश्न', 'प्रश्न पाठ', 'विनाअर्थ', 'வினா']);
        if ($qText === '') continue;

        $unit = qps_header_pick($r, ['unit_no', 'unit', 'alagu', 'इकाई', 'यूनिट', 'அலகு', 'unite', 'module'], '');
        $subUnit = qps_header_pick($r, ['sub_unit', 'subunit', 'sub_topic', 'subtopic', 'sub', 'उप-इकाई', 'उप इकाई'], '');
        $kLevel = qps_header_pick($r, ['k_level', 'klevel', 'bloom', 'bloom_level', 'cognitive_level', 'के-स्तर', 'के स्तर'], '');
        $marks = qps_header_pick($r, ['marks', 'mark', 'total_marks', 'अंक', 'मूल्यांकन अंक', 'மதிப்பெண்'], '1');
        $sec = qps_header_pick($r, ['section_type', 'section', 'part', 'सेक्शन', 'खंड', 'भाग', 'பகுதி'], '');
        $answer = qps_header_pick($r, ['answer_key', 'answer', 'key', 'ans', 'solution', 'उत्तर कुंजी', 'उत्तर', 'कुंजी', 'விடை'], '');
        $imageUrl = qps_header_pick($r, ['image_url', 'image', 'img', 'photo'], '');

        $rowDict = [
            'question_text' => $qText,
            'unit_no' => (int)$unit,
            'sub_unit' => $subUnit,
            'k_level' => $kLevel,
            'marks' => (int)$marks,
            'section_type' => $sec,
            'answer_key' => $answer,
            'image_url' => $imageUrl
        ];

        $out[] = qps_question_defaults($rowDict, $num++);
    }

    return $out;
}

/**
 * CSV Parser
 */
function qps_parse_csv(string $path): array {
    $handle = @fopen($path, 'r');
    if (!$handle) throw new RuntimeException('Cannot open CSV file: ' . $path);

    $rows = [];
    $headers = null;
    while (($data = fgetcsv($handle, 4096, ',')) !== false) {
        if (!$headers) {
            $headers = array_map('trim', $data);
            continue;
        }
        $assoc = [];
        foreach ($headers as $i => $h) {
            $assoc[$h] = $data[$i] ?? '';
        }
        $rows[] = $assoc;
    }
    fclose($handle);
    return qps_rows_to_questions($rows);
}

/**
 * JSON Parser
 */
function qps_parse_json_file(string $path): array {
    $raw = @file_get_contents($path);
    if ($raw === false) throw new RuntimeException('Cannot read JSON file: ' . $path);
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Invalid JSON question bank format.');

    $list = $data['questions'] ?? (is_array(reset($data)) ? $data : [$data]);
    return qps_rows_to_questions($list);
}

function qps_docx_is_noise(string $text): bool {
    $t = qps_normalize_space($text);
    if ($t === '') return true;
    $patterns = [
        '/^(?:Assertion\s*&\s*Reasoning|HOLY CROSS|SCHOOL OF|DEPARTMENT OF|COURSE CODE|QUESTION BANK|SEMESTER|DURATION|MAX MARKS|PROGRAMME|TIME:)/iu',
        '/^(?:PART\s*[-–—]\s*[A-D]|SECTION\s*[-–—]\s*[A-D]|பகுதி\s*[-–—]\s*[A-Dஅஆஇஈ]|ALAGU|அலகு\s*[1-5I-V])/iu',
        '/^Answer\s+(?:all|any)\s+questions/iu',
        '/^\(Q\.Nos?\.\s*\d+\s*(?:to|-)\s*\d+\)/iu'
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $t)) return true;
    }
    return false;
}

function qps_omml_latex(DOMNode $node): string {
    $text = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            if ($child->localName === 't') {
                $text .= $child->textContent;
            } elseif ($child->localName === 'f') { // fraction
                $num = ''; $den = '';
                foreach ($child->childNodes as $fc) {
                    if ($fc->localName === 'num') $num = qps_omml_latex($fc);
                    if ($fc->localName === 'den') $den = qps_omml_latex($fc);
                }
                $text .= "\\frac{{$num}}{{{$den}}}";
            } elseif ($child->localName === 'rad') { // radical/sqrt
                $deg = ''; $base = '';
                foreach ($child->childNodes as $rc) {
                    if ($rc->localName === 'deg') $deg = qps_omml_latex($rc);
                    if ($rc->localName === 'e') $base = qps_omml_latex($rc);
                }
                $text .= ($deg !== '') ? "\\sqrt[{$deg}]{{{$base}}}" : "\\sqrt{{$base}}";
            } elseif ($child->localName === 'sSup') { // superscript
                $e = ''; $sup = '';
                foreach ($child->childNodes as $sc) {
                    if ($sc->localName === 'e') $e = qps_omml_latex($sc);
                    if ($sc->localName === 'sup') $sup = qps_omml_latex($sc);
                }
                $text .= "{$e}^{{$sup}}";
            } elseif ($child->localName === 'sSub') { // subscript
                $e = ''; $sub = '';
                foreach ($child->childNodes as $sc) {
                    if ($sc->localName === 'e') $e = qps_omml_latex($sc);
                    if ($sc->localName === 'sub') $sub = qps_omml_latex($sc);
                }
                $text .= "{$e}_{{$sub}}";
            } else {
                $text .= qps_omml_latex($child);
            }
        }
    }
    return $text;
}

function qps_dom_children_text(DOMNode $node): string {
    $t = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $t .= $child->nodeValue;
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            if ($child->localName === 't') {
                $t .= $child->textContent;
            } elseif ($child->localName === 'tab') {
                $t .= ' ';
            } elseif ($child->localName === 'br') {
                $t .= "\n";
            } else {
                $t .= qps_dom_children_text($child);
            }
        }
    }
    return $t;
}

/**
 * Robust DOCX Parser extracting questions, units, sub-units, answer keys, and options
 */
function qps_docx_parse(string $path, array $sectionMarks = []): array {
    // 1. Try powerful python-docx extractor first
    $pyScript = __DIR__ . '/docx_extractor.py';
    if (file_exists($pyScript)) {
        $output = []; $ret = -1;
        $marksArg = escapeshellarg(json_encode($sectionMarks, JSON_UNESCAPED_UNICODE));
        $pythonCandidates = [getenv('QPS_PYTHON') ?: 'python', 'python3'];
        foreach ($pythonCandidates as $py) {
            $output = []; $ret = -1;
            $cmd = $py . ' ' . escapeshellarg($pyScript) . ' ' . escapeshellarg($path) . ' --section_marks ' . $marksArg;
            @exec($cmd . ' 2>&1', $output, $ret);
            if ($ret === 0 && !empty($output)) break;
        }
        if ($ret === 0 && !empty($output)) {
            $jsonStr = implode("\n", $output);
            $parsed = json_decode($jsonStr, true);
            if (is_array($parsed) && !empty($parsed['success']) && !empty($parsed['questions'])) {
                return $parsed['questions'];
            }
        }
    }

    // 2. Fallback to native PHP DOMDocument parsing
    $zip = qps_zip_entries($path);
    $xml = qps_zip_read_entry($zip, 'word/document.xml');
    if ($xml === null) throw new RuntimeException('DOCX document.xml not found.');

    $relsXml = qps_zip_read_entry($zip, 'word/_rels/document.xml.rels');
    $rels = [];
    if ($relsXml !== null && class_exists('DOMDocument')) {
        $rdom = new DOMDocument();
        @$rdom->loadXML($relsXml);
        foreach ($rdom->documentElement->childNodes as $r) {
            if ($r->nodeType === XML_ELEMENT_NODE && $r->localName === 'Relationship') {
                $rels[$r->getAttribute('Id')] = $r->getAttribute('Target');
            }
        }
    }

    $dom = new DOMDocument();
    @$dom->loadXML($xml);
    $paras = [];
    $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    foreach ($dom->getElementsByTagNameNS($W, 'p') as $p) {
        $text = '';
        $images = [];
        $numId = null;

        foreach ($p->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            if ($child->localName === 'pPr') {
                foreach ($child->childNodes as $pp) {
                    if ($pp->nodeType === XML_ELEMENT_NODE && $pp->localName === 'numPr') {
                        foreach ($pp->childNodes as $nn) {
                            if ($nn->nodeType === XML_ELEMENT_NODE && $nn->localName === 'numId' && $nn->hasAttributeNS($W, 'val')) {
                                $numId = (int)$nn->getAttributeNS($W, 'val');
                            }
                        }
                    }
                }
                continue;
            }
            if ($child->localName === 'r') {
                $hasMath = false;
                foreach ($child->childNodes as $cc) {
                    if ($cc->localName === 'oMath') {
                        $hasMath = true;
                        $text .= '$' . qps_omml_latex($cc) . '$';
                    }
                }
                if (!$hasMath) $text .= qps_dom_children_text($child);
            } elseif ($child->localName === 'oMath' || $child->localName === 'oMathPara') {
                $latex = qps_omml_latex($child);
                if ($latex !== '') $text .= '$' . $latex . '$';
            } else {
                $text .= qps_dom_children_text($child);
            }

            // Extract embedded images
            $xp = $child->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'blip');
            foreach ($xp as $blip) {
                $rid = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
                if (isset($rels[$rid])) {
                    $target = ltrim($rels[$rid], '/');
                    if (strpos($target, 'word/') !== 0) $target = 'word/' . $target;
                    $bytes = qps_zip_read_entry($zip, $target);
                    if ($bytes !== null && strlen($bytes) <= 2097152) {
                        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
                        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp'][$ext] ?? 'application/octet-stream';
                        $images[] = 'data:' . $mime . ';base64,' . base64_encode($bytes);
                    }
                }
            }
        }
        $text = qps_normalize_space($text);
        if ($text !== '' || $images) {
            $paras[] = ['text' => $text, 'image_url' => $images[0] ?? '', 'num_id' => $numId];
        }
    }

    return qps_paras_to_smart_questions($paras);
}

/**
 * Parse text paragraphs / blocks with stateful tracking of Unit, Sub-unit, Section, and Answer Keys
 */
function qps_paras_to_smart_questions(array $paras): array {
    $questions = [];
    $current = null;
    $activeUnit = 1;
    $activeSubUnit = '1.1';
    $activeSection = 'SECTION-A';
    $itemCountInUnit = 0;

    $flush = function() use (&$questions, &$current, &$activeUnit, &$activeSubUnit, &$activeSection) {
        if ($current !== null) {
            $t = qps_normalize_space((string)($current['question_text'] ?? ''));
            if ($t !== '') {
                $current['question_text'] = $t;
                $current['unit_no'] = $current['unit_no'] ?? $activeUnit;
                $current['sub_unit'] = $current['sub_unit'] ?? $activeSubUnit;
                $current['section_type'] = $current['section_type'] ?? $activeSection;
                $questions[] = $current;
            }
        }
        $current = null;
    };

    foreach ($paras as $block) {
        $line = qps_normalize_space((string)($block['text'] ?? ''));
        if ($line === '') continue;
        if (qps_docx_is_noise($line)) continue;

        // Check if line defines a new Unit
        if (preg_match('/^(?:UNIT|Unit|ALAGU|அலகு|Unité|Module)\s*[-:–—\s]*([1-5]|I|II|III|IV|V)\b/iu', $line, $um)) {
            $flush();
            $activeUnit = is_numeric($um[1]) ? (int)$um[1] : qps_roman_to_int($um[1]);
            $itemCountInUnit = 0;
            $activeSubUnit = "{$activeUnit}.1";
            continue;
        }

        // Check if line defines a new Section
        if (preg_match('/^(?:SECTION|Section|PART|Part|பகுதி|Partie)\s*[-:–—\s]*([A-Dஅஆஇஈ]|I|II|III|IV)/iu', $line, $sm)) {
            $flush();
            $sCode = strtoupper($sm[1]);
            if ($sCode === 'I' || $sCode === '1' || $sCode === 'அ') $sCode = 'A';
            if ($sCode === 'II' || $sCode === '2' || $sCode === 'ஆ') $sCode = 'B';
            if ($sCode === 'III' || $sCode === '3' || $sCode === 'இ') $sCode = 'C';
            if ($sCode === 'IV' || $sCode === '4' || $sCode === 'ஈ') $sCode = 'D';
            $activeSection = 'SECTION-' . $sCode;
            continue;
        }

        // Check if line defines explicit Sub-Unit (e.g. 1.1, 1.2, 2.3)
        if (preg_match('/^([1-5]\.[1-5])\s*[-–—:]\s*(.*)$/u', $line, $sm)) {
            $activeSubUnit = $sm[1];
            $activeUnit = (int)substr($activeSubUnit, 0, 1);
            $line = trim($sm[2]);
            if ($line === '') continue;
        }

        // Check if line starts a new question (e.g. 1. , 1), Q1., வினா 1:, 1.1:)
        if (preg_match('/^(?:(?:Q(?:uestion)?\s*|வினா\s*|Question\s*)?(\d+)[\.\):\-]|([1-5]\.[1-5])[\.\):\-]?)\s*(.*)$/iu', $line, $qm)) {
            $flush();
            $body = trim($qm[3] !== '' ? $qm[3] : $qm[0]);
            if (!empty($qm[2])) {
                $activeSubUnit = $qm[2];
                $activeUnit = (int)substr($activeSubUnit, 0, 1);
            }
            $itemCountInUnit++;
            $subIdx = (($itemCountInUnit - 1) % 5) + 1;
            $subU = !empty($qm[2]) ? $qm[2] : "{$activeUnit}.{$subIdx}";

            $current = [
                'question_text' => $body,
                'image_url' => (string)($block['image_url'] ?? ''),
                'unit_no' => $activeUnit,
                'sub_unit' => $subU,
                'section_type' => $activeSection
            ];
            continue;
        }

        // Check if line is an answer key
        if (preg_match('/^(?:Answer|Key|Ans|Solution|விடை|சரியான\s*விடை|Réponse|Corrigé)\s*[:：\-]\s*(.*)$/iu', $line, $akm)) {
            if ($current !== null) {
                $current['answer_key'] = trim($akm[1]);
            }
            continue;
        }

        // Append line to current question (e.g. options or multi-line problem)
        if ($current !== null) {
            $current['question_text'] .= "\n" . $line;
            if (empty($current['image_url']) && !empty($block['image_url'])) {
                $current['image_url'] = $block['image_url'];
            }
        } else {
            // Unnumbered first line question
            $itemCountInUnit++;
            $subIdx = (($itemCountInUnit - 1) % 5) + 1;
            $current = [
                'question_text' => $line,
                'image_url' => (string)($block['image_url'] ?? ''),
                'unit_no' => $activeUnit,
                'sub_unit' => "{$activeUnit}.{$subIdx}",
                'section_type' => $activeSection
            ];
        }
    }
    $flush();

    $out = [];
    $i = 1;
    foreach ($questions as $q) {
        $out[] = qps_question_defaults($q, $i++);
    }
    return $out;
}

function qps_blocks_to_questions(array $blocks): array {
    return qps_paras_to_smart_questions($blocks);
}

function qps_txt_parse(string $path): array {
    $text = qps_utf8((string)file_get_contents($path));
    $lines = preg_split('/\R/u', $text);
    $blocks = [];
    foreach ($lines as $line) $blocks[] = ['text' => $line, 'image_url' => ''];
    return qps_blocks_to_questions($blocks);
}

function qps_odt_parse(string $path): array {
    $zip = qps_zip_entries($path);
    $xml = qps_zip_read_entry($zip, 'content.xml');
    if ($xml === null) throw new RuntimeException('ODT content.xml not found.');
    $dom = new DOMDocument();
    @$dom->loadXML($xml);
    $paras = [];
    foreach ($dom->getElementsByTagNameNS('urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'p') as $p) {
        $text = qps_normalize_space(qps_dom_children_text($p));
        if ($text !== '') $paras[] = ['text' => $text, 'image_url' => ''];
    }
    return qps_blocks_to_questions($paras);
}

function qps_xlsx_rows(string $path): array {
    $zip = qps_zip_entries($path);
    $shared = [];
    $ss = qps_zip_read_entry($zip, 'xl/sharedStrings.xml');
    if ($ss !== null) {
        preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is', $ss, $sm);
        foreach ($sm[1] as $si) {
            preg_match_all('/<t\b[^>]*>(.*?)<\/t>/is', $si, $tm);
            $shared[] = html_entity_decode(implode('', $tm[1] ?? []), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    $sheetName = null;
    foreach (qps_zip_list($zip) as $name) {
        if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
            $sheetName = $name;
            break;
        }
    }
    if (!$sheetName) throw new RuntimeException('No worksheet found in XLSX.');
    $xml = qps_zip_read_entry($zip, $sheetName);
    if (!$xml) throw new RuntimeException('Cannot read XLSX worksheet.');

    preg_match_all('/<row\b[^>]*>(.*?)<\/row>/is', $xml, $rm);
    $rawRows = [];
    foreach ($rm[1] as $r) {
        preg_match_all('/<c\b[^>]*?(?:t="([^"]*)")?[^>]*>(?:<v>(.*?)<\/v>)?<\/c>/is', $r, $cm);
        $row = [];
        foreach ($cm[2] as $idx => $val) {
            $type = $cm[1][$idx] ?? '';
            $v = $val;
            if ($type === 's' && isset($shared[(int)$val])) $v = $shared[(int)$val];
            $row[] = qps_utf8((string)$v);
        }
        if (array_filter($row)) $rawRows[] = $row;
    }
    if (empty($rawRows)) return [];

    $headers = array_shift($rawRows);
    $assoc = [];
    foreach ($rawRows as $r) {
        $row = [];
        foreach ($headers as $i => $h) $row[$h] = $r[$i] ?? '';
        $assoc[] = $row;
    }
    return qps_rows_to_questions($assoc);
}

function qps_pdf_text(string $path): string {
    if (!function_exists('exec')) return '';
    $cmd = getenv('QPS_PDFTOTEXT') ?: 'pdftotext';
    $out = [];
    $code = 0;
    exec($cmd . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>&1', $out, $code);
    if ($code !== 0) return '';
    return qps_utf8(implode("\n", $out));
}

function qps_tesseract_text(string $path, string $lang = 'eng', int $psm = 6): string {
    if (!function_exists('exec')) throw new RuntimeException('PHP exec() is disabled. Enable it for OCR/PDF extraction.');
    $cmd = getenv('QPS_TESSERACT') ?: 'tesseract';
    $out = [];
    $code = 0;
    exec($cmd . ' ' . escapeshellarg($path) . ' stdout -l ' . escapeshellarg($lang) . ' --psm ' . (int)$psm . ' 2>&1', $out, $code);
    if ($code !== 0) throw new RuntimeException('Tesseract OCR failed. Check the selected language pack (' . $lang . ').');
    return qps_utf8(implode("\n", $out));
}

function qps_tesseract_best(string $path, string $lang = 'eng'): string {
    $first = qps_tesseract_text($path, $lang, 6);
    return $first;
}

function qps_image_parse(string $path, string $lang = 'eng'): array {
    $text = qps_tesseract_best($path, $lang);
    $bytes = @file_get_contents($path);
    $image = '';
    if ($bytes !== false && strlen($bytes) <= 3145728) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'gif' => 'image/gif'][$ext] ?? 'image/png';
        $image = 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
    $lines = preg_split('/\R/u', $text);
    $blocks = [];
    foreach ($lines as $line) $blocks[] = ['text' => $line, 'image_url' => $image];
    return qps_blocks_to_questions($blocks);
}

/**
 * Main entry point for universal parsing
 */
function qps_parse_file(string $path, string $ext, string $ocrLang = 'eng', bool $forceOcr = false, array $sectionMarks = []): array {
    $ext = strtolower($ext);
    switch ($ext) {
        case 'csv': return ['questions' => qps_parse_csv($path), 'ocr_used' => false];
        case 'json': return ['questions' => qps_parse_json_file($path), 'ocr_used' => false];
        case 'xlsx': return ['questions' => qps_xlsx_rows($path), 'ocr_used' => false];
        case 'docx': return ['questions' => qps_docx_parse($path, $sectionMarks), 'ocr_used' => false];
        case 'odt': return ['questions' => qps_odt_parse($path), 'ocr_used' => false];
        case 'txt': return ['questions' => qps_txt_parse($path), 'ocr_used' => false];
        case 'pdf':
            $text = qps_pdf_text($path);
            if ($forceOcr || strlen(trim($text)) < 80) {
                return ['questions' => [], 'ocr_used' => true];
            }
            $lines = preg_split('/\R/u', $text);
            $blocks = [];
            foreach ($lines as $line) $blocks[] = ['text' => $line, 'image_url' => ''];
            return ['questions' => qps_blocks_to_questions($blocks), 'ocr_used' => false];
        case 'png': case 'jpg': case 'jpeg': case 'webp': case 'bmp': case 'gif':
            return ['questions' => qps_image_parse($path, $ocrLang), 'ocr_used' => true];
        case 'zip':
            $zip = qps_zip_entries($path);
            $all = [];
            $ocr = false;
            foreach (qps_zip_list($zip) as $name) {
                if (str_ends_with($name, '/') || preg_match('#(^|/)(__MACOSX|\.git)(/|$)#i', $name)) continue;
                $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($e, ['csv', 'json', 'xlsx', 'docx', 'odt', 'pdf', 'txt'], true)) continue;
                $bytes = qps_zip_read_entry($zip, $name);
                if ($bytes === null) continue;
                $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qps_' . bin2hex(random_bytes(5)) . '.' . $e;
                file_put_contents($tmp, $bytes);
                try {
                    $r = qps_parse_file($tmp, $e, $ocrLang, $forceOcr, $sectionMarks);
                    $ocr = $ocr || !empty($r['ocr_used']);
                    $all = array_merge($all, $r['questions'] ?? []);
                } finally {
                    @unlink($tmp);
                }
            }
            $i = 1;
            foreach ($all as &$q) $q['q_number'] = $i++;
            unset($q);
            return ['questions' => $all, 'ocr_used' => $ocr];
        default:
            throw new RuntimeException('Unsupported upload format: ' . $ext);
    }
}
?>
