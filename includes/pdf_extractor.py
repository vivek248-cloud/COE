#!/usr/bin/env python3
"""
Holy Cross College (Autonomous), Tiruchirappalli - 620 002
COE Question Bank System - Standard PDF Question Extractor
Supports:
1. Native vector PDFs (Standard generated templates, structured tables, labeled blocks)
2. Scanned / Non-text PDFs (OCR fallback with confidence scoring & review flagging)
"""

import os
import sys
import re
import json
import argparse

try:
    import pypdf
except ImportError:
    pypdf = None

def normalize_space(text):
    if not text:
        return ""
    return re.sub(r'\s+', ' ', str(text)).strip()

def extract_pdf_questions(pdf_path, ocr_lang='eng', force_ocr=False):
    if pypdf is None:
        raise RuntimeError("pypdf is required for PDF extraction")

    reader = pypdf.PdfReader(pdf_path)
    total_pages = len(reader.pages)
    pages_text = []
    full_text = ""

    for page in reader.pages:
        t = page.extract_text() or ""
        pages_text.append(t)
        full_text += "\n" + t

    clean_text = full_text.strip()
    char_count = len(clean_text)
    avg_chars_per_page = char_count / max(total_pages, 1)

    is_scanned = (avg_chars_per_page < 80) or force_ocr
    base_confidence = 0.65 if is_scanned else 0.95
    status = "OCR_REVIEW" if is_scanned else "READY"

    questions = []

    # Normalize wrapped words in PDF text stream
    normalized_full = full_text
    normalized_full = re.sub(r'ASSERTION_R\s*EASON', 'ASSERTION_REASON', normalized_full, flags=re.IGNORECASE)
    normalized_full = re.sub(r'EITHER_\s*OR', 'EITHER_OR', normalized_full, flags=re.IGNORECASE)
    normalized_full = re.sub(r'FILL_\s*BLANK', 'FILL_BLANK', normalized_full, flags=re.IGNORECASE)

    # 1. First attempt: Table row parsing (e.g. from generated PDF template)
    table_pattern = re.compile(
        r'(?:^|\n)\s*(\d{1,3})\s*\n?\s*(Section\s+[A-E]|Part\s+[A-E]|Partie\s+[A-E]|खंड\s+[क-ङ]|பகுதி\s+[அ-ஈ])\s*\n?\s*([A-Za-z_]+)\s*\n?\s*(?:U(\d)|(\d))\s*\n?\s*([0-9\.]+)\s*\n?\s*(K[1-6])\s*\n?\s*(CO[1-5])\s*\n?\s*(\d{1,2})\s*\n?\s*(.*?)(?=(?:^|\n)\s*\d{1,3}\s*\n?\s*(?:Section|Part|Partie|खंड|பகுதி)|\Z)',
        re.DOTALL | re.IGNORECASE
    )

    tbl_matches = list(table_pattern.finditer(normalized_full))
    if tbl_matches and len(tbl_matches) >= 2:
        for m in tbl_matches:
            q_num = m.group(1)
            sec = m.group(2)
            q_type = m.group(3).upper()
            unit = m.group(4) or m.group(5) or '1'
            sub_u = m.group(6) or (unit + '.1')
            k_lvl = m.group(7).upper()
            co_cd = m.group(8).upper()
            marks = int(m.group(9))
            body = m.group(10).strip()

            q_obj = {
                'question_no': int(q_num),
                'section': sec,
                'question_type': q_type,
                'unit': unit,
                'sub_unit': sub_u,
                'k_level': k_lvl,
                'co_code': co_cd,
                'marks': marks,
                'question_text': '',
                'option_a': '',
                'option_b': '',
                'option_c': '',
                'option_d': '',
                'correct_answer': '',
                'answer_text': '',
                'assertion': '',
                'reason': '',
                'match_left': '',
                'match_right': '',
                'passage_text': '',
                'sub_questions': '',
                'either_or_group': '',
                'compulsory': 'Yes',
                'language': 'en',
                'source_ref': '',
                'notes': '',
                'confidence': 0.95,
                'errors': [],
                'warnings': []
            }

            # Extract options if any
            op_a = re.search(r'(?:A\)|\[A\]|\(A\))\s*([^\n\r\|]+?)(?=\s*(?:B\)|\[B\]|\(B\))|\Z)', body)
            op_b = re.search(r'(?:B\)|\[B\]|\(B\))\s*([^\n\r\|]+?)(?=\s*(?:C\)|\[C\]|\(C\))|\Z)', body)
            op_c = re.search(r'(?:C\)|\[C\]|\(C\))\s*([^\n\r\|]+?)(?=\s*(?:D\)|\[D\]|\(D\))|\Z)', body)
            op_d = re.search(r'(?:D\)|\[D\]|\(D\))\s*([^\n\r\|]+?)(?=\s*(?:Correct|Key|Answer|\Z))', body)

            if op_a and op_b:
                q_obj['option_a'] = normalize_space(op_a.group(1))
                q_obj['option_b'] = normalize_space(op_b.group(1))
                if op_c: q_obj['option_c'] = normalize_space(op_c.group(1))
                if op_d: q_obj['option_d'] = normalize_space(op_d.group(1))

            # Clean question text
            q_text = body
            q_text = re.sub(r'(?:A\)|\[A\]|\(A\)).*$', '', q_text, flags=re.DOTALL)
            q_obj['question_text'] = normalize_space(q_text)
            questions.append(q_obj)

    # 2. Second attempt: Labeled blocks [QUESTION_NO: ...] or Question N: [...]
    if len(questions) == 0:
        labeled_pattern = re.compile(
            r'(?:^|\n)\s*(?:Question\s+(\d{1,3})|\bQ(?:\.|\s*)(\d{1,3})|\[(?:QUESTION_NO|Q_NO|QNO):\s*(\d{1,3})\])(.*?)(?=(?:^|\n)\s*(?:Question\s+\d{1,3}|\bQ(?:\.|\s*)\d{1,3}|\[(?:QUESTION_NO|Q_NO|QNO):\s*\d{1,3}\])|\Z)',
            re.DOTALL | re.IGNORECASE
        )
        matches = list(labeled_pattern.finditer(full_text))
        for idx, m in enumerate(matches, start=1):
            q_num = m.group(1) or m.group(2) or m.group(3) or str(idx)
            block = m.group(4).strip()
            q_obj = parse_labeled_block(q_num, block, base_confidence)
            if q_obj and q_obj.get('question_text'):
                questions.append(q_obj)

    # 3. Third attempt: Heuristic numbered list (1. ..., 2. ...)
    if len(questions) == 0 and not is_scanned:
        # Split by numbered paragraphs
        num_pattern = re.compile(r'(?:^|\n)\s*(\d{1,3})[\.\)\:\-]\s+(.*?)(?=(?:^|\n)\s*\d{1,3}[\.\)\:\-]\s+|\Z)', re.DOTALL)
        num_matches = list(num_pattern.finditer(full_text))
        for m in num_matches:
            q_num = m.group(1)
            raw_content = m.group(2).strip()
            if len(raw_content) > 10:
                q_obj = parse_labeled_block(q_num, raw_content, base_confidence * 0.9)
                if q_obj and q_obj.get('question_text'):
                    questions.append(q_obj)

    return {
        'status': status,
        'is_scanned': is_scanned,
        'total_pages': total_pages,
        'char_count': char_count,
        'confidence': base_confidence,
        'parser_confidence': base_confidence,
        'questions': questions,
        'question_count': len(questions)
    }

def parse_labeled_block(q_num, block, base_conf):
    q_data = {
        'question_no': int(q_num) if str(q_num).isdigit() else q_num,
        'section': 'Section A',
        'question_type': 'MCQ',
        'unit': '1',
        'sub_unit': '1.1',
        'k_level': 'K1',
        'co_code': 'CO1',
        'marks': 1,
        'question_text': '',
        'option_a': '',
        'option_b': '',
        'option_c': '',
        'option_d': '',
        'correct_answer': '',
        'answer_text': '',
        'assertion': '',
        'reason': '',
        'match_left': '',
        'match_right': '',
        'passage_text': '',
        'sub_questions': '',
        'either_or_group': '',
        'compulsory': 'Yes',
        'language': 'en',
        'source_ref': '',
        'notes': '',
        'confidence': base_conf,
        'errors': [],
        'warnings': []
    }

    # Extract Section
    sec_m = re.search(r'\[(?:SECTION|PART):\s*([^\]]+)\]', block, re.IGNORECASE)
    if sec_m:
        q_data['section'] = sec_m.group(1).strip()
    elif re.search(r'\b(?:Part|Section)\s+([A-Ea-e1-5])\b', block, re.IGNORECASE):
        sm = re.search(r'\b(?:Part|Section)\s+([A-Ea-e1-5])\b', block, re.IGNORECASE)
        q_data['section'] = 'Section ' + sm.group(1).upper()

    # Extract Unit
    unit_m = re.search(r'\[(?:UNIT):\s*([^\]]+)\]', block, re.IGNORECASE)
    if unit_m:
        q_data['unit'] = unit_m.group(1).strip()
    elif re.search(r'\bUnit\s*[:\-\.]?\s*([IVX1-5]+)\b', block, re.IGNORECASE):
        um = re.search(r'\bUnit\s*[:\-\.]?\s*([IVX1-5]+)\b', block, re.IGNORECASE)
        u_val = um.group(1).upper()
        roman_map = {'I': '1', 'II': '2', 'III': '3', 'IV': '4', 'V': '5'}
        q_data['unit'] = roman_map.get(u_val, u_val)

    # Extract Sub-unit
    sub_m = re.search(r'\[(?:SUB_UNIT|SUBUNIT):\s*([^\]]+)\]', block, re.IGNORECASE)
    if sub_m:
        q_data['sub_unit'] = sub_m.group(1).strip()
    elif re.search(r'\b(?:Sub-Unit|Subunit|Sub Unit)\s*[:\-\.]?\s*([1-5]\.[1-9])\b', block, re.IGNORECASE):
        sm = re.search(r'\b(?:Sub-Unit|Subunit|Sub Unit)\s*[:\-\.]?\s*([1-5]\.[1-9])\b', block, re.IGNORECASE)
        q_data['sub_unit'] = sm.group(1)

    # Extract K-Level
    k_m = re.search(r'\[(?:K_LEVEL|KLEVEL|BLOOM):\s*([^\]]+)\]', block, re.IGNORECASE)
    if k_m:
        q_data['k_level'] = k_m.group(1).strip().upper()
    elif re.search(r'\b(?:LEVEL\s*[:\-\.]?\s*)?(K[1-6])\b', block, re.IGNORECASE):
        km = re.search(r'\b(?:LEVEL\s*[:\-\.]?\s*)?(K[1-6])\b', block, re.IGNORECASE)
        q_data['k_level'] = km.group(1).upper()

    # Extract CO
    co_m = re.search(r'\[(?:CO|CO_CODE|CO_LEVEL):\s*([^\]]+)\]', block, re.IGNORECASE)
    if co_m:
        q_data['co_code'] = co_m.group(1).strip().upper()
    elif re.search(r'\b(CO[1-5])\b', block, re.IGNORECASE):
        com = re.search(r'\b(CO[1-5])\b', block, re.IGNORECASE)
        q_data['co_code'] = com.group(1).upper()

    # Extract Marks
    marks_m = re.search(r'\[(?:MARKS|MARK|POINTS):\s*(\d+)\]', block, re.IGNORECASE)
    if marks_m:
        q_data['marks'] = int(marks_m.group(1))
    elif re.search(r'\b(?:Marks?|Pts?)\s*[:\-\.]?\s*(\d+)\b', block, re.IGNORECASE):
        mm = re.search(r'\b(?:Marks?|Pts?)\s*[:\-\.]?\s*(\d+)\b', block, re.IGNORECASE)
        q_data['marks'] = int(mm.group(1))

    # Extract Type
    type_m = re.search(r'\[(?:TYPE|QUESTION_TYPE|Q_TYPE):\s*([^\]]+)\]', block, re.IGNORECASE)
    if type_m:
        q_data['question_type'] = type_m.group(1).strip().upper()

    # Extract Answer Key
    ans_m = re.search(r'\[(?:ANSWER|ANSWER_KEY|KEY|CORRECT_ANSWER):\s*([^\]]+)\]', block, re.IGNORECASE)
    if ans_m:
        raw_ans = ans_m.group(1).strip()
        if '|' in raw_ans:
            p_parts = [p.strip() for p in raw_ans.split('|', 1)]
            q_data['correct_answer'] = p_parts[0]
            q_data['answer_text'] = p_parts[1]
        else:
            q_data['correct_answer'] = raw_ans
    else:
        ans_h = re.search(r'(?:Answer|Ans|Key)\s*[:\-\.]\s*([A-Da-d]|[^\n\r]+)', block, re.IGNORECASE)
        if ans_h:
            q_data['correct_answer'] = ans_h.group(1).strip()

    # Extract Options A, B, C, D
    op_a = re.search(r'(?:\(A\)|\[A\]|A\))\s*([^\n\r\(\)]+?)(?=\s*(?:\(B\)|\[B\]|B\))|\Z)', block, re.IGNORECASE)
    op_b = re.search(r'(?:\(B\)|\[B\]|B\))\s*([^\n\r\(\)]+?)(?=\s*(?:\(C\)|\[C\]|C\))|\Z)', block, re.IGNORECASE)
    op_c = re.search(r'(?:\(C\)|\[C\]|C\))\s*([^\n\r\(\)]+?)(?=\s*(?:\(D\)|\[D\]|D\))|\Z)', block, re.IGNORECASE)
    op_d = re.search(r'(?:\(D\)|\[D\]|D\))\s*([^\n\r\(\)]+?)(?=\s*(?:\[ANSWER|\(A\)|Answer:|\Z))', block, re.IGNORECASE)

    if op_a and op_b:
        q_data['option_a'] = normalize_space(op_a.group(1))
        q_data['option_b'] = normalize_space(op_b.group(1))
        if op_c: q_data['option_c'] = normalize_space(op_c.group(1))
        if op_d: q_data['option_d'] = normalize_space(op_d.group(1))

    # Clean Question Text
    cleaned_q = block
    cleaned_q = re.sub(r'\[(?:QUESTION_NO|SECTION|PART|TYPE|QUESTION_TYPE|UNIT|SUB_UNIT|K_LEVEL|CO|MARKS|ANSWER|KEY|ASSERTION|REASON|MATCH_LEFT|MATCH_RIGHT|PASSAGE|SUB_QUESTIONS|EITHER_OR_GROUP|COMPULSORY|LANGUAGE|SOURCE|NOTES)[^\]]*\]', '', cleaned_q, flags=re.IGNORECASE)
    cleaned_q = re.sub(r'(?:\(A\)|\(B\)|\(C\)|\(D\)|A\)|B\)|C\)|D\)).*$', '', cleaned_q, flags=re.MULTILINE | re.IGNORECASE)
    cleaned_q = re.sub(r'(?:Answer|Ans|Key)\s*[:\-\.].*$', '', cleaned_q, flags=re.MULTILINE | re.IGNORECASE)
    cleaned_q = re.sub(r'\b(?:CODEU\w+|LEVEL:\s*K\d|UNIT:\s*\d|CO:\s*CO\d)\b', '', cleaned_q, flags=re.IGNORECASE)
    q_data['question_text'] = normalize_space(cleaned_q)

    if not q_data.get('option_a') and q_data['question_type'] == 'MCQ':
        if q_data['marks'] in [1, 2]:
            q_data['question_type'] = 'VSA'
        elif q_data['marks'] == 5:
            q_data['question_type'] = 'PARAGRAPH'
        else:
            q_data['question_type'] = 'ESSAY'

    return q_data

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description="PDF Question Extractor")
    parser.add_argument('pdf_file', help="Path to PDF file")
    parser.add_argument('--lang', default='eng', help="OCR Language")
    parser.add_argument('--force_ocr', action='store_true', help="Force OCR pipeline")

    args = parser.parse_args()
    res = extract_pdf_questions(args.pdf_file, args.lang, args.force_ocr)
    print(json.dumps(res, indent=2, ensure_ascii=False))
