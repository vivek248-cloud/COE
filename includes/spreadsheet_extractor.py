#!/usr/bin/env python3
"""
Holy Cross College (Autonomous), Tiruchirappalli
COE Standard Authoritative Spreadsheet & CSV Extractor
- Reads XLSX and CSV files with exact mapping to 26 canonical fields
- Authoritative Confidence = 1.0
- Validation & Error Flagging per row
"""

import os
import sys
import json
import csv
import re
import argparse

try:
    import openpyxl
except ImportError:
    openpyxl = None

CANONICAL_KEYS = [
    'question_no', 'section', 'question_type', 'unit', 'sub_unit', 'k_level', 'co_code',
    'marks', 'question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer',
    'answer_text', 'assertion', 'reason', 'match_left', 'match_right', 'passage_text',
    'sub_questions', 'either_or_group', 'compulsory', 'language', 'source_ref', 'notes'
]

ALIAS_MAP = {
    'question_no': ['question_no', 'q_no', 'q_number', 'qno', 'sl_no', 'slno', 's_no', 'sno', 'no', 'வினா எண்', 'क्र.सं.', 'n° q', 'n°'],
    'section': ['section', 'part', 'section_type', 'part_type', 'பகுதி', 'खंड', 'partie'],
    'question_type': ['question_type', 'type', 'q_type', 'வினா வகை', 'प्रश्न प्रकार', 'type de question'],
    'unit': ['unit', 'unit_no', 'அலகு', 'इकाई', 'unité'],
    'sub_unit': ['sub_unit', 'subunit', 'sub unit', 'துணை அலகு', 'उप-इकाई', 'sous-unité'],
    'k_level': ['k_level', 'klevel', 'bloom_level', 'bloom', 'level', 'அறிவாற்றல் நிலை', 'संज्ञानात्मक स्तर', 'niveau k'],
    'co_code': ['co_code', 'co_level', 'co', 'பாடம் விளைவு', 'परिणाम', 'code co'],
    'marks': ['marks', 'mark', 'points', 'மதிப்பெண்', 'अंक'],
    'question_text': ['question_text', 'question', 'text', 'prompt', 'வினா உரை', 'விவரிப்பு', 'प्रश्न विवरण', 'texte de la question'],
    'option_a': ['option_a', 'option a', 'option_1', 'option 1', 'a', '(a)', '[a]', 'விருப்பம் a', 'விருப்பம் அ', 'विकल्प a', 'विकल्प क'],
    'option_b': ['option_b', 'option b', 'option_2', 'option 2', 'b', '(b)', '[b]', 'விருப்பம் b', 'விருப்பம் ஆ', 'विकल्प b', 'विकल्प ख'],
    'option_c': ['option_c', 'option c', 'option_3', 'option 3', 'c', '(c)', '[c]', 'விருப்பம் c', 'விருப்பம் இ', 'विकल्प c', 'विकल्प ग'],
    'option_d': ['option_d', 'option d', 'option_4', 'option 4', 'd', '(d)', '[d]', 'விருப்பம் d', 'விருப்பம் ஈ', 'विकल्प d', 'विकल्प घ'],
    'correct_answer': ['correct_answer', 'answer_key', 'answer', 'key', 'correct', 'சரியான விடை', 'விடைக்குறிப்பு', 'उत्तर कुंजी', 'सही उत्तर', 'réponse correcte', 'clé'],
    'answer_text': ['answer_text', 'model_answer', 'solution', 'explanation', 'விளக்க உரை', 'उत्तर विवरण', 'texte de réponse'],
    'assertion': ['assertion', 'கூற்று', 'कथन'],
    'reason': ['reason', 'காரணம்', 'कारण', 'raison'],
    'match_left': ['match_left', 'match left', 'column_a', 'பொருத்துக இடப்பக்கம்', 'சுमेलित बायां भाग', 'correspondance gauche'],
    'match_right': ['match_right', 'match right', 'column_b', 'பொருத்துக வலப்பக்கம்', 'சுमेलित दायां भाग', 'correspondance droite'],
    'passage_text': ['passage_text', 'passage', 'case_study', 'scenario', 'பத்தி உரை', 'गद्यांश', 'texte du passage'],
    'sub_questions': ['sub_questions', 'sub questions', 'துணை வினாக்கள்', 'उप-प्रश्न', 'sous-questions'],
    'either_or_group': ['either_or_group', 'either_or', 'choice_group', 'group', 'அல்லது குழு', 'अथवा समूह', 'groupe au choix'],
    'compulsory': ['compulsory', 'is_compulsory', 'mandatory', 'கட்டாய வினா', 'अनिवार्य', 'obligatoire'],
    'language': ['language', 'lang', 'மொழி', 'भाषा', 'langue'],
    'source_ref': ['source_ref', 'source', 'reference', 'ref', 'ஆதார நூல்', 'स्रोत संदर्भ', 'référence source'],
    'notes': ['notes', 'remarks', 'comment', 'குறிப்புகள்', 'टिप्पणी', 'remarques']
}

def resolve_header_key(raw_header):
    if not raw_header:
        return None
    cleaned = re.sub(r'[\s_]+', '_', str(raw_header).strip().lower())
    if cleaned in CANONICAL_KEYS:
        return cleaned
    for canonical, aliases in ALIAS_MAP.items():
        for a in aliases:
            a_clean = re.sub(r'[\s_]+', '_', a.lower())
            if cleaned == a_clean:
                return canonical
    for canonical, aliases in ALIAS_MAP.items():
        for a in aliases:
            a_clean = re.sub(r'[\s_]+', '_', a.lower())
            if a_clean in cleaned:
                return canonical
    return None

def normalize_unit(val):
    if not val:
        return '1'
    s = str(val).strip().upper()
    roman = {'I': '1', 'II': '2', 'III': '3', 'IV': '4', 'V': '5'}
    return roman.get(s, s)

def normalize_k_level(val):
    if not val:
        return 'K1'
    s = str(val).strip().upper()
    if re.match(r'^K[1-6]$', s):
        return s
    m = re.search(r'K([1-6])', s)
    if m:
        return 'K' + m.group(1)
    return s

def normalize_co_code(val):
    if not val:
        return 'CO1'
    s = str(val).strip().upper()
    if re.match(r'^CO[1-5]$', s):
        return s
    m = re.search(r'CO([1-5])', s)
    if m:
        return 'CO' + m.group(1)
    return s

def extract_spreadsheet(file_path, default_lang='en'):
    ext = os.path.splitext(file_path)[1].lower()
    raw_rows = []

    if ext == '.csv':
        with open(file_path, 'r', encoding='utf-8-sig', errors='replace') as f:
            reader = csv.reader(f)
            for row in reader:
                if any(str(c).strip() for c in row):
                    raw_rows.append([str(c).strip() for c in row])
    elif ext in ['.xlsx', '.xls']:
        if openpyxl is None:
            raise RuntimeError("openpyxl required to read XLSX files")
        wb = openpyxl.load_workbook(file_path, data_only=True)
        ws = wb.active
        for row in ws.iter_rows(values_only=True):
            if any(row):
                raw_rows.append([str(c).strip() if c is not None else '' for c in row])
    else:
        raise ValueError(f"Unsupported spreadsheet format: {ext}")

    if not raw_rows:
        return {'status': 'EMPTY', 'confidence': 1.0, 'questions': [], 'question_count': 0}

    # Find the header row
    header_idx = -1
    col_map = {} # canonical_key -> col_index

    for idx, row in enumerate(raw_rows[:10]):
        matched = 0
        current_map = {}
        for c_idx, cell in enumerate(row):
            canonical = resolve_header_key(cell)
            if canonical and canonical not in current_map:
                current_map[canonical] = c_idx
                matched += 1
        if matched >= 3: # Found header row!
            header_idx = idx
            col_map = current_map
            break

    if header_idx == -1:
        header_idx = 0
        for c_idx, k in enumerate(CANONICAL_KEYS):
            col_map[k] = c_idx

    questions = []
    data_rows = raw_rows[header_idx + 1:]

    for r_idx, row in enumerate(data_rows, start=1):
        if not any(row):
            continue

        q_obj = {k: '' for k in CANONICAL_KEYS}
        for k, c_idx in col_map.items():
            if c_idx < len(row):
                q_obj[k] = row[c_idx].strip()

        # Check if row is empty or instruction text
        if not q_obj['question_text'] and not q_obj['question_no']:
            continue
        
        # Default question number
        if not q_obj['question_no']:
            q_obj['question_no'] = r_idx
        elif str(q_obj['question_no']).isdigit():
            q_obj['question_no'] = int(q_obj['question_no'])

        # Normalizations
        q_obj['unit'] = normalize_unit(q_obj['unit'])
        if not q_obj['sub_unit']:
            q_obj['sub_unit'] = f"{q_obj['unit']}.1"
        q_obj['k_level'] = normalize_k_level(q_obj['k_level'])
        q_obj['co_code'] = normalize_co_code(q_obj['co_code'])
        
        try:
            q_obj['marks'] = int(float(q_obj['marks'])) if q_obj['marks'] else 1
        except Exception:
            q_obj['marks'] = 1

        if not q_obj['section']:
            q_obj['section'] = 'Section A'

        # Auto Question Type deduction if not specified or standard
        raw_type = q_obj['question_type'].upper().strip()
        if raw_type:
            q_obj['question_type'] = raw_type
        else:
            if q_obj.get('option_a') or q_obj.get('option_b'):
                q_obj['question_type'] = 'MCQ'
            elif q_obj.get('assertion') or q_obj.get('reason'):
                q_obj['question_type'] = 'ASSERTION_REASON'
            elif q_obj.get('match_left') or q_obj.get('match_right'):
                q_obj['question_type'] = 'MATCH'
            elif q_obj.get('passage_text'):
                q_obj['question_type'] = 'PASSAGE'
            elif q_obj.get('either_or_group'):
                q_obj['question_type'] = 'EITHER_OR'
            elif q_obj['marks'] in [1, 2]:
                q_obj['question_type'] = 'VSA'
            elif q_obj['marks'] == 5:
                q_obj['question_type'] = 'PARAGRAPH'
            else:
                q_obj['question_type'] = 'ESSAY'

        # Row-level validation
        errors = []
        warnings = []
        if not q_obj['question_text']:
            errors.append('Question text is missing')
        if q_obj['marks'] <= 0:
            warnings.append('Marks should be greater than 0')
        if q_obj['question_type'] == 'MCQ' and not q_obj.get('option_a'):
            warnings.append('MCQ has no Option A specified')

        q_obj['confidence'] = 1.0 # Authoritative structured source
        q_obj['errors'] = errors
        q_obj['warnings'] = warnings

        questions.append(q_obj)

    return {
        'status': 'READY',
        'is_scanned': False,
        'confidence': 1.0,
        'parser_confidence': 1.0,
        'questions': questions,
        'question_count': len(questions)
    }

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description="Authoritative Spreadsheet Extractor")
    parser.add_argument('file_path', help="Path to XLSX or CSV")
    args = parser.parse_args()
    res = extract_spreadsheet(args.file_path)
    print(json.dumps(res, indent=2, ensure_ascii=False))
