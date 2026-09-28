#!/usr/bin/env python3
"""Structure-aware DOCX question-bank extractor v2.

Preserves Word numbering and handles real-world question banks containing
MCQ/options, Match, Assertion/Reason, passages, sub-questions, K headings,
unit/sub-unit headings and answer keys.
"""
import sys,json,re,unicodedata
from collections import defaultdict
from zipfile import ZipFile
from lxml import etree
import docx

W='http://schemas.openxmlformats.org/wordprocessingml/2006/main'
NS={'w':W}
DEFAULT_MARKS={'A':1,'B':5,'C':10,'D':10}

def clean(s):
    s=(s or '').replace('\xa0',' ').replace('\u200b','')
    s=unicodedata.normalize('NFC',str(s))
    s=re.sub(r'[ \t]+',' ',s)
    return s.strip()

def section(line):
    m=re.search(r'\b(?:SECTION|PART|PARTIE|பகுதி)\s*[-:–—\s]*([A-D]|I{1,3}|IV|[அஆஇஈ])\b',line,re.I)
    if not m:return None
    return {'1':'A','I':'A','அ':'A','2':'B','II':'B','ஆ':'B','3':'C','III':'C','இ':'C','4':'D','IV':'D','ஈ':'D'}.get(m.group(1).upper(),m.group(1).upper())

def klevel(line):
    m=re.search(r'(?<![A-Z])K\s*[-:–—]?\s*([1-6])\b',line,re.I)
    return 'K'+m.group(1) if m else None

def subunit(line):
    m=re.match(r'^\s*([1-5]\.[1-9]\d?)\s*(?:[-:–—:]|$)\s*(?:K\s*[1-6])?',line,re.I)
    return m.group(1) if m else None

def answer(line):
    m=re.match(r'^\s*(?:Answer(?:\s*Key)?|Ans|Key|Solution|விடை|சரியான\s*விடை|விடைக்குறிப்பு|Réponse|Corrigé|Clé(?:\s*de\s*réponse)?)\s*[:：=\-–—]\s*(.+?)\s*$',line,re.I)
    return clean(m.group(1)) if m else ''

def strip_inline_key(line):
    return clean(re.sub(r'\s+(?:Key|Ans|Answer)\s*[:=]\s*[A-Da-d]\s*$','',line,flags=re.I))

def inline_options(text):
    t=text.replace('\t',' ')
    ms=list(re.finditer(r'(?:^|\s)([A-Da-d])\s*[\.)\-:]\s*',t))
    if len(ms)<2:return {}
    out={}
    for i,m in enumerate(ms):
        end=ms[i+1].start() if i+1<len(ms) else len(t)
        v=clean(t[m.end():end])
        if v:out[m.group(1).upper()]=v
    return out

def match_row(text):
    t=clean(text)
    return bool(re.search(r'\s-\s*[A-E]\.',t)) or bool(re.match(r'^\s*\d+\s*[\.)-]\s*.*\b[A-E]\.',t))

def noise(text):
    return bool(re.match(r'^(?:HOLY CROSS|SCHOOL OF|DEPARTMENT OF|QUESTION BANK|COURSE TITLE|COURSE CODE|TIME\s*:|MAX(?:IMUM)?\s*MARKS?|PROGRAMME|SEMESTER|DURATION)\b',clean(text),re.I))

def numbering_formats(path):
    out={}
    try:
        with ZipFile(path) as z:
            root=etree.fromstring(z.read('word/numbering.xml'))
            nums={}
            for n in root.xpath('.//w:num',namespaces=NS):
                a=n.find('./w:abstractNumId',namespaces=NS)
                if a is not None: nums[n.get(f'{{{W}}}numId')]=a.get(f'{{{W}}}val')
            absmap={a.get(f'{{{W}}}abstractNumId'):a for a in root.xpath('.//w:abstractNum',namespaces=NS)}
            for nid,aid in nums.items():
                a=absmap.get(aid)
                if a is None:continue
                lvl=a.find('./w:lvl',namespaces=NS)
                if lvl is not None:
                    nf=lvl.find('./w:numFmt',namespaces=NS)
                    if nf is not None:out[nid]=nf.get(f'{{{W}}}val')
    except Exception:pass
    return out

def numinfo(p):
    ppr=p._p.pPr
    np=ppr.numPr if ppr is not None else None
    if np is None:return None
    nid=np.numId.val if np.numId is not None else None
    il=np.ilvl.val if np.ilvl is not None else 0
    return (str(nid),int(il)) if nid is not None else None

def numbering_formats(path):
    out={}
    try:
        with ZipFile(path) as z:
            root=etree.fromstring(z.read('word/numbering.xml'))
            absmap={a.get(f'{{{W}}}abstractNumId'):a for a in root.xpath('.//w:abstractNum',namespaces=NS)}
            for n in root.xpath('.//w:num',namespaces=NS):
                nid=n.get(f'{{{W}}}numId')
                a=n.find('./w:abstractNumId',namespaces=NS)
                if a is None: continue
                ab=absmap.get(a.get(f'{{{W}}}val'))
                if ab is None: continue
                for lvl in ab.xpath('./w:lvl',namespaces=NS):
                    il=lvl.get(f'{{{W}}}ilvl','0')
                    nf=lvl.find('./w:numFmt',namespaces=NS)
                    if nf is not None: out[(str(nid),int(il))]=nf.get(f'{{{W}}}val')
    except Exception:
        pass
    return out

def parse_docx(path,section_marks=None):
    marks={**DEFAULT_MARKS,**(section_marks or {})}
    doc=docx.Document(path)
    fmts=numbering_formats(path)

    # Read paragraphs and tables in document order. Word numbering is retained
    # because many real banks store question numbers as numbering properties
    # instead of literal "1." text.
    blocks=[]
    for idx,b in enumerate(iter_blocks(doc)):
        if hasattr(b,'text'):
            num=None
            try:
                ppr=b._p.pPr
                np=ppr.numPr if ppr is not None else None
                if np is not None and np.numId is not None:
                    num=(str(np.numId.val), int(np.ilvl.val) if np.ilvl is not None else 0)
            except Exception:
                pass
            for part in b.text.splitlines():
                t=clean(part)
                if t: blocks.append((idx,t,'p',num))
        else:
            for t in table_lines(b):
                blocks.append((idx,t,'t',None))

    qs=[]
    current=None
    active={'code':'','sub_unit':'1.1','k_level':'K1','question_type':'VSA'}
    sec='A'
    in_match=False
    in_ar=False

    def flush():
        nonlocal current,in_match,in_ar
        if not current:
            return
        current['question_text']=clean(current.get('question_text',''))
        if not current.get('question_text') and current.get('question_type')!='MATCH':
            current=None; in_match=False; in_ar=False; return

        current['q_number']=len(qs)+1
        current['unit_no']=int(str(current.get('sub_unit') or active['sub_unit']).split('.')[0])
        current['sub_unit']=current.get('sub_unit') or active['sub_unit']
        current['section_type']='SECTION-'+sec
        current['k_level']=current.get('k_level') or active['k_level']
        current['co_level']=current.get('co_level') or ('CO'+current['k_level'][1:])
        current['marks']=int(current.get('marks') or marks.get(sec,1))
        current.setdefault('options',{})
        current.setdefault('answer_key','')
        current.setdefault('warnings',[])
        current['language']=lang(current.get('question_text',''))

        qt=current.get('question_type')
        if qt=='MCQ' and len(current.get('options',{}))<2:
            current['warnings'].append('MCQ options incomplete')
        if qt=='MCQ' and not current.get('answer_key'):
            current['warnings'].append('MCQ answer key missing')
        if qt=='MATCH' and not current.get('answer_key'):
            current['warnings'].append('Match answer key not detected')
        if qt=='ASSERTION_REASON':
            if not current.get('assertion'): current['warnings'].append('Assertion not detected')
            if not current.get('reason'): current['warnings'].append('Reason not detected')

        score=.50 + .12*bool(current.get('source_q_number')) + .10*bool(current.get('sub_unit')) + .08*bool(current.get('k_level')) + .08*bool(current.get('question_type')) + .07*bool(current.get('answer_key'))
        current['parser_confidence']=round(min(score,.99),2)
        current['parse_status']='warning' if current['warnings'] else 'ready'
        qs.append(current)
        current=None; in_match=False; in_ar=False

    def start(body,qtype=None,src=None):
        nonlocal current,in_match,in_ar
        flush()
        qt=qtype or active.get('question_type') or 'VSA'
        body=clean(body)

        # Inline options are common in the real Hindi bank.
        opts=option_tokens(body)
        if opts:
            first=re.search(r'(?:^|\s)[A-Da-d]\s*[\.)\-:]\s*',body)
            if first:
                body=clean(body[:first.start()])
        current={
            'source_q_number':src,
            'course_code':active.get('code',''),
            'question_text':body,
            'unit_no':int(str(active['sub_unit']).split('.')[0]),
            'sub_unit':active['sub_unit'],
            'section_type':'SECTION-'+sec,
            'k_level':active['k_level'],
            'co_level':'CO'+active['k_level'][1:],
            'question_type':qt,
            'marks':marks.get(sec,1),
            'options':opts.copy(),
            'answer_key':'',
            'warnings':[],
            'assertion':'',
            'reason':'',
            'passage_text':'',
            'sub_questions':[],
            'match_text':''
        }
        in_match=qt=='MATCH'
        in_ar=qt=='ASSERTION_REASON'

    def capture_inline_key(line):
        m=re.search(r'\b(?:Key|Answer|Ans)\s*[:=]?\s*([A-Da-d])\s*$',line,re.I)
        if not m: return line,''
        return clean(line[:m.start()]),m.group(1).upper()

    for _,line,kind,num in blocks:
        if noise(line):
            continue

        ss=section(line)
        if ss:
            flush(); sec=ss; continue

        md=metadata(line)
        if md:
            changed=any(md.get(k) and md.get(k)!=active.get(k) for k in ('sub_unit','k_level','question_type','code'))
            if changed and current:
                flush()
            active.update(md)
            continue

        # Standalone answer keys, including the real file's "Key a" form.
        ak=answer(line)
        if ak:
            if current: current['answer_key']=ak
            continue

        line_no_key,key=capture_inline_key(line)
        if key:
            line=line_no_key
            if current:
                current['answer_key']=key

        u=unit(line); kk=klevel(line)
        if u or kk:
            if current and ((u and u!=active['sub_unit']) or (kk and kk!=active['k_level'])):
                flush()
            if u: active['sub_unit']=u
            if kk: active['k_level']=kk
            continue

        if re.search(r'\b(?:match the following|match\s+the|reliez|associez|பொருத்துக)\b',line,re.I):
            n=numbered(line)
            start(n[1] if n else line,'MATCH',n[0] if n else None)
            continue

        if re.search(r'\b(?:assertion\s*(?:and|&)\s*reason|assertion\s*&\s*reasoning)\b',line,re.I):
            start(line,'ASSERTION_REASON',None)
            continue

        n=numbered(line)
        auto_q=(num is not None and fmts.get(num)=='decimal' and not in_match and not option_tokens(line))
        if n and not in_match:
            body=n[1]
            start(body,active.get('question_type'),n[0])
            continue
        if auto_q:
            start(line,active.get('question_type'),None)
            continue

        if not current:
            if active.get('question_type') and re.match(r'^(?:Explain|Define|Describe|Discuss|State|Write|Why|What|How|When|Who|Which|Comment|Analyse|Analyze|Assess|Evaluate|Examine|Critically)\b',line,re.I):
                start(line,active['question_type'],None)
            continue

        # Assertion/Reason labels must be retained separately.
        if re.match(r'^assertion\s*[:：]',line,re.I):
            current['assertion']=line
            continue
        if re.match(r'^reason\s*[:：]',line,re.I):
            current['reason']=line
            continue

        opts=option_tokens(line)
        if opts:
            current.setdefault('options',{}).update(opts)
            continue

        if in_match:
            current['match_text']=clean(current.get('match_text','')+' '+line)
            continue

        if current.get('question_type')=='PASSAGE':
            current['passage_text']=clean(current.get('passage_text','')+' '+line)
        else:
            current['question_text']=clean(current['question_text']+' '+line)

    flush()

    for q in qs:
        if q['question_type']=='VSA' and len(q.get('options',{}))>=2:
            q['question_type']='MCQ'
        q['parse_status']='warning' if q.get('warnings') else 'ready'
    return qs

def main():
    path=sys.argv[1];marks={}
    if '--section_marks' in sys.argv:
        try:marks=json.loads(sys.argv[sys.argv.index('--section_marks')+1])
        except Exception:marks={}
    try:
        qs=parse_docx(path,marks)
        print(json.dumps({'success':True,'count':len(qs),'questions':qs,'parser_version':'docx-structure-v2.1-hindi-regression'},ensure_ascii=False))
    except Exception as e:
        print(json.dumps({'success':False,'message':str(e),'parser_version':'docx-structure-v2.0'},ensure_ascii=False));sys.exit(1)

if __name__=='__main__':main()
