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
    if (preg_match('/(?:^|\n)\s*(?:Answer(?:\s*Key)?|Ans|Key|Correct(?:\s*Option)?|Solution|விடை|சரியான\s*விடை|விடைக்குறிப்பு|Réponse|Corrigé|Clé)\s*[:：\-]?\s*([^\r\n]+)/iu', $text, $m)) {
        $key = trim($m[1]);
        $text = preg_replace('/(?:^|\n)\s*(?:Answer(?:\s*Key)?|Ans|Key|Correct(?:\s*Option)?|Solution|விடை|சரியான\s*விடை|விடைக்குறிப்பு|Réponse|Corrigé|Clé)\s*[:：\-]?\s*[^\r\n]+/iu', '', $text);
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

    // Preserve an explicit CO supplied by staff/source. Only legacy rows with
    // no usable CO may use the historical K->CO fallback.
    $coLevel = strtoupper(trim((string)($q['co_level'] ?? $q['co'] ?? '')));
    if ($coLevel === '' || !preg_match('/^CO[1-9][0-9]*$/', $coLevel)) {
        $kNum = preg_replace('/[^0-9]/', '', $kLevel);
        $coLevel = 'CO' . max(1, (int)($kNum !== '' ? $kNum : 1));
    }

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
        $marks = qps_header_pick($r, ['marks', 'mark', 'total_marks', 'अंक', 'மதிப்பெண்'], '');
        $sec = qps_header_pick($r, ['section_type', 'section', 'part', 'सेक्शन', 'खंड', 'பகுதி'], '');
        $coLevel = qps_header_pick($r, ['co_level', 'co', 'course_outcome', 'course outcome', 'CO', 'CO-Level'], '');
        $qNoRaw = qps_header_pick($r, ['q_no', 'qno', 'question_no', 'question_number', 'question number', 'Q.No', 'வினா எண்', 'प्रश्न सं.'], '');
        $qNo = (int)$qNoRaw;
        $answer = qps_header_pick($r, ['answer_key', 'answer', 'key', 'ans', 'solution', 'उत्तर कुंजी', 'उत्तर', 'कुंजी', 'விடை'], '');
        $imageUrl = qps_header_pick($r, ['image_url', 'image', 'img', 'photo'], '');

        $rowDict = [
            'question_text' => $qText,
            'unit_no' => (int)$unit,
            'sub_unit' => $subUnit,
            'k_level' => $kLevel,
            'co_level' => $coLevel,
            'marks' => $marks === '' ? 0 : (int)$marks,
            'section_type' => $sec,
            'answer_key' => $answer,
            'image_url' => $imageUrl
        ];

        $out[] = qps_question_defaults($rowDict, $qNo > 0 ? $qNo : $num);
        $num++;
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
function qps_docx_extract_blocks_php(string $path): array {
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
        throw new RuntimeException('PHP DOCX support requires ZipArchive and DOM/XML extensions.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Unable to open DOCX package.');

    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        throw new RuntimeException('DOCX document.xml not found.');
    }

    $rels = [];
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    if ($relsXml !== false) {
        $rdom = new DOMDocument();
        @$rdom->loadXML($relsXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($rdom->documentElement) {
            foreach ($rdom->documentElement->childNodes as $r) {
                if ($r->nodeType === XML_ELEMENT_NODE && $r->localName === 'Relationship') {
                    $id = $r->getAttribute('Id');
                    $target = $r->getAttribute('Target');
                    if ($id !== '' && $target !== '') $rels[$id] = $target;
                }
            }
        }
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        $zip->close();
        throw new RuntimeException('Invalid DOCX XML.');
    }

    $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $blocks = [];
    $body = $dom->getElementsByTagNameNS($W, 'body')->item(0);
    if (!$body) { $zip->close(); return []; }

    foreach ($body->childNodes as $node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) continue;

        if ($node->localName === 'p') {
            $text = '';
            $images = [];
            foreach ($node->getElementsByTagNameNS($W, 't') as $t) $text .= $t->textContent;
            foreach ($node->getElementsByTagNameNS($W, 'tab') as $tab) $text .= "\t";
            foreach ($node->getElementsByTagNameNS($W, 'br') as $br) $text .= "\n";

            foreach ($node->getElementsByTagNameNS($A, 'blip') as $blip) {
                $rid = $blip->getAttributeNS($R, 'embed');
                if (!isset($rels[$rid])) continue;
                $target = ltrim($rels[$rid], '/');
                if (strpos($target, 'word/') !== 0) $target = 'word/' . $target;
                $bytes = $zip->getFromName($target);
                if ($bytes === false || strlen($bytes) > 3145728) continue;
                $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
                $mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp'][$ext] ?? 'application/octet-stream';
                $images[] = 'data:' . $mime . ';base64,' . base64_encode($bytes);
            }

            $text = qps_normalize_space($text);
            if ($text !== '' || $images) $blocks[] = ['text'=>$text,'image_url'=>$images[0] ?? ''];
        } elseif ($node->localName === 'tbl') {
            foreach ($node->getElementsByTagNameNS($W, 'tr') as $tr) {
                $cells = [];
                foreach ($tr->getElementsByTagNameNS($W, 'tc') as $tc) {
                    $cell = '';
                    foreach ($tc->getElementsByTagNameNS($W, 't') as $t) $cell .= $t->textContent;
                    $cells[] = qps_normalize_space($cell);
                }
                $row = trim(implode(" | ", array_filter($cells, static fn($v) => $v !== '')));
                if ($row !== '') $blocks[] = ['text'=>$row,'image_url'=>''];
            }
        }
    }
    $zip->close();
    return $blocks;
}

/**
 * Parse the official v4 staff-simple DOCX format.
 * Required fields are explicit and authoritative:
 * Q.No, Unit, Sub-Unit, K-Level, CO, Section, Marks, Question.
 * No K->CO or question-number inference is performed for a v4 block.
 */
function qps_parse_staff_docx_v4(array $blocks, array $sectionMarks = []): array {
    $labelMap = [
        'Q.NO'=>'q_number','Q NO'=>'q_number','QUESTION NO'=>'q_number','QUESTION NUMBER'=>'q_number',
        'UNIT'=>'unit_no','SUB-UNIT'=>'sub_unit','SUB UNIT'=>'sub_unit',
        'K-LEVEL'=>'k_level','K LEVEL'=>'k_level','BLOOM'=>'k_level','BLOOM LEVEL'=>'k_level',
        'CO'=>'co_level','COURSE OUTCOME'=>'co_level',
        'SECTION'=>'section_type','PART'=>'section_type',
        'MARKS'=>'marks','MARK'=>'marks',
        'QUESTION'=>'question_text','QUESTION TEXT'=>'question_text'
    ];

    $normalizeLabel = static function(string $label): string {
        $label = strtoupper(trim(preg_replace('/\\s+/u', ' ', $label)));
        $label = str_replace(['_', '—', '–', ':'], [' ', '-', '-', ''], $label);
        return trim($label);
    };

    $parseField = static function(string $line) use ($normalizeLabel, $labelMap): array {
        if (preg_match('/^\\s*([A-Z][A-Z0-9 _-]{1,30})\\s*[:：]\\s*(.*)$/u', $line, $m)) {
            $label = $normalizeLabel($m[1]);
            if (isset($labelMap[$label])) return [$labelMap[$label], trim($m[2]), true];
        }
        return ['', '', false];
    };

    $hasV4 = false;
    foreach ($blocks as $b) {
        $line = trim((string)($b['text'] ?? ''));
        [$field, $value, $ok] = $parseField($line);
        if ($ok && in_array($field, ['q_number','unit_no','sub_unit','k_level','co_level','section_type','marks','question_text'], true)) {
            $hasV4 = true; break;
        }
    }
    if (!$hasV4) return [];

    $rows = [];
    $current = null;
    $flush = static function() use (&$rows, &$current) {
        if (!$current) return;
        $q = trim((string)($current['question_text'] ?? ''));
        if ($q === '') {
            $current['warnings'][] = 'QUESTION field is missing or empty.';
            $current['parser_confidence'] = 0.25;
        }
        $rows[] = $current;
        $current = null;
    };

    foreach ($blocks as $block) {
        $line = trim((string)($block['text'] ?? ''));
        if ($line === '') continue;
        // DOCX templates may use an 8-column table. The native extractor flattens
        // each table row with | separators, so support that exact canonical shape too.
        if (substr_count($line, '|') === 7) {
            $parts = array_map('trim', preg_split('/\\s*\\|\\s*/u', $line, 8));
            if (count($parts) === 8 && ctype_digit($parts[0]) && preg_match('/^[1-5]$/', $parts[1])
                && preg_match('/^[1-5]\\.[1-5]$/', $parts[2])
                && preg_match('/^K[1-6]$/i', $parts[3])
                && preg_match('/^CO[1-9][0-9]*$/i', $parts[4])
                && preg_match('/^(?:SECTION[- ]?)?[A-D]$/i', $parts[5])
                && is_numeric($parts[6]) && $parts[7] !== '') {
                $flush();
                $sec = strtoupper($parts[5]);
                if (preg_match('/^SECTION[- ]?([A-D])$/i', $sec, $sm)) $sec = 'SECTION-' . $sm[1];
                else $sec = 'SECTION-' . $sec;
                $current = [
                    'q_number' => (int)$parts[0],
                    'unit_no' => (int)$parts[1],
                    'sub_unit' => $parts[2],
                    'k_level' => strtoupper($parts[3]),
                    'co_level' => strtoupper($parts[4]),
                    'section_type' => $sec,
                    'marks' => (int)$parts[6],
                    'question_text' => $parts[7],
                    'warnings' => [],
                    'parser_confidence' => 1.0,
                    'source_format' => 'docx',
                    'parser_version' => 'php-staff-v4',
                    'import_schema' => 'staff-v4'
                ];
                continue;
            }
        }

        [$field, $value, $ok] = $parseField($line);

        // A new Q.No starts a new logical record.
        if ($ok && $field === 'q_number') {
            $flush();
            $current = [
                'q_number' => (int)$value,
                'warnings' => [],
                'parser_confidence' => 1.0,
                'source_format' => 'docx',
                'parser_version' => 'php-staff-v4'
            ];
            continue;
        }

        if ($current === null) continue;

        if ($ok) {
            if ($field === 'question_text') {
                $current['question_text'] = $value;
            } elseif ($field === 'unit_no') {
                $current['unit_no'] = (int)$value;
            } elseif ($field === 'sub_unit') {
                $current['sub_unit'] = $value;
            } elseif ($field === 'k_level') {
                $current['k_level'] = strtoupper(trim($value));
            } elseif ($field === 'co_level') {
                $current['co_level'] = strtoupper(trim($value));
            } elseif ($field === 'section_type') {
                $v = strtoupper(trim($value));
                if (preg_match('/^(?:SECTION[- ]?)?([A-D])$/', $v, $m)) $v = 'SECTION-' . $m[1];
                $current['section_type'] = $v;
            } elseif ($field === 'marks') {
                $current['marks'] = (int)$value;
            }
            continue;
        }

        // Unlabelled lines after QUESTION belong to the question text.
        if (isset($current['question_text'])) {
            $current['question_text'] .= "\n" . $line;
        } else {
            $current['warnings'][] = 'Unlabelled content appeared before QUESTION.';
            $current['parser_confidence'] = min((float)$current['parser_confidence'], 0.80);
        }
    }
    $flush();

    $out = [];
    foreach ($rows as $idx => $q) {
        $missing = [];
        foreach (['q_number','unit_no','sub_unit','k_level','co_level','section_type','marks','question_text'] as $f) {
            if (!isset($q[$f]) || trim((string)$q[$f]) === '') $missing[] = $f;
        }
        if ($missing) {
            $q['warnings'][] = 'Missing required fields: ' . implode(', ', $missing);
            $q['parser_confidence'] = min((float)($q['parser_confidence'] ?? 1), 0.40);
        }

        if (!isset($q['unit_no']) || $q['unit_no'] < 1 || $q['unit_no'] > 5) {
            $q['warnings'][] = 'Unit must be 1-5.';
            $q['parser_confidence'] = min((float)($q['parser_confidence'] ?? 1), 0.40);
        }
        if (isset($q['sub_unit']) && !preg_match('/^[1-5]\\.[1-5]$/', (string)$q['sub_unit'])) {
            $q['warnings'][] = 'Sub-Unit should use n.n format.';
            $q['parser_confidence'] = min((float)($q['parser_confidence'] ?? 1), 0.60);
        }
        if (isset($q['k_level']) && !preg_match('/^K[1-6]$/', (string)$q['k_level'])) {
            $q['warnings'][] = 'K-Level should use K1-K6.';
            $q['parser_confidence'] = min((float)($q['parser_confidence'] ?? 1), 0.60);
        }
        if (isset($q['co_level']) && !preg_match('/^CO[1-9][0-9]*$/', (string)$q['co_level'])) {
            $q['warnings'][] = 'CO should use CO1, CO2, ...';
            $q['parser_confidence'] = min((float)($q['parser_confidence'] ?? 1), 0.60);
        }
        if (isset($q['marks']) && ($q['marks'] < 0 || $q['marks'] > 100)) {
            $q['warnings'][] = 'Marks should be between 0 and 100.';
            $q['parser_confidence'] = min((float)($q['parser_confidence'] ?? 1), 0.50);
        }

        $opts = qps_extract_options_from_text((string)($q['question_text'] ?? ''));
        if ($opts) $q['options'] = $opts;
        $q['question_text'] = qps_clean_question_text((string)($q['question_text'] ?? ''));
        $q['language'] = qps_detect_language($q['question_text']);
        $q['source_question_no'] = (int)($q['q_number'] ?? 0);
        $q['import_schema'] = 'staff-v4';
        $out[] = $q;
    }
    return $out;
}

function qps_docx_parse(string $path, array $sectionMarks = []): array {
    // Production DOCX extraction is PHP-only. PHPWord is used when installed;
    // the native OOXML path remains the deterministic fallback for XAMPP.
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) @require_once $autoload;

    $blocks = qps_docx_extract_blocks_php($path);
    if (empty($blocks)) throw new RuntimeException('No readable text was found in the DOCX file.');

    $staffRows = qps_parse_staff_docx_v4($blocks, $sectionMarks);
    if (!empty($staffRows)) return $staffRows;

    // Legacy documents still use the mature PHP state-machine parser.
    return qps_paras_to_smart_questions($blocks);
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


/**
 * Deterministic parser for the institution's text-based OBE PDF layout.
 *
 * The PDF contains explicit CODE/LEVEL/UNIT/TYPE metadata. The legacy
 * line-based parser cannot safely use every numeric line as a question
 * boundary because MATCH tables contain their own 1., 2., 3., 4. rows.
 *
 * Rules:
 * - CODE/LEVEL/UNIT/TYPE is authoritative metadata.
 * - MC, VSA, PARAGRAPH and ESSAY use numbered lines as question boundaries.
 * - MATCH is one logical question block; its internal 1..4 rows stay together.
 * - ASSERTION_REASON is one logical question block per metadata block.
 * - Key/KEY/Answer is accepted with or without a colon.
 */
function qps_pdf_obe_parse(string $text, array $sectionMarks = []): array {
    $text = qps_utf8($text);
    $lines = preg_split('/\R/u', $text);
    $questions = [];
    $current = null;
    $ctx = [
        'unit_no' => 1,
        'sub_unit' => '1.1',
        'k_level' => 'K1',
        'type' => 'MCQ',
        'section_type' => 'SECTION-A',
        'marks' => 1,
        'source_question_no' => 0,
        'code' => ''
    ];

    $normalizeType = static function(string $type): string {
        $t = strtoupper(trim($type));
        $t = preg_replace('/[^A-Z_]/', '', $t);
        return match ($t) {
            'MC', 'MCQ' => 'MCQ',
            'M', 'MATCH' => 'MATCH',
            'AR', 'ASSERTION', 'ASSERTIONREASON', 'ASSERTION_REASON' => 'ASSERTION_REASON',
            'VSA', 'SA', 'SHORT' => 'VSA',
            'PA', 'P', 'PARAGRAPH', 'PARAGRAPHANSWER' => 'PARAGRAPH',
            'E', 'ESSAY', 'DESCRIPTIVE' => 'ESSAY',
            default => $t !== '' ? $t : 'VSA'
        };
    };

    $flush = static function() use (&$questions, &$current, &$ctx, $normalizeType): void {
        if ($current === null) return;
        $body = qps_normalize_space((string)($current['question_text'] ?? ''));
        if ($body === '') {
            $current = null;
            return;
        }

        $q = [
            'question_text' => qps_clean_question_text($body),
            'unit_no' => (int)$current['unit_no'],
            'sub_unit' => (string)$current['sub_unit'],
            'k_level' => strtoupper((string)$current['k_level']),
            'section_type' => (string)$current['section_type'],
            'marks' => (int)$current['marks'],
            'question_type' => $normalizeType((string)$current['type']),
            'answer_key' => trim((string)($current['answer_key'] ?? '')),
            'source_question_no' => (int)($current['source_question_no'] ?? 0),
            'source_code' => (string)($current['code'] ?? '')
        ];

        // Match/Assertion blocks need their complete text preserved. The
        // normal defaults layer will still extract options and normalize fields.
        $questions[] = $q;
        $current = null;
    };

    $newQuestion = static function(string $body, array $blockCtx, int $sourceNo): array {
        return [
            'question_text' => trim($body),
            'unit_no' => (int)$blockCtx['unit_no'],
            'sub_unit' => (string)$blockCtx['sub_unit'],
            'k_level' => (string)$blockCtx['k_level'],
            'section_type' => (string)$blockCtx['section_type'],
            'marks' => (int)$blockCtx['marks'],
            'type' => (string)$blockCtx['type'],
            'answer_key' => '',
            'source_question_no' => $sourceNo,
            'code' => (string)$blockCtx['code']
        ];
    };

    $applyContext = static function(array $m) use (&$ctx, $normalizeType, $sectionMarks): void {
        $ctx['code'] = trim((string)($m['code'] ?? ''));
        $ctx['k_level'] = strtoupper(trim((string)($m['level'] ?? 'K1')));
        if (!preg_match('/^K[1-6]$/', $ctx['k_level'])) $ctx['k_level'] = 'K1';

        $ctx['sub_unit'] = trim((string)($m['unit'] ?? '1.1'));
        if (!preg_match('/^[1-5]\.[1-5]$/', $ctx['sub_unit'])) $ctx['sub_unit'] = '1.1';
        $ctx['unit_no'] = (int)substr($ctx['sub_unit'], 0, 1);
        $ctx['type'] = $normalizeType((string)($m['type'] ?? 'MCQ'));

        $section = strtoupper((string)$ctx['section_type']);
        $defaultMarks = match ($section) {
            'SECTION-B' => 5,
            'SECTION-C' => 10,
            'SECTION-D' => 10,
            default => 1
        };
        $ctx['marks'] = $defaultMarks;

        if (isset($sectionMarks[$section])) {
            $candidate = (int)$sectionMarks[$section];
            if ($candidate > 0) $ctx['marks'] = $candidate;
        }
    };

    foreach ($lines as $rawLine) {
        $line = qps_normalize_space((string)$rawLine);
        if ($line === '') continue;

        // Unit headings.
        if (preg_match('/^Unit\s*[-:]?\s*(?:I{1,3}|IV|V|[1-5])\b/iu', $line, $um)) {
            $flush();
            $u = strtoupper(trim(preg_replace('/^Unit\s*[-:]?\s*/iu', '', $line)));
            $u = str_replace(['UNIT', '-', ':'], '', $u);
            $ctx['unit_no'] = is_numeric($u) ? (int)$u : qps_roman_to_int($u);
            $ctx['sub_unit'] = $ctx['unit_no'] . '.1';
            continue;
        }

        // Section headings.
        if (preg_match('/^SECTION\s*[-:]?\s*([A-D])/iu', $line, $sm)) {
            $flush();
            $ctx['section_type'] = 'SECTION-' . strtoupper($sm[1]);
            continue;
        }

        // Explicit PDF metadata line. Allow CODE/CODE:, missing spaces,
        // TYPE values MC/M/AR/VSA/PA/P/E, and metadata followed immediately
        // by the first numbered question.
        if (preg_match(
            '/CODE\s*:?\s*([A-Z0-9_-]+).*?LEVEL\s*:?\s*(K[1-6]).*?UNIT\s*:?\s*([1-5]\.[1-5]).*?TYPE\s*:?\s*([A-Z_]+)(?:\s+(.*))?$/iu',
            $line,
            $mm
        )) {
            $flush();
            $applyContext([
                'code' => $mm[1],
                'level' => $mm[2],
                'unit' => $mm[3],
                'type' => $mm[4]
            ]);

            $tail = trim((string)($mm[5] ?? ''));
            if ($tail !== '') {
                if (preg_match('/^(\d+)\s*[\.\):\-]\s*(.*)$/u', $tail, $qm)) {
                    $current = $newQuestion($qm[2], $ctx, (int)$qm[1]);
                } else {
                    $current = $newQuestion($tail, $ctx, 0);
                }
            }
            continue;
        }

        // Some source lines omit CODE entirely. Accept LEVEL/UNIT/TYPE as
        // authoritative context too.
        if (preg_match(
            '/LEVEL\s*:?\s*(K[1-6]).*?UNIT\s*:?\s*([1-5]\.[1-5]).*?TYPE\s*:?\s*([A-Z_]+)/iu',
            $line,
            $mm
        )) {
            $flush();
            $applyContext([
                'code' => $ctx['code'],
                'level' => $mm[1],
                'unit' => $mm[2],
                'type' => $mm[3]
            ]);
            $tail = trim(preg_replace('/^.*?TYPE\s*:?\s*[A-Z_]+\s*/iu', '', $line));
            if ($tail !== '' && preg_match('/^(\d+)\s*[\.\):\-]\s*(.*)$/u', $tail, $qm)) {
                $current = $newQuestion($qm[2], $ctx, (int)$qm[1]);
            }
            continue;
        }

        // Answer key. Accept "Key: a", "Key a", "KEY:c", "Answer: (b)" and
        // the same token when it is attached to the preceding line.
        if (preg_match('/^\s*(?:Answer(?:\s*Key)?|Ans|Key|Solution|Correct(?:\s*Option)?|விடை|சரியான\s*விடை|Réponse|Corrigé)\s*[:：\-]?\s*(.+?)\s*$/iu', $line, $ak)) {
            if ($current !== null) {
                $current['answer_key'] = trim($ak[1]);
            }
            continue;
        }

        // Handle an inline trailing key such as "... d. option Key:b".
        if ($current !== null && preg_match('/(?:^|\s)(?:Key|Ans|Answer)\s*[:：\-]?\s*([A-E])\s*$/iu', $line, $ik)) {
            $current['answer_key'] = strtoupper(trim($ik[1]));
            $clean = trim(preg_replace('/(?:^|\s)(?:Key|Ans|Answer)\s*[:：\-]?\s*[A-E]\s*$/iu', '', $line));
            if ($clean !== '') $current['question_text'] .= "\n" . $clean;
            continue;
        }

        $type = strtoupper((string)$ctx['type']);

        // MATCH is special: its internal 1..4 rows are table data, not new
        // questions. Only a new metadata line starts another MATCH question.
        if ($type === 'MATCH') {
            if ($current === null) {
                if (preg_match('/^(\d+)\s*[\.\):\-]\s*(.*)$/u', $line, $qm)) {
                    $current = $newQuestion($qm[2], $ctx, (int)$qm[1]);
                } else {
                    $current = $newQuestion($line, $ctx, 0);
                }
            } else {
                $current['question_text'] .= "\n" . $line;
            }
            continue;
        }

        // For MC/VSA/PARAGRAPH/ESSAY/AR, a numbered line is a logical
        // question boundary. This is what prevents questions from being
        // silently swallowed when numbering restarts inside a unit/type.
        if (preg_match('/^(\d+)\s*[\.\):\-]\s*(.*)$/u', $line, $qm)) {
            $flush();
            $current = $newQuestion($qm[2], $ctx, (int)$qm[1]);
            continue;
        }

        // Ignore table headings that occur before the first logical question.
        if ($current === null && preg_match('/^(?:Column\s+A|Column\s+B|Additional\s+Column|Panel\s+Options)$/iu', $line)) {
            continue;
        }

        if ($current !== null) {
            $current['question_text'] .= "\n" . $line;
        }
    }

    $flush();

    $out = [];
    $i = 1;
    foreach ($questions as $q) {
        $q['q_number'] = $i++;
        $q['source_question_no'] = (int)($q['source_question_no'] ?? 0);
        $q['import_schema'] = 'pdf-obe-v1';
        $q['parser_version'] = 'php-pdf-obe-v1';
        $q['question_type'] = strtoupper((string)($q['question_type'] ?? $q['type'] ?? 'VSA'));

        // Clean the key from the body one final time in case Poppler joined
        // "Key:" onto the previous line.
        $body = (string)($q['question_text'] ?? '');
        $key = (string)($q['answer_key'] ?? '');
        if ($key === '' && preg_match('/(?:^|\s)(?:Key|Ans|Answer)\s*[:：\-]?\s*([A-E])\s*$/iu', $body, $km)) {
            $key = strtoupper($km[1]);
            $body = trim(preg_replace('/(?:^|\s)(?:Key|Ans|Answer)\s*[:：\-]?\s*[A-E]\s*$/iu', '', $body));
        }
        $q['answer_key'] = $key;
        $q['question_text'] = qps_clean_question_text($body);
        $out[] = qps_question_defaults($q, $i - 1);

        // qps_question_defaults may infer fields for legacy compatibility,
        // but explicit PDF metadata remains authoritative.
        $out[array_key_last($out)]['unit_no'] = (int)$q['unit_no'];
        $out[array_key_last($out)]['sub_unit'] = (string)$q['sub_unit'];
        $out[array_key_last($out)]['k_level'] = strtoupper((string)$q['k_level']);
        $out[array_key_last($out)]['section_type'] = (string)$q['section_type'];
        $out[array_key_last($out)]['marks'] = (int)$q['marks'];
        $out[array_key_last($out)]['answer_key'] = trim((string)$q['answer_key']);
        $out[array_key_last($out)]['question_type'] = strtoupper((string)$q['question_type']);
        $out[array_key_last($out)]['source_question_no'] = (int)$q['source_question_no'];
        $out[array_key_last($out)]['import_schema'] = 'pdf-obe-v1';
        $out[array_key_last($out)]['parser_version'] = 'php-pdf-obe-v1';
    }

    return $out;
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
            // Prefer the deterministic Holy Cross OBE PDF parser. Fall back
            // to the generic parser only when the PDF has no recognizable
            // CODE/LEVEL/UNIT/TYPE structure.
            $pdfQuestions = qps_pdf_obe_parse($text, $sectionMarks);
            if (!empty($pdfQuestions)) {
                return ['questions' => $pdfQuestions, 'ocr_used' => false];
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
