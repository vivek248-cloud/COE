#!/usr/bin/env python3
"""High-precision DOCX question-bank extractor for Holy Cross College.

The extractor is intentionally stateful: section, K-level, unit and sub-unit are
read from document headings and inherited by the following questions. Word's
numbering XML is also read, so automatically numbered paragraphs are not lost.
It does not guess K-level from question verbs and it never treats a metadata
heading such as `3.3 - K3` as a question.
"""
import os, sys, json, re, argparse
from collections import defaultdict
from zipfile import ZipFile
from lxml import etree

try:
    import docx
except ImportError:
    docx = None

W='http://schemas.openxmlformats.org/wordprocessingml/2006/main'
NS={'w':W}

SECTION_DEFAULT_MARKS={'A':1,'B':5,'C':10,'D':10}


def clean_text(s):
    if not s: return ''
    s=str(s).replace('\xa0',' ')
    s=re.sub(r'[\r\n\t]+',' ',s)
    s=re.sub(r'\s+',' ',s)
    return s.strip()


def roman_to_int(s):
    m={'I':1,'II':2,'III':3,'IV':4,'V':5,'VI':6,'VII':7,'VIII':8}
    s=str(s).strip().upper()
    return int(s) if s.isdigit() else m.get(s,1)


def normalize_section(code):
    c=str(code).strip().upper()
    return {'1':'A','I':'A','அ':'A','A':'A','2':'B','II':'B','ஆ':'B','B':'B',
            '3':'C','III':'C','இ':'C','C':'C','4':'D','IV':'D','ஈ':'D','D':'D'}.get(c)


def section_from_line(line):
    m=re.search(r'\b(?:SECTION|PART|பகுதி|PARTIE)\s*[-:–—\s]*([A-D]|I{1,3}|IV|[அஆஇஈ])\b', line, re.I)
    if m:
        c=normalize_section(m.group(1));
        if c: return c
    # Tamil/French/English descriptive section headings.
    u=line.upper()
    if re.search(r'\b(?:MULTIPLE CHOICE|MCQ|VSA|MATCH)\b',u) and 'SECTION' in u:
        return 'A'
    return None


def parse_k_heading(line):
    # K1 – MCQ, K2 - VSA, SECTION B - K4, 1.4 - K4, etc.
    m=re.search(r'(?<![A-Z])K\s*[-–—:]?\s*([1-6])\b',line,re.I)
    return f'K{m.group(1)}' if m else None


def parse_subunit_heading(line):
    m=re.match(r'^\s*([1-9]\d?\.[1-9]\d?)\s*(?:[-–—:]|$)\s*(?:K\s*[1-6])?\s*$',line,re.I)
    if m:
        return m.group(1)
    return None


def parse_unit_heading(line):
    m=re.match(r'^\s*(?:UNIT|ALAGU|அலகு|UNITÉ|MODULE)\s*[-:–—\s]*([1-9]|I|II|III|IV|V)\b',line,re.I)
    return roman_to_int(m.group(1)) if m else None


def parse_marks(line):
    pats=[r'\[\s*(\d{1,2})\s*M(?:ARKS?)?\s*\]',r'\(\s*(\d{1,2})\s*M(?:ARKS?)?\s*\)',
          r'\b(\d{1,2})\s*(?:MARKS?|M)\b',r'\bMARK\s*[:=]\s*(\d{1,2})\b']
    for pat in pats:
        m=re.search(pat,line,re.I)
        if m:
            n=int(m.group(1));
            if 1<=n<=50:return n
    return None


def extract_answer(line):
    patterns=[
      r'^\s*(?:Answer\s*Key|Answer|Ans|Key|Correct\s*(?:Option|Answer)?|Solution|விடை|சரியான\s*விடை|விடைக்குறிப்பு|Réponse|Corrigé|Clé(?:\s*de\s*réponse)?)\s*[:：=\-–—]\s*(.+?)\s*$',
      r'^\s*\((?:Key|Ans|Answer|விடை)\s*[:：=\-–—]\s*(.+?)\)\s*$',
    ]
    for pat in patterns:
        m=re.match(pat,line,re.I)
        if m:return clean_text(m.group(1))
    return ''


def strip_metadata(text):
    t=clean_text(text)
    # Remove parser-visible metadata accidentally copied into the question body.
    t=re.sub(r'\b(?:CODE|PAPER\s*CODE|COURSE\s*CODE)\s*[:=]\s*[A-Z0-9_.-]+\s*', ' ', t, flags=re.I)
    t=re.sub(r'\b(?:LEVEL|K\s*[- ]?LEVEL|NIVEAU|நிலை)\s*[:=\-–—]?\s*K\s*[1-6]\b', ' ', t, flags=re.I)
    t=re.sub(r'\bCO\s*[:=\-–—]?\s*CO?\s*[1-6]\b', ' ', t, flags=re.I)
    t=re.sub(r'\b(?:UNIT|UNITE|UNITÉ)\s*[:=]\s*[IVX0-9]+\b', ' ', t, flags=re.I)
    t=re.sub(r'\s{2,}',' ',t)
    return t.strip(' -–—:;')


def extract_options(text):
    # Options often arrive in a single Word paragraph separated by tabs.
    t=text.replace('\t',' ')
    matches=list(re.finditer(r'(?:^|\s)([A-Da-dஅஆஇஈ])\s*[\.)\-:]\s*',t))
    if len(matches)<2:return {}
    out={}
    for i,m in enumerate(matches):
        start=m.end(); end=matches[i+1].start() if i+1<len(matches) else len(t)
        val=clean_text(t[start:end])
        if val:out[m.group(1).upper()]=val
    return out


def build_numbering_maps(z):
    abstracts={}; nums={}
    try:
        root=etree.fromstring(z.read('word/numbering.xml'))
    except Exception:
        return abstracts,nums
    for a in root.xpath('.//w:abstractNum',namespaces=NS):
        aid=int(a.get('{%s}abstractNumId'%W)); levels={}
        for lvl in a.xpath('./w:lvl',namespaces=NS):
            il=int(lvl.get('{%s}ilvl'%W)); fmt=lvl.find('./w:numFmt',namespaces=NS); txt=lvl.find('./w:lvlText',namespaces=NS)
            levels[il]={
              'fmt':fmt.get('{%s}val'%W) if fmt is not None else None,
              'text':txt.get('{%s}val'%W) if txt is not None else '%1.',
              'start':int(lvl.find('./w:start',namespaces=NS).get('{%s}val'%W)) if lvl.find('./w:start',namespaces=NS) is not None else 1
            }
        abstracts[aid]=levels
    for n in root.xpath('.//w:num',namespaces=NS):
        nid=int(n.get('{%s}numId'%W)); aid=n.find('./w:abstractNumId',namespaces=NS)
        if aid is not None: nums[nid]=int(aid.get('{%s}val'%W))
    return abstracts,nums


def paragraph_num_info(p, counters, abstracts, nums):
    ppr=p._p.pPr
    numpr=ppr.numPr if ppr is not None else None
    if numpr is None:return None
    nid_el=numpr.find('./w:numId',namespaces=NS)
    il_el=numpr.find('./w:ilvl',namespaces=NS)
    if nid_el is None:return None
    nid=int(nid_el.get('{%s}val'%W)); il=int(il_el.get('{%s}val'%W)) if il_el is not None else 0
    level=abstracts.get(nums.get(nid),{}).get(il)
    if not level:return None
    fmt=level.get('fmt')
    counters[nid][il]+=1
    n=counters[nid][il]
    return {'number':n,'kind':fmt or 'other','num_id':nid,'level':il}


def parse_docx(path, section_marks=None):
    if docx is None: raise RuntimeError('python-docx library is required.')
    section_marks={**SECTION_DEFAULT_MARKS,**(section_marks or {})}
    doc=docx.Document(path)
    z=ZipFile(path)
    abstracts,nums=build_numbering_maps(z)
    counters=defaultdict(lambda:defaultdict(int))

    blocks=[]
    for p in doc.paragraphs:
        txt=clean_text(p.text)
        if not txt: continue
        ni=paragraph_num_info(p,counters,abstracts,nums)
        blocks.append({'text':txt,'number':ni.get('number') if ni else None,'num_kind':ni.get('kind') if ni else None,'image_url':''})

    questions=[]; current=None
    active_section='A'; active_unit=1; active_sub='1.1'; active_k='K1'
    current_q_source=None; match_mode=False

    def flush():
        nonlocal current
        if not current:return
        text=strip_metadata(current.get('question_text',''))
        if text and len(text)>=2:
            current['question_text']=text
            current['q_number']=len(questions)+1
            current['marks']=int(current.get('marks') or section_marks.get(active_section,1))
            current['k_level']=current.get('k_level') or active_k
            current['co_level']='CO'+current['k_level'].replace('K','')
            current['section_type']='SECTION-'+active_section
            current['unit_no']=int(current.get('unit_no') or active_unit)
            current['sub_unit']=current.get('sub_unit') or active_sub
            current['has_formula']=1 if current.get('formula_latex') or re.search(r'[√∑∫∞≤≥≠≈±×÷∂∆∇]|\$[^$]+\$|[⁰¹²³⁴⁵⁶⁷⁸⁹₀₁₂₃₄₅₆₇₈₉]',text) else 0
            current['options']=current.get('options') or None
            questions.append(current)
        current=None

    def start_question(body, number=None, block=None, explicit_sub=None):
        nonlocal current,current_q_source,active_sub,active_unit
        flush()
        if explicit_sub:
            active_sub=explicit_sub; active_unit=int(explicit_sub.split('.')[0])
        q={'q_number':len(questions)+1,'source_q_number':number,'unit_no':active_unit,'sub_unit':active_sub,
           'section_type':'SECTION-'+active_section,'k_level':active_k,'co_level':'CO'+active_k.replace('K',''),
           'marks':section_marks.get(active_section,1),'question_text':strip_metadata(body),
           'options':{},'answer_key':'','formula_latex':'','image_url':''}
        current=q; current_q_source=number

    for b in blocks:
        line=b['text']
        if re.match(r'^(?:HOLY CROSS COLLEGE|SCHOOL OF|DEPARTMENT OF|QUESTION BANK|COURSE TITLE|COURSE CODE|TIME\s*:|MAX(?:IMUM)?\s*MARKS?|PAGE\s+\d+)',line,re.I):
            continue

        sec=section_from_line(line)
        if sec:
            flush(); active_section=sec; match_mode=False
            km=parse_k_heading(line)
            if km: active_k=km
            m=parse_marks(line)
            if m: section_marks[sec]=m
            continue

        km=parse_k_heading(line)
        # Standalone K heading: K1 – MCQ, K2 – VSA, SECTION B – K4
        if km and (re.match(r'^K\s*[-–—:]?\s*[1-6]\b',line,re.I) or 'SECTION' in line.upper() or 'PART' in line.upper()):
            flush(); active_k=km
            m=parse_marks(line)
            if m: section_marks[active_section]=m
            if re.search(r'\bMATCH\b|\bAPPARI',line,re.I): match_mode=True
            else: match_mode=False
            continue

        su=parse_subunit_heading(line)
        if su:
            flush(); active_sub=su; active_unit=int(su.split('.')[0]);
            k=parse_k_heading(line)
            if k: active_k=k
            continue

        # Headings such as "K1 – Match" and "K1 – MCQ".
        if re.match(r'^K\s*[1-6]\s*[-–—:]',line,re.I):
            flush(); km=parse_k_heading(line); active_k=km or active_k; match_mode=('MATCH' in line.upper() or 'RELIEZ' in line.upper()); continue

        ak=extract_answer(line)
        if ak:
            if current:
                current['answer_key']=ak
            continue

        # Matching block: the instruction starts the logical question, then the
        # column lines/options/key remain attached to it.
        if re.search(r'^(?:Reliez|Match|Match the|பொருத்துக|பொருத்தி|Associez)\b',line,re.I):
            start_question(line, b.get('number'), b)
            match_mode=True
            continue

        # Metadata-only numeric heading must never become a question.
        if re.match(r'^\s*\d+(?:\.\d+)?\s*[-–—:]\s*K\s*[1-6]\s*$',line,re.I):
            flush(); su2=re.match(r'^\s*(\d+\.\d+)',line)
            if su2: active_sub=su2.group(1); active_unit=int(active_sub.split('.')[0])
            active_k=parse_k_heading(line) or active_k
            continue

        # Word alphabetic list paragraphs are options. Their visible labels are
        # frequently stored only in numbering.xml (the text itself starts with the
        # option body), so never turn these into questions.
        if b.get('num_kind') in ('lowerLetter','upperLetter','lowerRoman','upperRoman') and current:
            label_num = b.get('number') or 1
            if b.get('num_kind') == 'lowerLetter' and 1 <= label_num <= 26:
                label = chr(96 + label_num)
            elif b.get('num_kind') == 'upperLetter' and 1 <= label_num <= 26:
                label = chr(64 + label_num)
            else:
                label = str(label_num)
            body_line=line
            # If the body itself contains b./c./d. separated by tabs, split it too.
            opts=extract_options(body_line)
            if opts:
                # The first option label is frequently omitted from the visible
                # paragraph text because Word stores it in numbering.xml. Recover
                # the leading option body before the first explicit b./c./d. label.
                if label.upper() == 'A' and 'A' not in opts:
                    mfirst=re.search(r'\s+[b-dB-Dஅஆஇஈ]\s*[\.)-:]\s*', body_line)
                    if mfirst:
                        prefix=clean_text(body_line[:mfirst.start()])
                        if prefix: current.setdefault('options',{})['A']=prefix
                current['options'].update(opts)
            else:
                current.setdefault('options',{})[label.upper()]=clean_text(body_line)
            continue

        # Explicit numeric question, including 29. ..., 1) ..., 1: ...
        qm=re.match(r'^\s*(?:Q(?:uestion)?\s*)?(\d+)\s*[\.)\-:]\s*(.+)$',line,re.I)
        if qm:
            body=qm.group(2)
            # In match mode, numeric column entries are part of the match block unless
            # they contain a real question phrase or a new top-level section marker.
            if match_mode and current and re.match(r'^\d+\s*[-.]',line):
                current['question_text']+='\n'+line
                continue
            start_question(body,int(qm.group(1)),b)
            m=parse_marks(line)
            if m: current['marks']=m
            k=parse_k_heading(line)
            if k: current['k_level']=k; current['co_level']='CO'+k.replace('K','')
            continue

        # Word automatic decimal numbering: python-docx text doesn't contain the
        # number, but numbering.xml does. Treat it as a question only when we are
        # not currently inside an options/matching line.
        if b.get('num_kind')=='decimal':
            # Decimal list paragraphs are real question items. In a matching block,
            # however, they belong to the current matching question.
            if match_mode and current:
                current['question_text']+='\n'+line
                continue
            if current and (re.match(r'^[A-Da-dஅஆஇஈ]\s*[\.)]',line) or line.lower().startswith(('key:','answer:'))):
                current['question_text']+='\n'+line
                continue
            start_question(line,b.get('number'),b)
            continue

        # Do NOT create questions from unnumbered instructional prose. This is the
        # main protection against lines such as "Répondez à TOUTES..." becoming
        # fake questions. Real unnumbered questions should be supplied through the
        # official structured template or explicitly numbered in Word.

        if current:
            opts=extract_options(line)
            if opts:
                current['options'].update(opts)
                continue
            # Explicit inline metadata is extracted but not retained in the body.
            m=parse_marks(line)
            if m and len(line)<40 and re.search(r'MARK|\bM\b',line,re.I):
                current['marks']=m; continue
            k=parse_k_heading(line)
            if k and len(line)<40:
                current['k_level']=k; current['co_level']='CO'+k.replace('K',''); continue
            # Preserve options, passages and multi-line question text.
            current['question_text'] += '\n' + line
            if b.get('image_url') and not current.get('image_url'): current['image_url']=b['image_url']

    flush()
    # Final normalization: no fake defaults from question verbs; CO always maps to K.
    for i,q in enumerate(questions,1):
        q['q_number']=i
        q['k_level']=q.get('k_level') or 'K1'
        q['co_level']='CO'+q['k_level'].replace('K','')
        q['section_type']='SECTION-'+q.get('section_type','SECTION-A').replace('SECTION-','')
        q['marks']=int(q.get('marks') or section_marks.get(q['section_type'].replace('SECTION-','A'),1))
        q['question_text']=strip_metadata(q['question_text'])
        if not q.get('options'): q['options']=None
    return questions


def main():
    ap=argparse.ArgumentParser(); ap.add_argument('file_path'); ap.add_argument('--output'); ap.add_argument('--section_marks', default='{}');
    args=ap.parse_args()
    try:
        try: section_marks=json.loads(args.section_marks or '{}')
        except Exception: section_marks={}
        qs=parse_docx(args.file_path, section_marks)
        payload={'success':True,'count':len(qs),'questions':qs}
        out=json.dumps(payload,ensure_ascii=False,indent=2)
        if args.output: open(args.output,'w',encoding='utf-8').write(out)
        else: print(out)
    except Exception as e:
        print(json.dumps({'success':False,'message':str(e)},ensure_ascii=False)); sys.exit(1)

if __name__=='__main__': main()
