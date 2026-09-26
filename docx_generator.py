#!/usr/bin/env python3
"""
Holy Cross College (Autonomous), Tiruchirappalli - 620 002
Official OBE Question Paper DOCX Generator
Strictly replicates the institutional layout from U23BC3ALT05.pdf & Tss.pdf
- Standard 4-Section Layout (Section A Parts I & II 20M, Section B 25M, Section C 20M, Section D 10M)
- Right-aligned Bloom's K-level & Course Outcome mapping
- Unicode font handling for Tamil, French, and English
"""

import sys
import os
import json
import re
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_ALIGN_VERTICAL
from docx.oxml import parse_xml, OxmlElement
from docx.oxml.ns import nsdecls, qn

def clean_xml_string(s):
    if s is None:
        return ""
    if not isinstance(s, str):
        s = str(s)
    # Remove control characters except newline and tab
    s = re.sub(r'[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]', '', s)
    return s.strip()

def set_cell_margins(cell, top=60, bottom=60, left=80, right=80):
    tcPr = cell._tc.get_or_add_tcPr()
    tcMar = OxmlElement('w:tcMar')
    for m, val in [('top', top), ('bottom', bottom), ('left', left), ('right', right)]:
        node = OxmlElement(f'w:{m}')
        node.set(qn('w:w'), str(val))
        node.set(qn('w:type'), 'dxa')
        tcMar.append(node)
    tcPr.append(tcMar)

def set_cell_borders(cell, top="none", bottom="none", left="none", right="none", color="CCCCCC", sz="4"):
    tcPr = cell._tc.get_or_add_tcPr()
    tcBorders = OxmlElement('w:tcBorders')
    for side, val in [('top', top), ('left', left), ('bottom', bottom), ('right', right)]:
        if val != "none":
            border = OxmlElement(f'w:{side}')
            border.set(qn('w:val'), val)
            border.set(qn('w:sz'), sz)
            border.set(qn('w:space'), '0')
            border.set(qn('w:color'), color)
            tcBorders.append(border)
        else:
            border = OxmlElement(f'w:{side}')
            border.set(qn('w:val'), 'none')
            tcBorders.append(border)
    tcPr.append(tcBorders)

def normalize_sections_data(paper_data):
    """
    Normalizes both dictionary and list formats into a structured format
    conforming to Holy Cross 4-Section structure (U23BC3ALT05.pdf)
    """
    raw_sections = paper_data.get('sections', {})
    
    if isinstance(raw_sections, list):
        return raw_sections

    normalized = []
    
    # 1. SECTION A (20 Marks)
    sec_a = raw_sections.get('section_a', {})
    if sec_a:
        p1 = sec_a.get('part_1', {})
        p2 = sec_a.get('part_2', {})
        sec_a_dict = {
            'section_name': sec_a.get('name', 'SECTION – A (20 Marks)'),
            'choice_formula': '20 x 1 = 20 Marks',
            'parts': [
                {
                    'part_name': 'Part I – Answer all the Questions (10 x 1 = 10 Marks)',
                    'questions': p1.get('questions', [])
                },
                {
                    'part_name': 'Part II – Answer all the Questions (10 x 1 = 10 Marks)',
                    'questions': p2.get('questions', [])
                }
            ]
        }
        normalized.append(sec_a_dict)

    # 2. SECTION B (25 Marks)
    sec_b = raw_sections.get('section_b', {})
    if sec_b:
        pairs = sec_b.get('pairs', [])
        b_questions = []
        for pair in pairs:
            opt_a = pair.get('opt_a', {})
            opt_b = pair.get('opt_b', {})
            b_questions.append({
                'q_number': opt_a.get('label', f"{pair.get('pair_number', 21)}. (a)"),
                'question_text': opt_a.get('question_text', ''),
                'k_level': opt_a.get('k_level', 'K3'),
                'co_level': opt_a.get('co_level', 'CO3'),
                'marks': 5,
                'is_sub': True
            })
            b_questions.append({
                'q_number': '(OR)\n(b)',
                'question_text': opt_b.get('question_text', ''),
                'k_level': opt_b.get('k_level', 'K3'),
                'co_level': opt_b.get('co_level', 'CO3'),
                'marks': 5,
                'is_sub': True
            })

        normalized.append({
            'section_name': sec_b.get('name', 'SECTION – B (5 x 5 = 25 Marks)'),
            'instruction': sec_b.get('instruction', 'Answer all the Questions. Either or type.'),
            'choice_formula': '5 x 5 = 25 Marks',
            'questions': b_questions
        })

    # 3. SECTION C (20 Marks)
    sec_c = raw_sections.get('section_c', {})
    if sec_c:
        normalized.append({
            'section_name': sec_c.get('name', 'SECTION – C (2 x 10 = 20 Marks)'),
            'instruction': sec_c.get('instruction', 'Answer any TWO Questions.'),
            'choice_formula': '2 x 10 = 20 Marks',
            'questions': sec_c.get('questions', [])
        })

    # 4. SECTION D (10 Marks)
    sec_d = raw_sections.get('section_d', {})
    if sec_d:
        normalized.append({
            'section_name': sec_d.get('name', 'SECTION – D (1 x 10 = 10 Marks)'),
            'instruction': sec_d.get('instruction', 'Answer the following Question (Compulsory).'),
            'choice_formula': '1 x 10 = 10 Marks',
            'questions': sec_d.get('questions', [])
        })

    return normalized

def generate_docx(paper_data, output_path):
    doc = Document()

    # Configure Margins: 0.5 in Left/Right, 0.5 in Top/Bottom
    for section in doc.sections:
        section.top_margin = Inches(0.5)
        section.bottom_margin = Inches(0.5)
        section.left_margin = Inches(0.6)
        section.right_margin = Inches(0.6)
        section.page_width = Inches(8.27)  # A4 Width
        section.page_height = Inches(11.69) # A4 Height

    # -------------------------------------------------------------
    # 1. INSTITUTIONAL HEADER BLOCK
    # -------------------------------------------------------------
    p_inst = doc.add_paragraph()
    p_inst.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_inst.paragraph_format.space_before = Pt(0)
    p_inst.paragraph_format.space_after = Pt(2)
    r_inst = p_inst.add_run(clean_xml_string(paper_data.get('institution', 'HOLY CROSS COLLEGE (AUTONOMOUS), TIRUCHIRAPPALLI – 620 002')))
    r_inst.bold = True
    r_inst.font.size = Pt(12)
    r_inst.font.name = "Times New Roman"

    # School Hierarchy Line
    school = clean_xml_string(paper_data.get('school_name', 'SCHOOL OF PHYSICAL SCIENCES'))
    p_sch = doc.add_paragraph()
    p_sch.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_sch.paragraph_format.space_before = Pt(0)
    p_sch.paragraph_format.space_after = Pt(2)
    r_sch = p_sch.add_run(school)
    r_sch.bold = True
    r_sch.font.size = Pt(10.5)
    r_sch.font.name = "Times New Roman"

    # Degree & Exam Line
    deg_line = clean_xml_string(paper_data.get('degree_exam_line', 'II U.G. DEGREE EXAMINATION, SEMESTER-III, NOVEMBER 2026'))
    p_deg = doc.add_paragraph()
    p_deg.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_deg.paragraph_format.space_before = Pt(0)
    p_deg.paragraph_format.space_after = Pt(2)
    r_deg = p_deg.add_run(deg_line)
    r_deg.bold = True
    r_deg.font.size = Pt(10)
    r_deg.font.name = "Times New Roman"

    # Course Part & Discipline Line
    part_line = clean_xml_string(paper_data.get('course_part_line', 'PART III - CORE MAJOR'))
    p_part = doc.add_paragraph()
    p_part.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_part.paragraph_format.space_before = Pt(0)
    p_part.paragraph_format.space_after = Pt(4)
    r_part = p_part.add_run(part_line)
    r_part.bold = True
    r_part.font.size = Pt(10)
    r_part.font.name = "Times New Roman"

    # Course Title & Paper Code Line Table
    t_hdr = doc.add_table(rows=1, cols=2)
    t_hdr.alignment = WD_TABLE_ALIGNMENT.CENTER
    t_hdr.rows[0].cells[0].width = Inches(5.0)
    t_hdr.rows[0].cells[1].width = Inches(2.0)

    p_title = t_hdr.rows[0].cells[0].paragraphs[0]
    p_title.paragraph_format.space_before = Pt(1)
    p_title.paragraph_format.space_after = Pt(1)
    r_title = p_title.add_run(clean_xml_string(paper_data.get('course_title', 'COURSE TITLE')))
    r_title.bold = True
    r_title.font.size = Pt(10.5)
    r_title.font.name = "Times New Roman"

    p_code = t_hdr.rows[0].cells[1].paragraphs[0]
    p_code.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    p_code.paragraph_format.space_before = Pt(1)
    p_code.paragraph_format.space_after = Pt(1)
    r_code = p_code.add_run(f"Paper Code: {clean_xml_string(paper_data.get('paper_code', 'U23BC3ALT05'))}")
    r_code.bold = True
    r_code.font.size = Pt(10.5)
    r_code.font.name = "Times New Roman"

    # Time & Maximum Marks Table
    t_meta = doc.add_table(rows=1, cols=2)
    t_meta.alignment = WD_TABLE_ALIGNMENT.CENTER
    t_meta.rows[0].cells[0].width = Inches(3.5)
    t_meta.rows[0].cells[1].width = Inches(3.5)

    p_time = t_meta.rows[0].cells[0].paragraphs[0]
    p_time.paragraph_format.space_before = Pt(1)
    p_time.paragraph_format.space_after = Pt(4)
    r_time = p_time.add_run(f"Time: {clean_xml_string(paper_data.get('duration_hours', '3 Hours'))}")
    r_time.bold = True
    r_time.font.size = Pt(10)
    r_time.font.name = "Times New Roman"

    p_marks = t_meta.rows[0].cells[1].paragraphs[0]
    p_marks.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    p_marks.paragraph_format.space_before = Pt(1)
    p_marks.paragraph_format.space_after = Pt(4)
    r_marks = p_marks.add_run(f"Max. Marks: {clean_xml_string(str(paper_data.get('max_marks', 75)))}")
    r_marks.bold = True
    r_marks.font.size = Pt(10)
    r_marks.font.name = "Times New Roman"

    # Divider line
    p_div = doc.add_paragraph()
    p_div.paragraph_format.space_before = Pt(0)
    p_div.paragraph_format.space_after = Pt(6)
    r_div = p_div.add_run("―" * 60)
    r_div.font.size = Pt(8)
    r_div.font.color.rgb = RGBColor(140, 140, 140)

    # -------------------------------------------------------------
    # 2. RENDER NORMALIZED 4 SECTIONS
    # -------------------------------------------------------------
    sections = normalize_sections_data(paper_data)

    for sec in sections:
        sec_name = sec.get('section_name', 'SECTION')
        sec_inst = sec.get('instruction', '')
        
        # Section Header Paragraph
        p_sec = doc.add_paragraph()
        p_sec.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p_sec.paragraph_format.space_before = Pt(8)
        p_sec.paragraph_format.space_after = Pt(2)
        r_sec = p_sec.add_run(clean_xml_string(sec_name))
        r_sec.bold = True
        r_sec.font.size = Pt(11)
        r_sec.font.name = "Times New Roman"

        if sec_inst:
            p_inst = doc.add_paragraph()
            p_inst.alignment = WD_ALIGN_PARAGRAPH.CENTER
            p_inst.paragraph_format.space_before = Pt(0)
            p_inst.paragraph_format.space_after = Pt(4)
            r_inst = p_inst.add_run(clean_xml_string(sec_inst))
            r_inst.italic = True
            r_inst.bold = True
            r_inst.font.size = Pt(9.5)
            r_inst.font.name = "Times New Roman"

        # If Section has Parts (e.g. Section A Part I and Part II)
        parts = sec.get('parts', [])
        if parts:
            for part in parts:
                p_pname = doc.add_paragraph()
                p_pname.alignment = WD_ALIGN_PARAGRAPH.LEFT
                p_pname.paragraph_format.space_before = Pt(4)
                p_pname.paragraph_format.space_after = Pt(2)
                r_pn = p_pname.add_run(clean_xml_string(part.get('part_name', '')))
                r_pn.bold = True
                r_pn.font.size = Pt(10)
                r_pn.font.name = "Times New Roman"

                # Render Questions Table for this Part
                render_questions_table(doc, part.get('questions', []))
        else:
            # Render Questions Table for this Section
            render_questions_table(doc, sec.get('questions', []))

    # Save Document
    doc.save(output_path)
    return True

def render_questions_table(doc, questions):
    if not questions:
        return

    t_q = doc.add_table(rows=0, cols=4)
    t_q.alignment = WD_TABLE_ALIGNMENT.CENTER
    t_q.autofit = False

    col_w = [Inches(0.6), Inches(4.8), Inches(0.8), Inches(0.8)]

    # Add Column Header
    hdr_cells = t_q.add_row().cells
    hdr_titles = ["Q.No", "Question Description", "K-Level", "CO Level"]
    for idx, title in enumerate(hdr_titles):
        hdr_cells[idx].width = col_w[idx]
        p = hdr_cells[idx].paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER if idx in [0, 2, 3] else WD_ALIGN_PARAGRAPH.LEFT
        p.paragraph_format.space_before = Pt(2)
        p.paragraph_format.space_after = Pt(2)
        r = p.add_run(title)
        r.bold = True
        r.font.size = Pt(9)
        r.font.name = "Times New Roman"
        set_cell_borders(hdr_cells[idx], top="single", bottom="single", left="none", right="none")
        set_cell_margins(hdr_cells[idx], top=30, bottom=30, left=50, right=50)

    # Add Question Rows
    for q in questions:
        row_cells = t_q.add_row().cells
        for idx, w in enumerate(col_w):
            row_cells[idx].width = w
            set_cell_borders(row_cells[idx], top="none", bottom="none", left="none", right="none")
            set_cell_margins(row_cells[idx], top=30, bottom=30, left=50, right=50)

        # Col 0: Question Number
        q_num = clean_xml_string(str(q.get('q_number', '')))
        p0 = row_cells[0].paragraphs[0]
        p0.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p0.paragraph_format.space_before = Pt(2)
        p0.paragraph_format.space_after = Pt(2)
        r0 = p0.add_run(q_num)
        r0.bold = True
        r0.font.size = Pt(9.5)
        r0.font.name = "Times New Roman"

        # Col 1: Question Text & Options
        q_text = clean_xml_string(q.get('question_text', ''))
        p1 = row_cells[1].paragraphs[0]
        p1.alignment = WD_ALIGN_PARAGRAPH.LEFT
        p1.paragraph_format.space_before = Pt(2)
        p1.paragraph_format.space_after = Pt(2)
        r1 = p1.add_run(q_text)
        r1.font.size = Pt(9.5)
        r1.font.name = "Times New Roman"

        # Col 2: K-Level
        k_val = clean_xml_string(q.get('k_level', 'K1'))
        p2 = row_cells[2].paragraphs[0]
        p2.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p2.paragraph_format.space_before = Pt(2)
        p2.paragraph_format.space_after = Pt(2)
        r2 = p2.add_run(f"[{k_val}]")
        r2.bold = True
        r2.font.size = Pt(9)
        r2.font.name = "Times New Roman"

        # Col 3: CO Level
        co_val = clean_xml_string(q.get('co_level', 'CO1'))
        p3 = row_cells[3].paragraphs[0]
        p3.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p3.paragraph_format.space_before = Pt(2)
        p3.paragraph_format.space_after = Pt(2)
        r3 = p3.add_run(f"[{co_val}]")
        r3.bold = True
        r3.font.size = Pt(9)
        r3.font.name = "Times New Roman"

if __name__ == '__main__':
    if len(sys.argv) < 3:
        print("Usage: python3 docx_generator.py <input_json_path> <output_docx_path>")
        sys.exit(1)

    json_path = sys.argv[1]
    docx_path = sys.argv[2]

    try:
        with open(json_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        generate_docx(data, docx_path)
        print("DOCX successfully generated:", docx_path)
    except Exception as e:
        print("Error generating DOCX:", str(e), file=sys.stderr)
        sys.exit(1)
