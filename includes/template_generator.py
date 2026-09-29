#!/usr/bin/env python3
"""COE Staff Simple Question Bank Template v3.
Required staff fields: Q.No, Unit, Sub-Unit, K-Level, CO, Section, Marks, Question.
Question Type, Unit/Sub-Unit, options and answer keys are optional; the importer
infers them when possible and never invents missing staff data.
"""
import argparse
import docx
from docx.shared import Inches, Pt
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

LANG={
 'english':{
  'title':'HOLY CROSS COLLEGE (AUTONOMOUS), TIRUCHIRAPPALLI',
  'subtitle':'STAFF QUESTION BANK UPLOAD TEMPLATE — SIMPLE FORMAT',
  'headers':['Q.No','Unit','Sub-Unit','K-Level','CO','Section','Marks','Question'],
  'labels':['Q.NO:','UNIT:','SUB-UNIT:','K-LEVEL:','CO:','SECTION:','MARKS:','QUESTION:'],
  'samples':[
   [1,1,'1.1','K1','CO1','A',1,'Which of the following data structures follows the Last-In-First-Out (LIFO) principle? (a) Queue (b) Stack (c) Linked List (d) Tree'],
   [2,'A',1,'K2','CO1','Why was the Constituent Assembly important for India?'],
   [3,1,'1.3','K3','CO2','B',5,'Explain the role of democratic institutions in nation building.'],
   [4,1,'1.4','K4','CO3','C',10,'Analyse the major factors and discuss their impact on society.']
  ],
  'notes':['These eight fields are required: Question Number, Unit, Sub-Unit, K-Level, CO-Level, Section, Marks and Question.','Do NOT enter Question Type. The system detects MCQ, VSA, Match, Assertion-Reason, Paragraph and Essay where possible.','For MCQ, write options inside the Question field using (a), (b), (c), (d) or A., B., C., D. The system extracts them.','Unit/Sub-Unit is optional. If the source document has Unit/1.1 headings, the importer captures them; otherwise they remain blank for COE review.','Marks, Section, K-Level and CO are read from each question block and are never replaced with section defaults when explicitly supplied.','Use Unicode text for English, Tamil, Hindi or French.']
 },
 'tamil':{
  'title':'தூய சிலுவைக் கல்லூரி (தன்னாட்சி), திருச்சிராப்பள்ளி',
  'subtitle':'பணியாளர் வினா வங்கி பதிவேற்ற மாதிரி — எளிய வடிவம்',
  'headers':['வினா எண்','அலகு','துணை அலகு','K-நிலை','CO','பகுதி','மதிப்பெண்','வினா'],
  'labels':['வினா எண்:','அலகு:','துணை அலகு:','K-நிலை:','CO:','பகுதி:','மதிப்பெண்:','வினா:'],
  'samples':[[1,1,'1.1','K1','CO1','A',1,'பின்வருவனவற்றில் சரியான விடையைத் தேர்ந்தெடுக்கவும். (a) ஒன்று (b) இரண்டு (c) மூன்று (d) நான்கு'],[2,1,'1.2','K2','CO1','A',2,'இந்தக் கருத்தைச் சுருக்கமாக விளக்குக.'],[3,1,'1.3','K3','CO2','B',5,'இந்தக் கோட்பாட்டின் முக்கியத்துவத்தை விளக்குக.'],[4,1,'1.4','K4','CO3','C',10,'இந்தத் தலைப்பை பகுப்பாய்வு செய்து விவாதிக்கவும்.']],
  'notes':['பணியாளர் நிரப்ப வேண்டிய எட்டு புலங்கள்: வினா எண், அலகு, துணை அலகு, K-நிலை, CO, பகுதி, மதிப்பெண், வினா.','வினா வகையை நிரப்ப வேண்டாம்; அமைப்பு தானாகக் கண்டறியும்.','MCQ விருப்பங்களை வினா புலத்திலேயே (a), (b), (c), (d) வடிவில் கொடுக்கவும்.','Unit/Sub-Unit விருப்பமானது; இருந்தால் அமைப்பு எடுத்துக்கொள்ளும்.','ஒவ்வொரு வினாவிற்கும் வழங்கப்பட்ட மதிப்பெண்/பகுதி/K/CO மாற்றப்படாது.']
 },
 'hindi':{
  'title':'होली क्रॉस कॉलेज (स्वायत्त), तिरुचिरापल्ली',
  'subtitle':'स्टाफ प्रश्न बैंक अपलोड टेम्पलेट — सरल प्रारूप',
  'headers':['प्रश्न सं.','यूनिट','उप-यूनिट','K-स्तर','CO','सेक्शन','अंक','प्रश्न'],
  'labels':['प्रश्न सं.:','यूनिट:','उप-यूनिट:','K-स्तर:','CO:','सेक्शन:','अंक:','प्रश्न:'],
  'samples':[[1,1,'1.1','K1','CO1','A',1,'निम्नलिखित में से सही उत्तर चुनिए। (a) एक (b) दो (c) तीन (d) चार'],[2,1,'1.2','K2','CO1','A',2,'इस विषय को संक्षेप में समझाइए।'],[3,1,'1.3','K3','CO2','B',5,'इस विषय के महत्व को स्पष्ट कीजिए।'],[4,1,'1.4','K4','CO3','C',10,'इस विषय का विश्लेषण कीजिए और चर्चा कीजिए।']],
  'notes':['स्टाफ को आठ फ़ील्ड भरने हैं: प्रश्न संख्या, यूनिट, उप-यूनिट, K-स्तर, CO, सेक्शन, अंक और प्रश्न।','प्रश्न प्रकार भरना आवश्यक नहीं है; सिस्टम स्वतः पहचानने का प्रयास करेगा।','MCQ विकल्प प्रश्न फ़ील्ड में (a), (b), (c), (d) के साथ लिखें।','Unit/Sub-Unit वैकल्पिक है; उपलब्ध होने पर सिस्टम उसे पढ़ेगा।']
 },
 'french':{
  'title':'HOLY CROSS COLLEGE (AUTONOME), TIRUCHIRAPPALLI',
  'subtitle':'MODÈLE DE BANQUE DE QUESTIONS — FORMAT SIMPLE POUR LE PERSONNEL',
  'headers':['N° Q','Unité','Sous-unité','Niveau K','CO','Section','Points','Question'],
  'labels':['N° Q:','UNITÉ:','SOUS-UNITÉ:','NIVEAU K:','CO:','SECTION:','POINTS:','QUESTION:'],
  'samples':[[1,1,'1.1','K1','CO1','A',1,'Choisissez la bonne réponse. (a) Un (b) Deux (c) Trois (d) Quatre'],[2,1,'1.2','K2','CO1','A',2,'Expliquez brièvement ce sujet.'],[3,1,'1.3','K3','CO2','B',5,'Expliquez l’importance de ce concept.'],[4,1,'1.4','K4','CO3','C',10,'Analysez ce sujet et discutez ses effets.']],
  'notes':['Le personnel renseigne huit champs: N° de question, Unité, Sous-unité, Niveau K, CO, Section, Points et Question.','Le type de question est détecté automatiquement lorsque possible.','Pour un QCM, écrire les choix dans le champ Question avec (a), (b), (c), (d).','Unité/Sous-unité est facultative et sera lue si elle existe dans le document.']
 }
}

def shade(cell,fill):
    tcPr=cell._tc.get_or_add_tcPr(); shd=OxmlElement('w:shd'); shd.set(qn('w:fill'),fill); tcPr.append(shd)

def generate_docx(lang,out,course_code='',course_title=''):
    c=LANG[lang]; d=docx.Document()
    sec=d.sections[0]; sec.top_margin=Inches(.55); sec.bottom_margin=Inches(.55); sec.left_margin=Inches(.65); sec.right_margin=Inches(.65)
    p=d.add_paragraph(); p.alignment=WD_ALIGN_PARAGRAPH.CENTER; r=p.add_run(c['title']); r.bold=True; r.font.size=Pt(14)
    p=d.add_paragraph(); p.alignment=WD_ALIGN_PARAGRAPH.CENTER; r=p.add_run(c['subtitle']); r.bold=True; r.font.size=Pt(11)
    if course_code or course_title:
        p=d.add_paragraph(); p.alignment=WD_ALIGN_PARAGRAPH.CENTER; p.add_run(f'Course: {course_code} {course_title}'.strip()).bold=True
    box=d.add_table(rows=1,cols=1); box.alignment=WD_TABLE_ALIGNMENT.CENTER; cell=box.cell(0,0); shade(cell,'F1F5F9')
    cell.paragraphs[0].add_run('STAFF INSTRUCTIONS').bold=True
    for n in c['notes']:
        cell.add_paragraph(n,style=None).paragraph_format.space_after=Pt(1)
    d.add_paragraph('STANDARD QUESTION BLOCK').runs[0].bold=True
    for label,val in zip(c['labels'],['1','1','1.1','K1','CO1','A','1','Enter the complete question here. For MCQ, include (a) ... (d) in the same field.']):
        p=d.add_paragraph(); p.paragraph_format.space_after=Pt(1)
        a=p.add_run(label+' '); a.bold=True; p.add_run(val)
    d.add_paragraph()
    d.add_paragraph('EXAMPLE TABLE').runs[0].bold=True
    t=d.add_table(rows=1,cols=8); t.alignment=WD_TABLE_ALIGNMENT.CENTER
    for i,h in enumerate(c['headers']):
        t.cell(0,i).text=h; shade(t.cell(0,i),'1E293B')
        for rr in t.cell(0,i).paragraphs[0].runs: rr.bold=True; rr.font.color.rgb=__import__('docx').shared.RGBColor(255,255,255); rr.font.size=Pt(8)
    for row in c['samples']:
        cells=t.add_row().cells
        for i,v in enumerate(row): cells[i].text=str(v)
    d.add_paragraph()
    d.add_paragraph('Question Type and Answer Key are optional. Keep the eight standard fields in this order: Q.No, Unit, Sub-Unit, K-Level, CO, Section, Marks, Question.').runs[0].italic=True
    d.save(out)

def generate_xlsx(lang,out,course_code='',course_title=''):
    c=LANG[lang]; wb=openpyxl.Workbook(); ws=wb.active; ws.title='Staff Upload'
    headers=c['headers']
    ws.merge_cells(start_row=1,start_column=1,end_row=1,end_column=8); ws.cell(1,1).value=c['title']; ws.cell(1,1).font=Font(size=14,bold=True,color='FFFFFF'); ws.cell(1,1).fill=PatternFill('solid',fgColor='0F172A'); ws.cell(1,1).alignment=Alignment(horizontal='center')
    ws.merge_cells(start_row=2,start_column=1,end_row=2,end_column=6); ws.cell(2,1).value=c['subtitle']; ws.cell(2,1).font=Font(size=11,bold=True,color='FFFFFF'); ws.cell(2,1).fill=PatternFill('solid',fgColor='4338CA'); ws.cell(2,1).alignment=Alignment(horizontal='center')
    for i,n in enumerate(c['notes'],3):
        ws.merge_cells(start_row=i,start_column=1,end_row=i,end_column=6); ws.cell(i,1).value=n; ws.cell(i,1).alignment=Alignment(wrap_text=True)
    hr=3+len(c['notes'])+1
    for i,h in enumerate(headers,1):
        x=ws.cell(hr,i,h); x.font=Font(bold=True,color='FFFFFF'); x.fill=PatternFill('solid',fgColor='1E293B'); x.alignment=Alignment(horizontal='center',wrap_text=True)
    for r,row in enumerate(c['samples'],hr+1):
        for i,v in enumerate(row,1): ws.cell(r,i,v).alignment=Alignment(wrap_text=True,vertical='top')
    widths=[8,9,12,11,9,11,10,75]
    for i,w in enumerate(widths,1): ws.column_dimensions[get_column_letter(i)].width=w
    ws.freeze_panes=f'A{hr+1}'; ws.auto_filter.ref=f'A{hr}:H{hr+len(c["samples"])}'
    ws.save(out)

if __name__=='__main__':
    ap=argparse.ArgumentParser(); ap.add_argument('--lang',choices=LANG.keys(),default='english'); ap.add_argument('--format',choices=['docx','xlsx'],required=True); ap.add_argument('--output',required=True); ap.add_argument('--course_code',default=''); ap.add_argument('--course_title',default=''); a=ap.parse_args()
    (generate_docx if a.format=='docx' else generate_xlsx)(a.lang,a.output,a.course_code,a.course_title)
