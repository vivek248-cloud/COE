# HCC Question Bank Template

## Recommended structured format

Use **one row/object per logical question**. There is no 30-question limit. Upload the complete question pool (up to the application 50 MB file limit).

### Columns

| Column | Required | Format |
|---|---|---|
| `q_number` | No | Source/display number |
| `unit_no` | Yes | 1–5 (or configured unit range) |
| `section_type` | Yes | Part A, Part B, Part C, VSA, MCQ, Match, Assertion, Case Study, etc. |
| `marks` | Yes | Number |
| `k_level` | Yes | K1–K6 |
| `co_level` | Yes | CO1–CO6 |
| `question_text` | Yes | UTF-8 Unicode text |
| `formula_latex` | No | LaTeX without outer `$` |

### Example

```text
5,2,Part B,13,K4,CO2,Calculate the value of x for x^2+5x+6=0.,x^2+5x+6=0
```

### Languages

UTF-8 is used. Tamil, Hindi, French, English and mixed-language questions can be stored without converting them to ASCII.

### Existing DOCX/PDF files

The uploader also supports existing question-bank documents. It now ignores common non-question content such as section/K-level headings, `Key:`/`Answer:` rows, examples and repeated metadata headers. For complex legacy documents, the structured XLSX/CSV/JSON template is the most deterministic format.

## DOCX template

Use the supplied `question_bank_template.docx` when staff must work in Word. Each logical item starts with `Q1.`, `Q2.`, etc. Optional metadata tags can be placed on the same line:

`[UNIT:1] [K:K1] [CO:CO1] [MARKS:2] [TYPE:MCQ]`

The importer removes these tags from the stored question text and stores the values as metadata.
