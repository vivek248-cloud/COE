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

def parse_docx(path,section_marks=None):
    marks={**DEFAULT_MARKS,**(section_marks or {})}
    doc=docx.Document(path)
    fmts=numbering_formats(path)
    blocks=[]
    for i,p in enumerate(doc.paragraphs):
        t=clean(p.text)
        if t:blocks.append({'i':i,'text':t,'num':numinfo(p)})
    by=defaultdict(list)
    for b in blocks:
        if b['num']:by[b['num'][0]].append(b)
    roles={}
    for nid,items in by.items():
        f=fmts.get(nid)
        q=sum(1 for x in items if x['text'] and not match_row(x['text']) and not re.match(r'^[A-Ea-e]\s*[\.)-]',x['text']))
        if f=='decimal' and q:roles[nid]='question'
        elif f in ('lowerLetter','upperLetter') or any(match_row(x['text']) for x in items):roles[nid]='option'
        else:roles[nid]='other'

    qs=[];cur=None;sec='A';sub='1.1';k='K1';match=False;passage=False;opt_counts=defaultdict(int)

    def flush():
        nonlocal cur,match,passage
        if not cur:return
        text=clean(cur.get('question_text',''))
        if not text:
            cur=None;match=False;passage=False;return
        if cur.get('question_type')=='PASSAGE':
            cur['question_text']=text
        if cur.get('question_type')=='MCQ' and len(cur.get('options',{}))<2:
            cur.setdefault('warnings',[]).append('MCQ options incomplete')
        if cur.get('question_type')=='MATCH' and not cur.get('answer_key'):
            cur.setdefault('warnings',[]).append('Match answer key not detected')
        cur['q_number']=len(qs)+1
        cur['unit_no']=int(cur.get('unit_no') or sub.split('.')[0])
        cur['sub_unit']=cur.get('sub_unit') or sub
        cur['section_type']=cur.get('section_type') or 'SECTION-'+sec
        cur['k_level']=cur.get('k_level') or k
        cur['co_level']=cur.get('co_level') or ('CO'+cur['k_level'].replace('K',''))
        cur['marks']=int(cur.get('marks') or marks.get(sec,1))
        cur.setdefault('options',{});cur.setdefault('answer_key','')
        score=.55+.10*(bool(cur.get('source_q_number')))+.10*bool(cur.get('sub_unit'))+.08*bool(cur.get('k_level'))+.05*bool(cur.get('answer_key'))+.05*(cur.get('question_type') in ('MCQ','MATCH','ASSERTION_REASON','PASSAGE'))
        cur['parser_confidence']=round(min(score,.99),2)
        cur['parse_status']='warning' if cur.get('warnings') else 'ready'
        qs.append(cur);cur=None;match=False;passage=False

    def start(text,qtype='VSA',source=None):
        nonlocal cur,match,passage
        flush()
        cur={'source_q_number':source,'question_text':strip_inline_key(text),'unit_no':int(sub.split('.')[0]),'sub_unit':sub,
             'section_type':'SECTION-'+sec,'k_level':k,'co_level':'CO'+k.replace('K',''),
             'marks':marks.get(sec,1),'question_type':qtype,'options':{},'answer_key':'',
             'warnings':[],'passage_text':'','sub_questions':[]}
        match=qtype=='MATCH';passage=qtype=='PASSAGE'

    for b in blocks:
        line=b['text'];nid=b['num'][0] if b['num'] else None;fmt=fmts.get(nid) if nid else None
        if noise(line):continue

        ss=section(line)
        if ss:
            flush();sec=ss;k=klevel(line) or k;continue

        kk=klevel(line)
        if re.match(r'^Assertion\s*&\s*Reasoning\s*-\s*K\s*[1-6]',line,re.I):
            flush();k=kk or k;continue
        if kk and (re.match(r'^K\s*[1-6]\b',line,re.I) or re.search(r'\b(?:SECTION|PART)\b',line,re.I)):
            flush();k=kk;continue

        su=subunit(line)
        if su:
            flush();sub=su;k=klevel(line) or k;continue

        ak=answer(line)
        if ak:
            if cur:cur['answer_key']=ak
            continue

        if re.match(r'^(?:Reliez|Match|Associez|பொருத்துக)\b',line,re.I):
            start(line,'MATCH');continue
        if re.match(r'^(?:L[’\']énoncé et la justification|Assertion\s*(?:and|&)\s*Reason)',line,re.I):
            start(line,'ASSERTION_REASON');continue
        if fmt=='decimal' and re.match(r'^(?:Lisez|Read)\b',line,re.I) and re.search(r'(?:question|questions|répondez)',line,re.I):
            start(line,'PASSAGE');continue

        if match and re.match(r'^\s*\d+\s*[\.)-]\s*',line):
            cur['question_text']=clean(cur['question_text']+'\n'+line);continue

        qm=re.match(r'^\s*(?:Q(?:uestion)?\s*)?(\d+)\s*[\.)\-:]\s*(.+)$',line,re.I)
        if qm and not passage:
            body=qm.group(2);start(body,'MATCH' if re.match(r'^(?:Reliez|Match|Associez)',body,re.I) else 'VSA',qm.group(1));continue

        if passage:
            if nid and roles.get(nid)=='option' and not match_row(line):
                cur['sub_questions'].append({'text':line,'answer_key':''})
            else:cur['passage_text']=clean(cur.get('passage_text','')+' '+line)
            continue

        if nid and fmt=='decimal' and roles.get(nid)=='question' and not match and not (cur and cur.get('question_type')=='ASSERTION_REASON'):
            start(line,'VSA',None);continue

        if cur:
            if cur.get('question_type')=='ASSERTION_REASON':
                if re.match(r'^(?:L[’\']énoncé|assertion)\s*[:：]',line,re.I):cur['assertion']=line
                elif re.match(r'^(?:La justification|reason)\s*[:：]',line,re.I):cur['reason']=line
                elif nid and fmt in ('lowerLetter','upperLetter'):
                    opt_counts[nid]+=1;cur.setdefault('options',{})[chr(64+min(opt_counts[nid],26))]=strip_inline_key(line)
                else:
                    opts=inline_options(line)
                    if opts:cur['options'].update(opts)
                    else:cur['question_text']=clean(cur['question_text']+' '+strip_inline_key(line))
                continue
            if match:
                opts=inline_options(line)
                if opts:cur['options'].update(opts)
                else:cur['question_text']=clean(cur['question_text']+'\n'+line)
                continue
            if nid and fmt in ('lowerLetter','upperLetter'):
                opt_counts[nid]+=1;label=chr(64+min(opt_counts[nid],26));body=strip_inline_key(line)
                opts=inline_options(body)
                if opts:
                    cur['options'].update(opts)
                    if label not in cur['options']:
                        prefix=clean(re.split(r'(?:^|\s)[b-dB-D]\s*[\.)\-:]\s*',body,maxsplit=1,flags=re.I)[0])
                        if prefix:cur['options'][label]=prefix
                else:cur['options'][label]=body
                continue
            opts=inline_options(line)
            if opts:cur['options'].update(opts)
            else:cur['question_text']=clean(cur['question_text']+' '+strip_inline_key(line))
            continue

        # Only explicit task/question forms are accepted without Word numbering.
        if re.match(r'^(?:Traduisez|Conjuguez|Nommez|Que |Qui |Comment |Combien |Pourquoi |Quelle|Qu’est|Est-ce|Explain|Define|Describe|Discuss|State|Write)\b',line,re.I):
            start(line,'VSA',None)

    flush()
    for q in qs:
        if q['question_type']=='VSA' and len(q.get('options',{}))>=2:q['question_type']='MCQ'
        if q['question_type']=='MCQ' and not q.get('answer_key'):q.setdefault('warnings',[]).append('MCQ answer key missing')
        q['parse_status']='warning' if q.get('warnings') else 'ready'
    return qs

def main():
    path=sys.argv[1];marks={}
    if '--section_marks' in sys.argv:
        try:marks=json.loads(sys.argv[sys.argv.index('--section_marks')+1])
        except Exception:marks={}
    try:
        qs=parse_docx(path,marks)
        print(json.dumps({'success':True,'count':len(qs),'questions':qs,'parser_version':'docx-structure-v2.0'},ensure_ascii=False))
    except Exception as e:
        print(json.dumps({'success':False,'message':str(e),'parser_version':'docx-structure-v2.0'},ensure_ascii=False));sys.exit(1)

if __name__=='__main__':main()
