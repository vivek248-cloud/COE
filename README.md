# Holy Cross College (Autonomous), Tiruchirappalli – 620 002
## Outcome-Based Education (OBE) Question Paper Generation & Examination System

---

### Key System Capabilities & Architectural Features

#### 1. Replicated Institutional Question Paper Layout (`U23BC3ALT05.pdf`)
* **Standard Institutional Header Hierarchy:**
  * Top Institutional Header: **HOLY CROSS COLLEGE (AUTONOMOUS), TIRUCHIRAPPALLI – 620 002**
  * School Hierarchy: School name (e.g. *SCHOOL OF PHYSICAL SCIENCES / MANAGEMENT STUDIES / HUMANITIES*)
  * Exam Specification: Semester Examination line (e.g. *II U.G. Degree Examination, Semester-III, November 2026*)
  * Course Allocation Line: Degree & Discipline (e.g. *PART III - COMMERCE: CORPORATE ACCOUNTING*)
  * Course Title & Code block: Course Name in bold with right-aligned **Paper Code** (e.g. `U23BC3ALT05`)
  * Examination Parameters: **Time: 3 Hours** | **Max. Marks: 75** (or dynamic configured max marks)
* **4-Section OBE Structure (75 Marks Total):**
  * **SECTION – A ($20 \times 1 = 20\text{ Marks}$):**
    * *Part I – Answer all the Questions ($10 \times 1 = 10\text{ Marks}$)*: Questions 1 to 10 (Multiple Choice Questions with inline `(a)`, `(b)`, `(c)`, `(d)` options, or Fill in the Blanks / Match the Following).
    * *Part II – Answer all the Questions ($10 \times 1 = 10\text{ Marks}$)*: Questions 11 to 20 (Objective / Very Short Answer questions).
  * **SECTION – B ($5 \times 5 = 25\text{ Marks}$):**
    * *Answer all the Questions. Either or type.*: Questions 21 to 25 formatted with explicit `(a)` and `(b)` **(OR)** sub-questions paired across Units I through V.
  * **SECTION – C ($2 \times 10 = 20\text{ Marks}$):**
    * *Answer any TWO Questions.*: Questions 26, 27, and 28 (Descriptive / Problem-solving).
  * **SECTION – D ($1 \times 10 = 10\text{ Marks}$):**
    * *Answer the following Question (Compulsory).*: Question 29 / 30 (Application / Case study / Critical thinking).
* **OBE Competency Indicators:**
  * Every single question features right-aligned **Bloom’s Taxonomy Cognitive Level** (e.g. `[K1]`, `[K2]`, `[K3]`, `[K4]`, `[K5]`, `[K6]`) and **Course Outcome Mapping** (e.g. `[CO1]`, `[CO2]`, `[CO3]`, `[CO4]`, `[CO5]`).
  * **Rule Enforced:** Course Outcome level is automatically aligned with Bloom's Cognitive K-Level ($K_1 \to CO_1$, $K_2 \to CO_2$, $K_3 \to CO_3$, $K_4 \to CO_4$, $K_5 \to CO_5$, $K_6 \to CO_6$).

---

#### 2. Staff Question Bank Upload & Duplicate Resolution Engine
* **Duplicate Detection:**
  * Checks for duplicates both within the uploaded file and against the existing database question pool for the assigned course code.
* **Inline Replace Options:**
  * When duplicates are identified, staff are presented with an interactive resolution interface:
    * **`Replace Existing`**: Overwrites the existing question in the bank and database.
    * **`Append as New`**: Keeps both existing and new questions.
    * **`Skip Duplicate`**: Discards the duplicate from being imported.
  * Batch action buttons: *Replace All Matching*, *Append All*, and *Skip All Duplicates*.
  * Duplicate cards clearly show: Question Number, Section, Cognitive K-Level, and Question Text.
* **Support for 250+ Question Banks:**
  * Seamlessly appends large question batches (250+ questions) into the course repository.

---

#### 3. Answer Key Table & Verification Repository
* **Dedicated Database Storage (`answer_keys` table):**
  * Records `course_code`, `question_id`, `bank_id`, `q_number`, `unit_no`, `sub_unit`, `section_type`, `k_level`, `co_level`, and `answer_key`.
* **Universal Answer Extraction:**
  * Automatically extracts answer keys from uploaded DOCX, PDF, CSV, XLSX, and JSON files (`Answer: (a)...`, `Key: B`, `விடை: ...`, `Réponse: ...`).
* **Answer Key Viewer:**
  * Built-in answer solution viewers in both Faculty portal and HOD review workspace.

---

#### 4. Role-Based Workflow: Faculty $\to$ HOD $\to$ COE
* **Teaching Staff Role:**
  * Uploads / edits question bank $\to$ Clicks **"Submit to HOD"** button.
  * Status set to `Submitted to HOD`.
* **Head of Department (HOD) Role:**
  * Reviews all question banks uploaded by department faculty.
  * **Multi-Filter Verification Workspace:** Quick filter pills for **Section A**, **Section B**, **Section C**, **Section D**, **All Units (I to V)**, and **K-Levels (K1 to K6)**.
  * **"Check Duplicates" Button:** Performs automated similarity scan across questions. If duplicates are found, launches a rich SweetAlert2 modal displaying Question Number, Section, K-Level, and Question Text.
  * **"Submit to COE" Button:** Marks the bank as HOD-Approved and forwards it to the COE Office for paper generation.

---

#### 5. Master Blueprint Matrix (`Tss.pdf`) & Dynamic Rule Engine
* **Tss.pdf Matrix Structure:**
  * Sub-unit rows: Unit I ($1.1$ to $1.5$), Unit II ($2.1$ to $2.5$), Unit III ($3.1$ to $3.5$), Unit IV ($4.1$ to $4.5$), Unit V ($5.1$ to $5.5$).
  * Categories: `AR (K2)`, `Match (K1)`, `MCQ (K1, K2, K3)`, `VSA (K1, K2, K3, K4)`, `Paragraph (K1, K2, K3, K4, K5)`, `Essay (K1, K2, K4, K5)`.
* **Dynamic "+ Add Row" Feature:** Allows adding custom sub-unit rows (e.g. Unit I - 1.6, Unit VI - 6.1) with real-time total recalculations.
* **Dynamic "+ Add Column" Feature:** Allows adding custom question categories and Bloom's levels.
* **Unique Blueprint Name:** Enforces unique blueprint names across the institution.
* **History-Aware / Non-Repeating Question Picking:**
  * Tracks historical question usage in `question_usage_history`.
  * When shuffling papers, questions used in previous examination sessions/years are deprioritized, ensuring fresh questions from the available pool are picked without repetition.

---

#### 6. Multi-Language Optimization (Tamil, French, English)
* **Auto-Language Detection:**
  * Analyzes character scripts and vocabulary.
  * Automatically detects English (`en`), Tamil (`ta`), and French (`fr`).
  * Prompts staff with a confirmation alert and configures Unicode extraction and specialized fonts (`Noto Sans Tamil`, `Latha`, standard typography).
* **Language Switcher:** Smooth manual toggles for `🇬🇧 English`, `🇮🇳 தமிழ் (Tamil)`, `🇮🇳 हिन्दी (Hindi)`, and `🇫🇷 Français (French)`. Uploaded question text is never silently translated.

---

#### 7. Hierarchical Compressed File Storage
* Files are structured hierarchically:
  `storage/uploads/{course_code}/sem_{semester}/{academic_year}/`
  * Example: `storage/uploads/U23BC3ALT05/sem_3/2026-2027/U23BC3ALT05_sem_3_2026-2027_bank.json` and `.json.gz`.
* File paths are indexed in `question_banks.archive_path` and `question_banks.source_path`.

---

### Database Tables Summary

1. `pr_x_xxxx_staf_prof_mast`: Faculty profiles, designations, and HOD status.
2. `timetablefaculty`: Timetable allocations.
3. `courses`: Curriculum repository with course codes, titles, and credit configurations.
4. `departments`: Institutional departments.
5. `question_banks`: Stored question banks with JSON payloads and archive paths.
6. `questions`: Individual relational question pool with units, sub-units, sections, and Bloom levels.
7. `answer_keys`: Question answer keys linked to course codes and question IDs.
8. `question_usage_history`: History of picked questions across academic years and exam sessions.
9. `blueprints`: Institutional blueprint definitions with master matrix configs.
10. `generated_papers`: Generated multi-set examination papers with structured JSON.
11. `qps_bank_versions`: Version control history for question banks.
12. `qps_upload_history`: Audit trail of uploaded files.
13. `qps_audit_log`: System-wide audit logging.

---

### Quick Start / Default Credentials

- **Faculty / HOD Portal:**
  - Login with Staff Code (e.g. `SF0199`, `TAS2014M02`, `TCO2017M01`, `TBC2018M04`)
  - Default Password: `hcc123`
- **COE Central Portal:**
  - Username: `coe`
  - Password: `coe@123`
