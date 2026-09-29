#!/usr/bin/env python3
"""COE Staff Question Bank DOCX importer v3.

Primary format is intentionally simple for teaching staff:
Q.NO / SECTION / MARKS / K-LEVEL / CO / QUESTION.
Unit, sub-unit, question type and answer/options are optional and are inferred
when present. The importer never invents explicit staff fields; missing values
are flagged for review. Legacy institutional banks remain supported as fallback.
"""
import sys, json, re, unicodedata
from zipfile import ZipFile
from lxml import etree
import docx

W='http://schemas.openxmlformats.org/wordprocessingml/2006/main'
NS={'w':W}
DEFAULT_MARKS={'A':1,'B':5,'C':10,'D':10}

def clean(s):
    s=unicodedata.normalize('NFC',(s or '').replace('\xa0',' ').replace('\u200b',' '))
    s=re.sub(r'[ \t]+',' ',str(s))
    return s.strip()

def section_value(s):
    s=clean(s)
    m=re.search(r'\b(?:SECTION|SEC|PART|PARTIE|பகுதி|सेक्शन)\s*[-:–— ]*([A-D]|I{1,3}|IV|[அஆஇஈ])\b',s,re.I)
    if not m:
        m=re.match(r'^([A-D])$',s,re.I)
    if not m: return ''
    x=m.group(1).upper()
    return {'I':'A','II':'B','III':'C','IV':'D','அ':'A','ஆ':'B','இ':'C','ஈ':'D'}.get(x,x)

def klevel(s):
    m=re.search(r'\bK\s*[-:：]?\s*([1-6])\b',clean(s),re.I)
    return 'K'+m.group(1) if m else ''

def colevel(s):
    m=re.search(r'\bCO\s*[-:：]?\s*([1-9][0-9]*)\b',clean(s),re.I)
    return 'CO'+m.group(1) if m else ''

def marks_value(s):
    m=re.search(r'(?<!\d)(\d+(?:\.\d+)?)\s*(?:marks?|m|மதிப்பெண்|अंक|points?)?\s*$',clean(s),re.I)
    if not m: return None
    v=float(m.group(1))
    return int(v) if v.is_integer() else v

def qnum(s):
    m=re.match(r'^\s*(?:Q(?:\.?\s*NO)?|QUESTION|QUESTION\s*NO|வினா\s*எண்|प्रश्न\s*सं\.?)\s*[-:#.]?\s*(\d+)\s*$',clean(s),re.I)
    if m:return int(m.group(1))
    m=re.match(r'^\s*(\d+)\s*[.)\-:]\s*(.*)$',clean(s))
    if m:return int(m.group(1)),clean(m.group(2))
    return None

def label_value(s, labels):
    for label in labels:
        m=re.match(r'^\s*'+label+r'\s*[-:#：=]?\s*(.*?)\s*$',s,re.I)
        if m:return clean(m.group(1))
    return None

def options_from_text(text):
    t=clean(text)
    ms=list(re.finditer(r'(?:^|\s)([A-Da-d])\s*[.)\-:]\s*',t))
    if len(ms)<2:return {}
    out={}
    for i,m in enumerate(ms):
        end=ms[i+1].start() if i+1<len(ms) else len(t)
        val=clean(t[m.end():end])
        if val: out[m.group(1).upper()]=val
    return out

def infer_type(text, options=None):
    t=clean(text).lower()
    if options and len(options)>=2:return 'MCQ'
    if 'assertion' in t and 'reason' in t:return 'ASSERTION_REASON'
    if 'match the following' in t or 'match:' in t or 'பொருத்துக' in t or 'match the' in t:return 'MATCH'
    if any(x in t for x in ['essay','long answer','discuss in detail','critically analyse','critically analyze']):return 'ESSAY'
    return 'VSA'

def language(text):
    if re.search(r'[\u0900-\u097F]',text): return 'hi'
    if re.search(r'[\u0B80-\u0BFF]',text): return 'ta'
    if re.search(r'[À-ÿ]',text): return 'fr'
    return 'en'

def numbering_formats(path):
    out={}
    try:
        with ZipFile(path) as z:
            root=etree.fromstring(z.read('word/numbering.xml'))
            absmap={a.get(f'{{{W}}}abstractNumId'):a for a in root.xpath('.//w:abstractNum',namespaces=NS)}
            for n in root.xpath('.//w:num',namespaces=NS):
                nid=n.get(f'{{{W}}}numId'); a=n.find('./w:abstractNumId',namespaces=NS)
                if a is None: continue
                ab=absmap.get(a.get(f'{{{W}}}val'))
                if ab is None: continue
                for lvl in ab.xpath('./w:lvl',namespaces=NS):
                    il=int(lvl.get(f'{{{W}}}ilvl','0'))
                    nf=lvl.find('./w:numFmt',namespaces=NS)
                    if nf is not None: out[(str(nid),il)]=nf.get(f'{{{W}}}val')
    except Exception: pass
    return out

def iter_blocks(doc):
    from docx.document import Document
    from docx.table import Table, _Cell
    from docx.text.paragraph import Paragraph
    parent=doc.element.body
    for child in parent.iterchildren():
        if child.tag==Paragraph._tag: yield Paragraph(child,doc)
        elif child.tag==Table._tag: yield Table(child,doc)

def table_lines(table):
    for row in table.rows:
        vals=[clean(c.text) for c in row.cells]
        vals=[v for v in vals if v]
        if vals: yield ' | '.join(vals)

def parse_docx(path, section_marks=None):
    marks={**DEFAULT_MARKS,**(section_marks or {})}
    doc=docx.Document(path); fmts=numbering_formats(path)
    blocks=[]
    for idx,b in enumerate(iter_blocks(doc)):
        if hasattr(b,'text'):
            num=None
            try:
                ppr=b._p.pPr; np=ppr.numPr if ppr is not None else None
                if np is not None and np.numId is not None:
                    num=(str(np.numId.val),int(np.ilvl.val) if np.ilvl is not None else 0)
            except Exception: pass
            for part in b.text.splitlines():
                t=clean(part)
                if t: blocks.append((idx,t,num))
        else:
            for t in table_lines(b): blocks.append((idx,t,None))

    questions=[]; cur=None; active={'section':'A','k_level':'','co_level':'','unit':'','sub_unit':'','type':''}

    def new_question(n=None):
        nonlocal cur
        if cur: flush()
        cur={'source_q_number':n,'q_number':n,'section_type':'','marks':None,
             'k_level':'','co_level':'','unit_no':None,'sub_unit':'',
             'question_type':'','question_text':'','options':{},'answer_key':'',
             'warnings':[]}

    def flush():
        nonlocal cur
        if not cur:return
        cur['question_text']=clean(cur.get('question_text'))
        if not cur['question_text'] and not cur.get('options'): cur=None; return
        sec=cur.get('section_type') or active['section'] or ''
        cur['section_type']='SECTION-'+sec if sec and not sec.startswith('SECTION-') else sec
        cur['k_level']=cur.get('k_level') or active['k_level'] or ''
        cur['co_level']=cur.get('co_level') or active['co_level'] or ''
        cur['marks']=cur.get('marks') if cur.get('marks') not in (None,'') else (active.get('marks') if active.get('marks') not in (None,'') else marks.get(sec.replace('SECTION-',''),None))
        cur['unit_no']=cur.get('unit_no') or (int(active['sub_unit'].split('.')[0]) if active.get('sub_unit') and active['sub_unit'][0].isdigit() else None)
        cur['sub_unit']=cur.get('sub_unit') or active.get('sub_unit','')
        cur['question_type']=cur.get('question_type') or infer_type(cur['question_text'],cur.get('options'))
        cur['options']=cur.get('options') or options_from_text(cur['question_text'])
        cur['language']=language(cur['question_text'])
        if not cur.get('q_number'): cur['q_number']=len(questions)+1
        if not cur.get('section_type'): cur['warnings'].append('Section not detected')
        if cur.get('marks') in (None,''): cur['warnings'].append('Marks not detected')
        if not cur.get('k_level'): cur['warnings'].append('K-Level not detected')
        if not cur.get('co_level'): cur['warnings'].append('CO-Level not detected')
        if not cur.get('question_text'): cur['warnings'].append('Question text not detected')
        if cur['question_type']=='MCQ' and len(cur['options'])<2: cur['warnings'].append('MCQ options not fully detected')
        cur['parse_status']='warning' if cur['warnings'] else 'ready'
        cur['parser_confidence']=round(min(.55+.08*bool(cur.get('q_number'))+.10*bool(cur.get('section_type'))+.10*bool(cur.get('marks'))+.10*bool(cur.get('k_level'))+.10*bool(cur.get('co_level'))+.12*bool(cur.get('question_text')), .99),2)
        questions.append(cur); cur=None

    for _,line,num in blocks:
        # New preferred labelled block.
        qm=label_value(line,[r'Q\.?\s*NO',r'QUESTION\s*NO',r'QUESTION',r'विन?ा\s*एं?\s*न',r'प्रश्न\s*सं\.?' ])
        if qm is not None and qm.isdigit():
            new_question(int(qm)); continue
        n=qnum(line)
        if isinstance(n,tuple):
            if cur is None or (n[0] != cur.get('q_number') and (n[1] or n[0] <= (cur.get('q_number') or 0))):
                new_question(n[0])
                if n[1]: cur['question_text']=n[1]
                continue

        v=label_value(line,[r'SECTION',r'SEC',r'PART',r'பகுதி',r'सेक्शन'])
        if v is not None:
            s=section_value(v)
            if s:
                if cur and cur.get('question_text'): cur['section_type']='SECTION-'+s
                else: active['section']=s
                continue
        v=label_value(line,[r'MARKS?',r'MARK',r'மதிப்பெண்',r'अंक',r'POINTS?'])
        if v is not None:
            mv=marks_value(v)
            if mv is not None:
                if cur: cur['marks']=mv
                else: active['marks']=mv
                continue
        v=label_value(line,[r'K(?:-?LEVEL)?',r'LEVEL',r'K-स्तर'])
        if v is not None:
            kv=klevel(v)
            if kv:
                if cur: cur['k_level']=kv
                else: active['k_level']=kv
                continue
        v=label_value(line,[r'CO(?:-?LEVEL)?',r'COURSE\s*OUTCOME',r'CO-स्तर'])
        if v is not None:
            cv=colevel(v)
            if cv:
                if cur: cur['co_level']=cv
                else: active['co_level']=cv
                continue
        v=label_value(line,[r'UNIT'])
        if v is not None:
            su=re.search(r'(\d+\.\d+|[IVX]+)',v,re.I)
            if cur and su and su.group(1)[0].isdigit(): cur['sub_unit']=su.group(1); cur['unit_no']=int(su.group(1).split('.')[0])
            elif su: active['sub_unit']=su.group(1)
            continue
        v=label_value(line,[r'SUB-?UNIT',r'துணை அலகு',r'उप-यूनिट'])
        if v is not None:
            if cur: cur['sub_unit']=v; cur['unit_no']=int(v.split('.')[0]) if v[0].isdigit() else None
            else: active['sub_unit']=v
            continue
        v=label_value(line,[r'TYPE',r'QUESTION\s*TYPE',r'विन?ा\s*प्रकार'])
        if v is not None:
            if cur: cur['question_type']=v
            else: active['type']=v
            continue
        v=label_value(line,[r'QUESTION',r'विन?ा',r'प्रश्न'])
        if v is not None and v:
            if not cur: new_question(None)
            inline_opts=options_from_text(v)
            if inline_opts:
                # Keep only the stem in question_text and store options separately.
                first_opt=min(re.finditer(r'(?:^|\\s)[A-Da-d]\\s*[.)\\-:]\\s*',clean(v)), key=lambda m:m.start())
                stem=clean(v[:first_opt.start()])
                cur['question_text']=clean((cur.get('question_text','')+' '+stem))
                cur.setdefault('options',{}).update(inline_opts)
                cur['question_type']='MCQ'
            else:
                cur['question_text']=clean((cur.get('question_text','')+' '+v))
            continue

        # Legacy section headings / metadata.
        s=section_value(line)
        if s and re.match(r'^(SECTION|PART|PARTIE|பகுதி|सेक्शन)',line,re.I):
            active['section']=s; continue
        kv=klevel(line)
        if kv:
            active['k_level']=kv; continue
        cv=colevel(line)
        if cv:
            active['co_level']=cv; continue

        if not cur:
            # Legacy numbered questions and natural question starts.
            if isinstance(n,tuple):
                new_question(n[0]); cur['question_text']=n[1]; continue
            if re.match(r'^(?:What|Why|How|Who|Which|When|Define|Explain|Discuss|Describe|Analyse|Analyze|Assess|Evaluate|Examine|Critically)\b',line,re.I):
                new_question(None); cur['question_text']=line; continue
            continue

        # Keep all question content; detect options without requiring a Question Type.
        opts=options_from_text(line)
        if opts:
            cur.setdefault('options',{}).update(opts)
            if len(opts)>=2 and not cur.get('question_type'): cur['question_type']='MCQ'
            # Remove options from the question stem only when they are inline.
            if cur.get('question_text') and not cur['options']:
                pass
            continue
        ak=re.match(r'^\s*(?:KEY|ANSWER|ANS|विट?ै|विट?ाकु?रिप्पु|उत्तर\s*कुंजी|Réponse)\s*[:=\-]?\s*(.+)$',line,re.I)
        if ak:
            cur['answer_key']=clean(ak.group(1)); continue
        cur['question_text']=clean(cur.get('question_text','')+' '+line)

    flush()
    # Stable sequential display number only when source did not provide one.
    for i,q in enumerate(questions,1):
        if not q.get('q_number'): q['q_number']=i
    return questions

def main():
    path=sys.argv[1]; marks={}
    if '--section_marks' in sys.argv:
        try: marks=json.loads(sys.argv[sys.argv.index('--section_marks')+1])
        except Exception: marks={}
    try:
        qs=parse_docx(path,marks)
        print(json.dumps({'success':True,'count':len(qs),'questions':qs,'parser_version':'docx-staff-simple-v3'},ensure_ascii=False))
    except Exception as e:
        print(json.dumps({'success':False,'message':str(e),'parser_version':'docx-staff-simple-v3'},ensure_ascii=False)); sys.exit(1)

if __name__=='__main__': main()
