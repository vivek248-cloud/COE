# HCC Standard Question Bank Template V5

Use one logical question per row (CSV/XLSX) or one `Qn.` block (DOCX). The importer supports English, Tamil, Hindi, French and mixed Unicode text.

## Standard columns

| Column | Required | Purpose |
|---|---|---|
| `q_number` | No | Source question number |
| `unit_no` | **Yes** | Unit 1–5 |
| `sub_unit` | **Yes** | Sub-unit such as 1.1, 2.3 |
| `section_type` | **Yes** | SECTION-A/B/C/D or Part A/B/C/D |
| `marks` | **Yes** | Marks for this question |
| `k_level` | **Yes** | K1–K6 |
| `co_level` | **Yes** | CO1–CO6 (or institution-configured CO range) |
| `question_type` | No | MCQ, VSA, MATCH, ASSERTION_REASON, PARAGRAPH, ESSAY, etc. |
| `question_text` | **Yes** | Complete question text |
| `options` | No | JSON or `|` separated MCQ options |
| `answer_key` | No | Correct answer/solution key |
| `image_url` | No | Relative/public image path or data URI |
| `formula_latex` | No | LaTeX formula without outer `$` |

### Excel/CSV example

```text
q_number,unit_no,sub_unit,section_type,marks,k_level,co_level,question_type,question_text,options,answer_key,image_url,formula_latex
1,1,1.1,SECTION-A,2,K1,CO1,MCQ,Which data structure follows LIFO?,"(a) Queue | (b) Stack | (c) Tree | (d) Graph",B,,
```

### Word example

```text
Q1. [UNIT:1] [SUB:1.1] [SECTION:SECTION-A] [MARKS:2] [K:K1] [CO:CO1] [TYPE:MCQ]
Which data structure follows LIFO?
(a) Queue
(b) Stack
(c) Tree
(d) Graph
Answer: B
```

## Important rules
- Do not merge multiple questions into one spreadsheet row.
- Do not put question numbers inside `question_text` when using CSV/XLSX.
- For Word, each logical question starts with `Q1.`, `Q2.`, etc.
- Embedded Word images are preserved and carried into the generated paper.
- Excel can reference a question image through `image_url`; uploaded images are also supported by the question-image uploader.
- The importer never silently translates uploaded text.
- Duplicate protection compares the logical question payload, including MCQ options, not only the stem.
