# COE Staff Question Bank Upload Standard — v3

## Purpose
The staff upload template is intentionally simple. Teaching staff are not required to understand the internal question-bank schema.

Staff provide only: **Q.No, Section, Marks, K-Level, CO and Question**.

This replaces the previous complex template that required Unit, Sub-Unit, Question Type, options and answer-key fields.

## Preferred DOCX block

Q.NO: 1
SECTION: A
MARKS: 1
K-LEVEL: K1
CO: CO1
QUESTION: Which of the following is a linear data structure?
(a) Tree
(b) Graph
(c) Array
(d) Heap

Q.NO: 2
SECTION: A
MARKS: 2
K-LEVEL: K2
CO: CO1
QUESTION: Explain the main concept briefly.

Q.NO: 3
SECTION: B
MARKS: 5
K-LEVEL: K3
CO: CO2
QUESTION: Discuss the significance of the concept.

Q.NO: 4
SECTION: C
MARKS: 10
K-LEVEL: K4
CO: CO3
QUESTION: Analyse the topic with suitable examples.

## Excel / CSV format

| Q.No | Section | Marks | K-Level | CO | Question |
|---:|---|---:|---|---|---|
| 1 | A | 1 | K1 | CO1 | Complete question text... |
| 2 | A | 2 | K2 | CO1 | Complete question text... |
| 3 | B | 5 | K3 | CO2 | Complete question text... |
| 4 | C | 10 | K4 | CO3 | Complete question text... |

The question cell can contain line breaks and Unicode text.

## MCQ handling
Staff do not need a separate Question Type column. Write MCQ options inside the Question field using (a), (b), (c), (d) or A., B., C., D. The importer attempts to extract the options and classify the question as MCQ.

The importer also attempts to recognize Match the Following, Assertion/Reason, Very Short Answer, Paragraph/Short Answer and Essay structures.

## Optional information
Unit / Sub-Unit is optional. If the source contains Unit or 1.1-style headings, the importer captures them when possible.

Answer keys are optional. Existing source lines such as Key: B or Answer: B are captured when present.

## Important parsing rule
Values explicitly entered by staff are authoritative. Marks, Section, K-Level, CO and Question Number must be preserved exactly as supplied. The importer must not infer CO from K-Level. If CO is missing, the question is flagged for review.

The importer must not replace an explicit question mark value with a section default.

## Legacy question banks
Existing institutional DOCX/PDF banks remain supported. The importer attempts to detect Question Number, Section, Marks, K-Level, CO, Unit/Sub-Unit, Question Type, MCQ options, Assertion/Reason, Match structures and Answer Keys.

Inconsistent or incomplete legacy data is surfaced as a validation warning instead of being silently rewritten.

## Staff upload workflow
1. Download the Staff Simple Template.
2. Enter Q.No, Section, Marks, K-Level, CO and Question.
3. Upload DOCX/XLSX/CSV.
4. The system extracts the questions.
5. The preview shows Section, Marks, K-Level, CO and Question.
6. Staff can correct extraction warnings before submission.
7. Duplicate checking runs before final save.

## COE workflow
COE receives the submitted structured question bank, reviews/edits questions, creates and manages blueprints, and uses Section, Marks, K-Level, CO, Unit/Sub-Unit and history constraints during paper generation.

## Evidence from the current standardized Hindi bank
The current standardized bank already shows question-level section and K-level information; for example Question 1 is Unit 1.1 / K1 / MCQ, while Question 37 is Section B / Unit 1.1 / K2 / PARAGRAPH. fileciteturn61file0L17-L31 fileciteturn61file0L454-L463

The new staff format deliberately removes fields staff do not need to maintain manually.

## Generated templates
The portal template downloader generates the same six-field structure for English, Tamil, Hindi and French: DOCX labelled blocks, XLSX six-column sheet and CSV six-column UTF-8 sheet. JSON is no longer presented as the normal staff upload format.