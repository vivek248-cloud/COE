#!/usr/bin/env python3
"""
Holy Cross College (Autonomous), Tiruchirappalli
Official Question Bank Template Generator (DOCX & XLSX)
Supports: English, Tamil (தமிழ்), French (Français)
"""

import os
import sys
import json
import argparse

try:
    import docx
    from docx.shared import Inches, Pt, RGBColor
    from docx.enum.text import WD_ALIGN_PARAGRAPH
    from docx.enum.table import WD_TABLE_ALIGNMENT, WD_ALIGN_VERTICAL
    from docx.oxml import OxmlElement
    from docx.oxml.ns import qn
except ImportError:
    docx = None

try:
    import openpyxl
    from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
    from openpyxl.utils import get_column_letter
except ImportError:
    openpyxl = None

DATA_BY_LANG = {
    'english': {
        'title': 'Holy Cross College (Autonomous), Tiruchirappalli',
        'subtitle': 'Master Question Bank Template (OBE / Bloom Taxonomy)',
        'instructions': [
            '1. Fill in questions strictly matching your approved Master Blueprint (275+ Pool or Semester Blueprint).',
            '2. Specify Unit (I-V) and Sub-Unit (e.g. 1.1, 1.2, 2.1) for accurate curriculum mapping.',
            '3. Specify cognitive level: K1 (Remember), K2 (Understand), K3 (Apply), K4 (Analyze), K5 (Evaluate), K6 (Create).',
            '4. For Multiple Choice questions, provide Option A, B, C, D and the Answer Key (A/B/C/D).',
            '5. Math formulas can be written using standard LaTeX notation (e.g. $\\int_0^\\infty e^{-x^2} dx$).'
        ],
        'headers': ['Q.No', 'Unit', 'Sub-Unit', 'Section', 'K-Level', 'CO', 'Question Type', 'Question Text', 'Option A', 'Option B', 'Option C', 'Option D', 'Answer Key', 'Marks', 'Formula / LaTeX'],
        'sample_rows': [
            [1, 'I', '1.1', 'Part A (MC)', 'K1', 'CO1', 'Multiple Choice', 'Which of the following data structures follows the Last-In-First-Out (LIFO) principle?', 'Queue', 'Stack', 'Linked List', 'Tree', 'B', 1, ''],
            [2, 'I', '1.1', 'Part A (MC)', 'K1', 'CO1', 'Match Following', 'Match: (i) Stack (ii) Queue (iii) Binary Tree with (a) FIFO (b) Hierarchical (c) LIFO', '(i)-(c), (ii)-(a), (iii)-(b)', '(i)-(a), (ii)-(c), (iii)-(b)', '(i)-(b), (ii)-(a), (iii)-(c)', '(i)-(c), (ii)-(b), (iii)-(a)', 'A', 1, ''],
            [3, 'I', '1.2', 'Part A (MC)', 'K2', 'CO1', 'Assertion-Reason', 'Assertion (A): Binary search requires a sorted array. Reason (R): Binary search uses divide-and-conquer strategy.', 'Both A and R are true and R is correct explanation of A', 'Both A and R are true but R is NOT correct explanation', 'A is true but R is false', 'A is false but R is true', 'B', 1, ''],
            [4, 'I', '1.2', 'Part A (VSA)', 'K1', 'CO1', 'Very Short Answer', 'Define time complexity of an algorithm and state Big-O notation for linear search.', '', '', '', '', 'Time complexity measures execution steps. Big-O for linear search is O(n).', 2, 'O(n)'],
            [5, 'I', '1.3', 'Part A (VSA)', 'K2', 'CO1', 'Very Short Answer', 'Differentiate between call by value and call by reference with a memory diagram outline.', '', '', '', '', 'Call by value passes copy; call by reference passes memory address.', 2, ''],
            [6, 'I', '1.3', 'Part B', 'K1', 'CO1', 'Short Answer', 'Explain the memory representation of single-dimensional and multi-dimensional arrays with address calculation formulas.', '', '', '', '', 'Base address calculation formula: Loc(A[i]) = Base(A) + w * (i - lower_bound)', 5, 'Loc(A[i]) = B + w(i - lb)'],
            [7, 'I', '1.3', 'Part B', 'K2', 'CO1', 'Short Answer', 'Illustrate the step-by-step trace of Quick Sort partitioning algorithm on array [45, 12, 89, 23, 7, 56].', '', '', '', '', 'Pivot element chosen and partitioning steps shown in detail.', 5, ''],
            [8, 'I', '1.3', 'Part B', 'K4', 'CO1', 'Analytical', 'Analyze the worst-case and average-case time complexities of Merge Sort vs Quick Sort with recurrence relations.', '', '', '', '', 'T(n) = 2T(n/2) + O(n) leads to O(n log n).', 5, 'T(n) = 2T(n/2) + O(n)'],
            [9, 'II', '2.1', 'Part C', 'K1', 'CO2', 'Descriptive', 'Describe the implementation of circular queue operations (enqueue, dequeue, display) using arrays with boundary condition checks.', '', '', '', '', 'Detailed algorithm with front/rear index wrap-around formulas.', 8, 'rear = (rear + 1) % MAX'],
            [10, 'II', '2.2', 'Part C', 'K3', 'CO2', 'Descriptive / Applied', 'Design and demonstrate an algorithm to evaluate postfix expressions using stack with complete dry run on: 5 3 + 8 2 - *.', '', '', '', '', 'Output is (5+3)*(8-2) = 8*6 = 48 with complete stack states shown.', 8, '(5+3)*(8-2) = 48'],
            [11, 'III', '3.1', 'Part D', 'K2', 'CO3', 'Comprehensive / Essay', 'Elaborate on AVL Tree rotations (LL, RR, LR, RL) with balance factor criteria and construct an AVL tree for numbers: 15, 20, 24, 10, 13, 7, 30, 36, 25.', '', '', '', '', 'Complete AVL construction with step-by-step balance factors and rotation diagrams.', 10, 'BF = h_L - h_R in {-1, 0, 1}']
        ]
    },
    'tamil': {
        'title': 'தூய சிலுவைக் கல்லூரி (தன்னாட்சி), திருச்சிராப்பள்ளி',
        'subtitle': 'அங்கீகரிக்கப்பட்ட வினா வங்கி மாதிரிப் படிவம் (OBE / Bloom Taxonomy)',
        'instructions': [
            '1. வினா வங்கி அங்கீகரிக்கப்பட்ட முதன்மை புளூபிரிண்ட் (275+ வினாத் தொகுப்பு) விதிகளின்படி பூர்த்தி செய்யப்பட வேண்டும்.',
            '2. அலகு (Unit I-V) மற்றும் துணை அலகு (Sub-Unit 1.1, 1.2, 2.1 போன்றவை) தெளிவாக குறிப்பிடவும்.',
            '3. அறிவாற்றல் நிலை (K-Level): K1 (நினைவுகூர்தல்), K2 (புரிந்துகொள்ளுதல்), K3 (பயன்படுத்துதல்), K4 (பகுத்தாய்தல்), K5 (மதிப்பிடுதல்), K6 (படைப்பாற்றல்).',
            '4. பலவுள் தெரிவு வினாக்களுக்கு தெரிவு A, B, C, D மற்றும் சரியான விடைக்குறிப்பு (A/B/C/D) கட்டாயம் நிரப்பப்பட வேண்டும்.',
            '5. தமிழ்ச் சொற்கள் மற்றும் கவிதை/இலக்கண வரிகள் ஒருங்குறி (Unicode) எழுத்துருவில் துல்லியமாக இருக்க வேண்டும்.'
        ],
        'headers': ['வினா எண்', 'அலகு (Unit)', 'துணை அலகு (Sub-Unit)', 'பகுதி (Section)', 'அறிவாற்றல் நிலை (K-Level)', 'பாடம் விளைவு (CO)', 'வினா வகை', 'வினா உரை (Question Text)', 'தெரிவு A', 'தெரிவு B', 'தெரிவு C', 'தெரிவு D', 'விடைக்குறிப்பு (Answer Key)', 'மதிப்பெண்', 'சூத்திரம் / குறிப்பு'],
        'sample_rows': [
            [1, 'I', '1.1', 'பகுதி அ (MC)', 'K1', 'CO1', 'பலவுள் தெரிவு', 'தொல்காப்பியத்தின் பொருளதிகாரம் எத்தனை இயல்களைக் கொண்டுள்ளது?', '7 இயல்கள்', '8 இயல்கள்', '9 இயல்கள்', '10 இயல்கள்', 'C', 1, ''],
            [2, 'I', '1.1', 'பகுதி அ (MC)', 'K1', 'CO1', 'பொருத்துக', 'பொருத்துக: (1) குறிஞ்சி (2) முல்லை (3) மருதம் (4) நெய்தல் உடன் (அ) வயல் (ஆ) மலை (இ) காடு (ஈ) கடல்', '(1)-(ஆ), (2)-(இ), (3)-(அ), (4)-(ஈ)', '(1)-(அ), (2)-(ஆ), (3)-(இ), (4)-(ஈ)', '(1)-(ஈ), (2)-(அ), (3)-(இ), (4)-(ஆ)', '(1)-(இ), (2)-(ஆ), (3)-(ஈ), (4)-(அ)', 'A', 1, ''],
            [3, 'I', '1.2', 'பகுதி அ (MC)', 'K2', 'CO1', 'கூற்று-காரணம்', 'கூற்று (A): எட்டுத்தொகை நூல்களுள் அகநூல்கள் ஆறு உள்ளன. காரணம் (R): பதிற்றுப்பத்தும் புறநானூறும் புறப்பொருள் பற்றிய நூல்களாகும்.', 'கூற்று (A) மற்றும் காரணம் (R) இரண்டும் சரி', 'கூற்று (A) சரி, ஆனால் காரணம் (R) தவறு', 'கூற்று (A) தவறு, ஆனால் காரணம் (R) சரி', 'இரண்டும் தவறு', 'A', 1, ''],
            [4, 'I', '1.2', 'பகுதி அ (VSA)', 'K1', 'CO1', 'குறுவினா', 'செம்மொழித் தகுதிப்பாடுகள் குறித்து பேராசிரியர் மணவை முஸ்தபா வரையறுத்த முதன்மை இலக்கணங்கள் யாவை?', '', '', '', '', 'தொன்மை, தனித்தன்மை, பொதுமைப்பண்பு, நடுவுநிலைமை, தாய்மைப்பண்பு முதலான 11 தகுதிகள்.', 2, ''],
            [5, 'I', '1.3', 'பகுதி அ (VSA)', 'K2', 'CO1', 'குறுவினா', 'சங்க இலக்கியத்தில் நிலவிய உள்ளுறை உவமம் மற்றும் இறைச்சி ஆகியவற்றின் வேறுபாட்டினை விளக்குக.', '', '', '', '', 'உள்ளுறை உவமம் கருப்பொருளின் வழி உணர்த்தும்; இறைச்சி குறிப்புப் பொருளால் வெளிப்படும்.', 2, ''],
            [6, 'I', '1.3', 'பகுதி ஆ', 'K1', 'CO1', 'சிறுவினா', 'முல்லைப்பாட்டில் விவரிக்கப்படும் கார்கால மாலைப்பொழுதின் இயற்கை வனப்பினை விளக்குக.', '', '', '', '', 'நப்பூதனாரின் முல்லைப்பாட்டு கார்கால வர்ணனை மற்றும் விருச்சி கேட்டல் நிகழ்வுகள்.', 5, ''],
            [7, 'I', '1.3', 'பகுதி ஆ', 'K2', 'CO1', 'சிறுவினா', 'புறநானூற்றுப் பாடல்கள் வழியே அறியலாகும் சங்ககாலத் தமிழரின் ஈகை மற்றும் போரறக் கோட்பாடுகளைத் தொகுத்தெழுதுக.', '', '', '', '', 'கடையெழு வள்ளல்களின் கொடைத்திறம் மற்றும் போரில் பசு, பார்ப்பார், பிணியாளர் புறந்தள்ளல் நெறி.', 5, ''],
            [8, 'I', '1.3', 'பகுதி ஆ', 'K4', 'CO1', 'பகுப்பாய்வு', 'சிலப்பதிகாரத்தில் வழக்குரை காதையில் கண்ணகியின் அறச்சீற்றமும் பாண்டியனின் நீதி வழுவாமையும் உணர்த்தும் அறநெறியைப் பகுத்தாராய்க.', '', '', '', '', 'அரசியல் பிழைத்தோர்க்கு அறங்கூற்றாவதும் உரைசால் பத்தினியை உயர்ந்தோர் ஏத்தலும் குறித்த விரிவான ஆய்வு.', 5, ''],
            [9, 'II', '2.1', 'பகுதி இ', 'K1', 'CO2', 'விரிவான வினா', 'பக்தி இலக்கியக் கால கட்டத்தில் திருநாவுக்கரசர் மற்றும் மாணிக்கவாசகரின் தமிழ்த் தொண்டினை விரிவாக விவரிக்க.', '', '', '', '', 'தேவாரப் பதிகங்கள், திருவாசகத் தத்துவ மேன்மை மற்றும் மக்கள் சமயப் புரட்சி.', 8, ''],
            [10, 'II', '2.2', 'பகுதி இ', 'K3', 'CO2', 'பயன்பாட்டு வினா', 'பாரதியாரின் பெண்ணியக் கவிதைகளில் வெளிப்படும் புதிய பெண்ணின் ஆளுமைச் சிறப்புகளை இன்றைய சமூகப் பின்னணியில் பொருத்தி விளக்குக.', '', '', '', '', 'நிமிர்ந்த நன்னடை, நேர்கொண்ட பார்வை மற்றும் தற்காலப் பெண் முன்னேற்றச் சிந்தனைகள் ஒப்பீடு.', 8, ''],
            [11, 'III', '3.1', 'பகுதி ஈ', 'K2', 'CO3', 'கட்டுரை வினா', 'தற்காலத் தமிழ் உரைநடை மற்றும் சிறுகதை வளர்ச்சியில் புதுமைப்பித்தன் மற்றும் ஜெயகாந்தனின் படைப்புப் பங்களிப்பினை மதிப்பிடுக.', '', '', '', '', 'யதார்த்தவாத சிறுகதை மரபு, மனித மன முரண்கள், சமூக விழிப்புணர்வுப் படைப்புகள் பற்றிய முழுமையான மதிப்பீட்டுக் கட்டுரை.', 10, '']
        ]
    },
    'french': {
        'title': 'Holy Cross College (Autonome), Tiruchirappalli',
        'subtitle': 'Modèle Officiel de Banque de Questions (OBE / Taxonomie de Bloom)',
        'instructions': [
            '1. La banque de questions doit correspondre strictement au plan directeur (Master Blueprint 275+ Pool).',
            '2. Indiquer l Unité (I à V) et la Sous-unité (ex: 1.1, 1.2, 2.1) pour une traçabilité précise du programme.',
            '3. Spécifier le niveau cognitif: K1 (Mémoriser), K2 (Comprendre), K3 (Appliquer), K4 (Analyser), K5 (Évaluer), K6 (Créer).',
            '4. Pour les questions à choix multiples, renseigner Options A, B, C, D et la Clé de réponse (A/B/C/D).',
            '5. Veiller au respect des accents et règles typographiques de la langue française.'
        ],
        'headers': ['N° Q', 'Unité', 'Sous-unité', 'Section', 'Niveau K', 'CO', 'Type de question', 'Texte de la question', 'Option A', 'Option B', 'Option C', 'Option D', 'Clé de réponse', 'Points', 'Formule / Remarque'],
        'sample_rows': [
            [1, 'I', '1.1', 'Partie A (QCM)', 'K1', 'CO1', 'Choix Multiple', 'Quel est le participe passé régulier du verbe « choisir » en français ?', 'Choisi', 'Choisissant', 'Choisit', 'Choisie', 'A', 1, ''],
            [2, 'I', '1.1', 'Partie A (QCM)', 'K1', 'CO1', 'Appariement', 'Associez: (1) Le Louvre (2) La Sorbonne (3) L Élysée avec (a) Université (b) Musée (c) Présidence', '(1)-(b), (2)-(a), (3)-(c)', '(1)-(a), (2)-(b), (3)-(c)', '(1)-(c), (2)-(a), (3)-(b)', '(1)-(b), (2)-(c), (3)-(a)', 'A', 1, ''],
            [3, 'I', '1.2', 'Partie A (QCM)', 'K2', 'CO1', 'Assertion-Raison', 'Assertion (A): Le subjonctif s emploie après « bien que ». Raison (R): « Bien que » exprime une concession hypothétique.', 'A et R sont vrais et R explique A', 'A et R sont vrais mais R n explique pas A', 'A est vrai mais R est faux', 'A est faux mais R est vrai', 'A', 1, ''],
            [4, 'I', '1.2', 'Partie A (VSA)', 'K1', 'CO1', 'Réponse Très Courte', 'Définissez la règle d accord du participe passé avec l auxiliaire « avoir ».', '', '', '', '', 'Le participe passé s accorde en genre et en nombre avec le COD si celui-ci est placé avant le verbe.', 2, ''],
            [5, 'I', '1.3', 'Partie A (VSA)', 'K2', 'CO1', 'Réponse Très Courte', 'Distinguez l emploi de l imparfait et du passé composé avec un exemple pour chacun.', '', '', '', '', 'Imparfait: description/habitude dans le passé. Passé composé: action ponctuelle et achevée.', 2, ''],
            [6, 'I', '1.3', 'Partie B', 'K1', 'CO1', 'Réponse Courte', 'Expliquez l usage des pronoms relatifs composés (auquel, duquel, lequel) avec trois exemples illustratifs.', '', '', '', '', 'Règles de contraction avec les prépositions à et de + exemples contextualisés.', 5, ''],
            [7, 'I', '1.3', 'Partie B', 'K2', 'CO1', 'Réponse Courte', 'Résumez les thèmes principaux abordés dans « Le Petit Prince » d Antoine de Saint-Exupéry.', '', '', '', '', 'L amitié, la critique du monde adulte, l essentiel invisible pour les yeux.', 5, ''],
            [8, 'I', '1.3', 'Partie B', 'K4', 'CO1', 'Analytique', 'Analysez l évolution de la condition féminine dans la littérature française du XXe siècle à travers Simone de Beauvoir.', '', '', '', '', 'Analyse critique du Deuxième Sexe et de l émancipation socio-culturelle.', 5, ''],
            [9, 'II', '2.1', 'Partie C', 'K1', 'CO2', 'Descriptive', 'Décrivez l impact de la Révolution Française de 1789 sur la diffusion de la langue et des valeurs républicaines.', '', '', '', '', 'Unification linguistique par l abbé Grégoire, Déclaration des droits de l homme.', 8, ''],
            [10, 'II', '2.2', 'Partie C', 'K3', 'CO2', 'Appliquée', 'Rédigez une lettre de motivation formelle en français pour un stage académique dans une institution internationale.', '', '', '', '', 'Structure épistolaire française formelle, formules de politesse adaptées, argumentation claire.', 8, ''],
            [11, 'III', '3.1', 'Partie D', 'K2', 'CO3', 'Dissertation', '« La Francophonie moderne : vecteur de diversité culturelle ou héritage linguistique ? » Développez votre réflexion.', '', '', '', '', 'Dissertation en trois parties: statut du français dans le monde, rayonnement culturel et défis contemporains.', 10, '']
        ]
    }
}

def set_cell_border(cell, **kwargs):
    """Set cell borders in python-docx"""
    tc = cell._tc
    tcPr = tc.get_or_add_tcPr()
    tcBorders = OxmlElement('w:tcBorders')
    for edge in ('top', 'left', 'bottom', 'right', 'insideH', 'insideV'):
        edge_data = kwargs.get(edge)
        if edge_data:
            tag = f'w:{edge}'
            element = OxmlElement(tag)
            element.set(qn('w:val'), edge_data.get('val', 'single'))
            element.set(qn('w:sz'), str(edge_data.get('sz', 4)))
            element.set(qn('w:space'), '0')
            element.set(qn('w:color'), edge_data.get('color', 'auto'))
            tcBorders.append(element)
    tcPr.append(tcBorders)

def set_cell_shading(cell, color_hex):
    """Set background fill of a cell in python-docx"""
    tc = cell._tc
    tcPr = tc.get_or_add_tcPr()
    shd = OxmlElement('w:shd')
    shd.set(qn('w:val'), 'clear')
    shd.set(qn('w:color'), 'auto')
    shd.set(qn('w:fill'), color_hex)
    tcPr.append(shd)


# Hindi template is intentionally Unicode-first. It reuses the same structural schema
# as English while replacing headers/instructions/sample content.
DATA_BY_LANG['hindi'] = {
    'title': 'होली क्रॉस कॉलेज (स्वायत्त), तिरुचिरापल्ली',
    'subtitle': 'मास्टर प्रश्न बैंक टेम्पलेट (OBE / Bloom Taxonomy)',
    'instructions': [
        '1. प्रश्न बैंक को स्वीकृत Master Blueprint के अनुसार भरें।',
        '2. यूनिट और उप-यूनिट (जैसे 1.1, 1.2, 2.1) स्पष्ट रूप से दें।',
        '3. K-Level K1 से K6 और CO को सही रूप से दर्ज करें; CO हमेशा K-Level के अनुरूप रहेगा।',
        '4. बहुविकल्पीय प्रश्नों में A, B, C, D विकल्प और उत्तर कुंजी दें।',
        '5. हिन्दी पाठ Unicode (UTF-8) में दर्ज करें।'
    ],
    'headers': ['प्रश्न सं.', 'यूनिट', 'उप-यूनिट', 'सेक्शन', 'K-स्तर', 'CO', 'प्रश्न प्रकार', 'प्रश्न', 'विकल्प A', 'विकल्प B', 'विकल्प C', 'विकल्प D', 'उत्तर कुंजी', 'अंक', 'सूत्र / LaTeX'],
    'sample_rows': [
        [1,'I','1.1','Part A','K1','CO1','बहुविकल्पीय','निम्नलिखित में से सही उत्तर चुनिए।','विकल्प 1','विकल्प 2','विकल्प 3','विकल्प 4','A',1,''],
        [2,'I','1.2','Part B','K2','CO2','लघु उत्तरीय','दिए गए विषय को संक्षेप में समझाइए।','','','','','अपेक्षित उत्तर यहाँ लिखें।',5,'']
    ]
}

def generate_docx(lang, output_path, course_code='', course_title=''):
    cfg = DATA_BY_LANG.get(lang, DATA_BY_LANG['english'])
    doc = docx.Document()
    
    # Page Margins - Landscape for wide layout
    for section in doc.sections:
        section.top_margin = Inches(0.5)
        section.bottom_margin = Inches(0.5)
        section.left_margin = Inches(0.5)
        section.right_margin = Inches(0.5)
        section.page_width = Inches(11.69) # A4 Landscape
        section.page_height = Inches(8.27)

    # Title & Subtitle
    p_title = doc.add_paragraph()
    p_title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run_t = p_title.add_run(cfg['title'].upper())
    run_t.bold = True
    run_t.font.name = 'Calibri'
    run_t.font.size = Pt(14)
    run_t.font.color.rgb = RGBColor(15, 23, 42)

    p_sub = doc.add_paragraph()
    p_sub.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run_s = p_sub.add_run(cfg['subtitle'])
    run_s.bold = True
    run_s.font.name = 'Calibri'
    run_s.font.size = Pt(10.5)
    run_s.font.color.rgb = RGBColor(79, 70, 229)

    if course_code or course_title:
        p_c = doc.add_paragraph()
        p_c.alignment = WD_ALIGN_PARAGRAPH.CENTER
        run_c = p_c.add_run(f"Course: {course_code} - {course_title}".strip(' -'))
        run_c.bold = True
        run_c.font.size = Pt(9.5)
        run_c.font.color.rgb = RGBColor(51, 65, 85)

    # Instructions box
    table_inst = doc.add_table(rows=1, cols=1)
    table_inst.alignment = WD_TABLE_ALIGNMENT.CENTER
    c_inst = table_inst.rows[0].cells[0]
    set_cell_shading(c_inst, 'F8FAFC')
    set_cell_border(c_inst, top={'val':'single','sz':4,'color':'CBD5E1'}, bottom={'val':'single','sz':4,'color':'CBD5E1'}, left={'val':'single','sz':8,'color':'4F46E5'}, right={'val':'single','sz':4,'color':'CBD5E1'})
    
    p_ih = c_inst.paragraphs[0]
    r_ih = p_ih.add_run("Instructions & Guidelines:")
    r_ih.bold = True
    r_ih.font.size = Pt(9)
    r_ih.font.color.rgb = RGBColor(30, 41, 59)

    for item in cfg['instructions']:
        p_item = c_inst.add_paragraph()
        p_item.paragraph_format.space_after = Pt(1.5)
        r_item = p_item.add_run(item)
        r_item.font.size = Pt(8.0)
        r_item.font.color.rgb = RGBColor(71, 85, 105)

    doc.add_paragraph().paragraph_format.space_after = Pt(4)

    # Data Table
    headers = cfg['headers']
    rows_data = cfg['sample_rows']
    
    table = doc.add_table(rows=len(rows_data) + 1, cols=len(headers))
    table.alignment = WD_TABLE_ALIGNMENT.CENTER

    # Header Row
    hdr_cells = table.rows[0].cells
    for i, h in enumerate(headers):
        hdr_cells[i].text = h
        set_cell_shading(hdr_cells[i], '1E293B')
        p = hdr_cells[i].paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        for r in p.runs:
            r.bold = True
            r.font.size = Pt(8.0)
            r.font.name = 'Calibri'
            r.font.color.rgb = RGBColor(255, 255, 255)

    # Data Rows
    for r_idx, row in enumerate(rows_data):
        row_cells = table.rows[r_idx + 1].cells
        bg_color = 'FFFFFF' if r_idx % 2 == 0 else 'F8FAFC'
        for c_idx, val in enumerate(row):
            row_cells[c_idx].text = str(val) if val is not None else ''
            set_cell_shading(row_cells[c_idx], bg_color)
            set_cell_border(row_cells[c_idx], 
                            top={'val':'single','sz':4,'color':'E2E8F0'},
                            bottom={'val':'single','sz':4,'color':'E2E8F0'},
                            left={'val':'single','sz':4,'color':'E2E8F0'},
                            right={'val':'single','sz':4,'color':'E2E8F0'})
            p = row_cells[c_idx].paragraphs[0]
            if c_idx in [0, 1, 2, 3, 4, 5, 6, 12, 13]:
                p.alignment = WD_ALIGN_PARAGRAPH.CENTER
            for r in p.runs:
                r.font.size = Pt(7.5)
                r.font.name = 'Calibri'
                r.font.color.rgb = RGBColor(30, 41, 59)

    doc.save(output_path)
    print(f'Saved DOCX: {output_path}')

def generate_xlsx(lang, output_path, course_code='', course_title=''):
    cfg = DATA_BY_LANG.get(lang, DATA_BY_LANG['english'])
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Master QB Template"
    
    # Enable grid lines
    ws.views.sheetView[0].showGridLines = True

    # Styling constants
    navy_fill = PatternFill(start_color="0F172A", end_color="0F172A", fill_type="solid")
    indigo_fill = PatternFill(start_color="4338CA", end_color="4338CA", fill_type="solid")
    light_blue_fill = PatternFill(start_color="EEF2FF", end_color="EEF2FF", fill_type="solid")
    zebra_fill = PatternFill(start_color="F8FAFC", end_color="F8FAFC", fill_type="solid")
    
    font_title = Font(name="Segoe UI", size=13, bold=True, color="FFFFFF")
    font_subtitle = Font(name="Segoe UI", size=10, italic=True, color="E0E7FF")
    font_hdr = Font(name="Segoe UI", size=9, bold=True, color="FFFFFF")
    font_body = Font(name="Segoe UI", size=9, color="0F172A")
    font_inst = Font(name="Segoe UI", size=8.5, color="334155")
    
    thin_border = Border(
        left=Side(style='thin', color='CBD5E1'),
        right=Side(style='thin', color='CBD5E1'),
        top=Side(style='thin', color='CBD5E1'),
        bottom=Side(style='thin', color='CBD5E1')
    )

    # Row 1: College Header Banner
    ws.merge_cells('A1:O1')
    c1 = ws['A1']
    c1.value = cfg['title'].upper()
    c1.font = font_title
    c1.fill = navy_fill
    c1.alignment = Alignment(horizontal="center", vertical="center")
    ws.row_dimensions[1].height = 28

    # Row 2: Subtitle Banner
    ws.merge_cells('A2:O2')
    c2 = ws['A2']
    c2.value = f"{cfg['subtitle']} | Course: {course_code} {course_title}".strip()
    c2.font = font_subtitle
    c2.fill = indigo_fill
    c2.alignment = Alignment(horizontal="center", vertical="center")
    ws.row_dimensions[2].height = 20

    # Rows 3-7: Instructions Box
    for i, inst in enumerate(cfg['instructions']):
        r_num = 3 + i
        ws.merge_cells(f'A{r_num}:O{r_num}')
        c = ws[f'A{r_num}']
        c.value = inst
        c.font = font_inst
        c.fill = light_blue_fill
        c.alignment = Alignment(horizontal="left", vertical="center", indent=1)
        ws.row_dimensions[r_num].height = 18

    # Row 8: Blank separation
    ws.row_dimensions[8].height = 8

    # Row 9: Table Header
    headers = cfg['headers']
    for col_idx, h in enumerate(headers, 1):
        cell = ws.cell(row=9, column=col_idx, value=h)
        cell.font = font_hdr
        cell.fill = navy_fill
        cell.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
        cell.border = thin_border
    ws.row_dimensions[9].height = 26

    # Data Rows
    rows_data = cfg['sample_rows']
    for r_idx, row in enumerate(rows_data, 10):
        is_even = (r_idx % 2 == 0)
        for c_idx, val in enumerate(row, 1):
            cell = ws.cell(row=r_idx, column=c_idx, value=val)
            cell.font = font_body
            cell.border = thin_border
            if is_even:
                cell.fill = zebra_fill
            if c_idx in [1, 2, 3, 4, 5, 6, 7, 13, 14]:
                cell.alignment = Alignment(horizontal="center", vertical="center")
            else:
                cell.alignment = Alignment(horizontal="left", vertical="center", wrap_text=True)
        ws.row_dimensions[r_idx].height = 24

    # Adjust column widths
    col_widths = {
        1: 8,   # Q.No
        2: 8,   # Unit
        3: 12,  # Sub-Unit
        4: 16,  # Section
        5: 10,  # K-Level
        6: 8,   # CO
        7: 18,  # Question Type
        8: 45,  # Question Text
        9: 24,  # Option A
        10: 24, # Option B
        11: 24, # Option C
        12: 24, # Option D
        13: 18, # Answer Key
        14: 8,  # Marks
        15: 22  # Formula / LaTeX
    }
    for col_idx, w in col_widths.items():
        ws.column_dimensions[get_column_letter(col_idx)].width = w

    wb.save(output_path)
    print(f'Saved XLSX: {output_path}')

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--lang', default='english', choices=['english', 'tamil', 'hindi', 'french'])
    parser.add_argument('--format', default='xlsx', choices=['xlsx', 'docx'])
    parser.add_argument('--output', required=True)
    parser.add_argument('--course_code', default='')
    parser.add_argument('--course_title', default='')
    args = parser.parse_args()

    if args.format == 'docx':
        generate_docx(args.lang, args.output, args.course_code, args.course_title)
    elif args.format == 'xlsx':
        generate_xlsx(args.lang, args.output, args.course_code, args.course_title)
