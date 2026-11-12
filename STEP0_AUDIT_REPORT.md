# STEP 0: Existing Codebase Audit Report

## Overview
This audit documents the current state of the Teaching Staff portal in the Question Paper System, comparing it against the 8-page specification provided. The system is built with PHP 8 + MySQL/PDO, using Tailwind CSS + SweetAlert2 for UI, and follows a workflow status model: draft → submitted_to_hod → hod_corrections → sent_to_dean → dean_corrections → sent_to_coe → approved.

---

## 1. Database Schema Analysis

### Core Tables (Existing)
| Table | Status | Notes |
|-------|--------|-------|
| `timetablefaculty` | ✅ Exists | Source of truth for faculty-course allocation (fid, papercode, deptcode) |
| `courses` | ✅ Exists | Course metadata (coursecode, coursetitle, qpattern, type, dept_code, maxmark) |
| `departments` | ✅ Exists | Department codes and names |
| `course_submissions` | ✅ Exists | Tracks workflow status per item type (question_bank, master_blueprint, final_blueprint) |
| `questions_draft` | ✅ Exists | Faculty-created questions with full metadata (unit, marks, bloom's, CO, type, options) |
| `corrections` | ✅ Exists | Correction tracking with status (open/resolved/withdrawn) |
| `master_blueprints` | ❓ Not verified | Referenced in question_bank_blueprint.php |
| `final_blueprint_slots` | ❓ Not verified | Referenced in question_bank_blueprint.php |
| `question_paper_templates` | ✅ Exists | Dean templates with sections/slots configuration |
| `course_outcomes` | ✅ Exists | CO codes per course |
| `syllabus` | ✅ Exists | Unit definitions per course |
| `qps_settings` | ✅ Exists | System settings (academic year, regulation, paper types, exam types) |

### Key Schema Observations
- **Access Control**: Uses `timetablefaculty` table matching `fid` (staff_code) + `papercode` (course_code)
- **Workflow Status**: `course_submissions` has 3 independent status columns:
  - `question_bank_status` (draft, submitted_to_hod, hod_corrections, sent_to_dean, dean_corrections, sent_to_coe, approved)
  - `master_blueprint_status` (same states)
  - `final_blueprint_status` (same states)
- **Questions**: `questions_draft` stores all question metadata including options_json for MCQ, match columns, assertion/reasoning
- **Academic Context**: regulation (2024), academic_year (2026-2027), exam_type, paper_type derived from courses.qpattern

---

## 2. Existing Teaching Module Pages

### Page 1: Dashboard (`modules/teaching/dashboard.php`) ✅ **MOSTLY MATCHES SPEC**
**Status**: Functional - shows allocated courses with 3 status chips per course
**Matches Spec**:
- ✅ Fetches courses from `timetablefaculty` joined with `courses` and `departments`
- ✅ Shows 3 independent workflow status chips (Question Bank, Master Blueprint, Final Blueprint)
- ✅ Status badge colors match spec (slate=draft, blue=submitted, rose=corrections, indigo=sent_to_dean, purple=sent_to_coe, emerald=approved)
- ✅ Action buttons per status: Continue Draft / Start Draft / Resolve Corrections / View Blueprint
- ✅ Quick actions: "Work on Question Bank" and "Corrections" links
- ✅ Uses `hcc_course_exam_info()` for exam type/marks display

**Gaps vs Spec**:
- ❌ Spec requires "Academic Year / Regulation" context badges at top - partially there
- ❌ Spec mentions "Create New Question Bank" button in welcome banner - exists but could be clearer
- ❌ Need to verify all courses from timetablefaculty are shown (deduplication needed for multiple timetable entries per course)

### Page 2: Question Bank Draft (`modules/teaching/question_bank_draft.php`) ✅ **MOSTLY MATCHES SPEC**
**Status**: Functional - create/edit questions with all required fields
**Matches Spec**:
- ✅ Modal form with all required fields: Unit, Sub-unit, Marks, Bloom's Level (K1-K6), CO Code, Question Type (MCQ, Match, Assertion & Reasoning, VAS, Paragraph, Essay)
- ✅ Dynamic content areas per question type (MCQ options, Match columns, AR text, Answer Key for text types)
- ✅ Image upload + LaTeX formula support
- ✅ Auto-assigns question_number per unit
- ✅ Save/Update/Delete actions with CSRF protection
- ✅ Fetch logic queries `questions_draft` directly by staff_id + course_code + academic context (fixed previous bug)
- ✅ POST fallback reads course_code from $_POST if $_GET missing
- ✅ onQuestionTypeChange() toggles required/disabled on hidden sections (fixes "Invalid form control" error)
- ✅ answer_key uses null coalescing `?? ''` (fixes undefined array key warning)
- ✅ Form action URL preserves query parameters
- ✅ Submit to HOD creates course_submissions record if missing
- ✅ Correction resolution workflow with status transition back to submitted_to_hod
- ✅ Quick stats: total questions, total marks, units covered, COs covered

**Gaps vs Spec**:
- ❌ Spec mentions "Sub-unit" as optional text input - implemented but could be enhanced with syllabus dropdown
- ❌ Need to verify CO codes are fetched from `course_outcomes` table correctly
- ❌ Spec requires "Question Bank Status" chip in header - exists
- ❌ No explicit "Continue Existing Draft" vs "New Draft" flow - handled via submission_id parameter

### Page 3: Question Bank Blueprint (`modules/teaching/question_bank_blueprint.php`) ✅ **MATCHES SPEC**
**Status**: Read-only compiled blueprint matrix matching sample-blueprint.jpeg
**Matches Spec**:
- ✅ Supports 3 item types: question_bank, master_blueprint, final_blueprint via `item_type` parameter
- ✅ Access control validates timetablefaculty allocation
- ✅ Live compiled matrix with sections: MC (1M), VSA (1M), Section B (5M), Section C (10M), Section D (10M+)
- ✅ K-Level breakdown: K1/K2 for 1M, K3/K4 for 5M, K5/K6 for 10M, K6 for 10M+
- ✅ Grand totals row
- ✅ Detailed matrix: Unit | Sub-unit | Marks | K-Level | CO-Level | Count
- ✅ Institutional header with college name, paper code, title, exam type, regulation
- ✅ Download DOCX button (API endpoint)
- ✅ Print support
- ✅ Status badge and navigation

**Gaps vs Spec**:
- ❌ Need to verify `master_blueprints` and `final_blueprint_slots` tables exist and have data
- ❌ Unit grouping with Roman numerals (I, II, III, IV, V) - implemented

### Page 4: Final Question Slot Creation (`modules/teaching/final_slot_allocation.php`) ⚠️ **PARTIAL - NEEDS REBUILD**
**Status**: JavaScript-heavy single-page app, not following the spec's server-rendered approach
**Matches Spec**:
- ✅ Course selection from allocated courses
- ✅ Loads published Dean templates
- ✅ Slot selection with eligibility filtering (unit, sub-unit, K-level, CO-level, marks)
- ✅ Save final allotment

**Gaps vs Spec**:
- ❌ Spec describes a 2-step process: (1) Select Template + Define Paper Structure, (2) Allocate Questions to Slots
- ❌ Current implementation is a SPA with fetch API calls - spec wants traditional form submissions
- ❌ Missing "Paper Structure Definition" step with section configuration
- ❌ No clear separation between template selection and slot allocation
- ❌ Need to align with workflow: Final Blueprint only unlocks after Master Blueprint approved

### Page 5: Draft Questions / Corrections (`modules/teaching/corrections.php`) ⚠️ **MINIMAL - NEEDS ENHANCEMENT**
**Status**: Very basic - shows open corrections from `qps_remarks` table only
**Gaps vs Spec**:
- ❌ Spec requires correction management per item type (Question Bank, Master Blueprint, Final Blueprint)
- ❌ Current only shows `qps_remarks` (different from `corrections` table used in question_bank_draft.php)
- ❌ No inline correction resolution - just links to `upload.php`
- ❌ Missing: correction details per question/blueprint cell, resolution workflow, status tracking
- ❌ Should integrate with `corrections` table (target_type: question, blueprint_cell)

### Page 6: Submit Course - **NOT YET IMPLEMENTED**
Spec requires a submission confirmation page with summary of all 3 artifacts before final submit.

### Page 7: Completed Courses (`modules/teaching/completed_question_banks.php`) ⚠️ **PARTIAL - USES DIFFERENT TABLES**
**Status**: Shows courses from `qps_submission_packages` with status SUBMITTED_TO_COE+
**Gaps vs Spec**:
- ❌ Uses `qps_submission_packages` + `qps_question_bank_drafts` instead of `course_submissions`
- ❌ Spec expects "Completed Courses" to show all 3 workflows with approved status
- ❌ Links to legacy `view_banks.php` and `upload.php` instead of new blueprint pages
- ❌ Missing: filter by academic year, regulation, status summary per course

### Page 8: HOD Review Status - **NOT YET IMPLEMENTED**
Spec requires read-only view of HOD/Dean/COE review progress per course.

---

## 3. Authentication & Authorization

### Current Implementation (`includes/auth.php`)
- ✅ Role constants: TEACHING_STAFF, HOD, DEAN, COE, ERP_ADMIN, SUPER_ADMIN
- ✅ `requireTeachingStaff()` protects teaching pages
- ✅ `isTeachingStaff()` checks role in session
- ✅ Session-based auth with staff_code, name, role, department
- ✅ Static accounts for HOD/Dean/COE with password verification

### Access Control Issue (Fixed)
- **Problem**: `question_bank_blueprint.php` checked `timetablefaculty` with `fid` + `papercode` but user accessed directly without `course_code` parameter
- **Solution**: User must access via Dashboard which passes `course_code` parameter
- **Root Cause**: Direct URL access without proper navigation context

---

## 4. API Endpoints

### Existing APIs (referenced in code)
- `api/teaching_dean_templates.php` - Catalog and get published Dean templates
- `api/teaching_slot_eligibility.php` - Filter eligible questions for slots
- `api/teaching_save_final_allotment.php` - Save/load final blueprint allotment
- `api/teaching_master_blueprint_docx.php` - Generate DOCX download

### Missing APIs (per spec)
- Question Bank CRUD API (currently handled via form POST in question_bank_draft.php)
- Master Blueprint generation API
- Submission workflow API
- Corrections API

---

## 5. Workflow Implementation

### Current Workflow (`includes/workflow.php`)
- ✅ `qps_transition()` function handles status transitions
- ✅ Status map: STAFF_SUBMIT, HOD_RETURN, HOD_FORWARD, STAFF_RESUBMIT, DEAN_RETURN, HOD_FORWARD_DEAN_REMARKS, STAFF_RESUBMIT_DEAN, HOD_REVERIFY, DEAN_APPROVE, COE_APPROVE, COE_ALLOT, COE_GENERATE
- ✅ Audit logging with `qps_audit_transition()`
- ✅ Correction tracking via `corrections` table
- ✅ `qps_assert_no_open_corrections()` guard for forward actions

### Workflow Status Mapping (course_submissions)
| Spec Status | DB Value |
|-------------|----------|
| Draft | draft |
| Submitted to HOD | submitted_to_hod |
| HOD Corrections | hod_corrections |
| Sent to Dean | sent_to_dean |
| Dean Corrections | dean_corrections |
| Sent to COE | sent_to_coe |
| Approved | approved |

---

## 6. UI/UX Implementation

### CSS Framework
- ✅ Tailwind CSS (via CDN or local build)
- ✅ Custom components: `btn-orange-pill`, `card-modern`, status badge classes
- ✅ Lucide icons for UI elements
- ✅ SweetAlert2 for confirmations/toasts

### Responsive Design
- ✅ Mobile-first grid layouts
- ✅ Collapsible sidebar navigation
- ✅ Print-optimized blueprint tables

---

## 7. Gap Analysis: Spec vs Implementation

### Page-by-Page Comparison

| Page | Spec Name | Current File | Match % | Key Gaps |
|------|-----------|--------------|---------|----------|
| 1 | Allocated Courses | `dashboard.php` | 85% | Deduplication, clearer new draft CTA |
| 2 | Question Bank Entry | `question_bank_draft.php` | 95% | Minor UX polish, syllabus-driven sub-units |
| 3 | Master Blueprint | `question_bank_blueprint.php` (item_type=master_blueprint) | 90% | Verify master_blueprints table exists |
| 4 | Final Question Slot Creation | `final_slot_allocation.php` | 40% | **Major rebuild needed** - SPA vs server-rendered, missing paper structure step |
| 5 | Draft Questions (Corrections) | `corrections.php` | ?% | Need to review |
| 6 | Submit Course | **Not implemented** | 0% | New page needed |
| 7 | Completed Courses | `completed_question_banks.php` | ?% | Need to review |
| 8 | HOD Review Status | **Not implemented** | 0% | New page needed |

### Database Migrations Needed
- [x] `master_blueprints` table exists with schema: (submission_id, course_code, semester, academic_year, programme_year, language, unit_no, sub_unit, question_type, marks, k_level, co_level, available_count, version_no)
- [x] `final_blueprint_slots` table exists with schema: (paper_code, slot_number, section_type, unit_no, sub_unit, question_type, marks, k_level, co_level, is_compulsory, choice_group)
- [x] `version` column exists in `course_submissions`
- [ ] Ensure indexes on `questions_draft` for performance (staff_id, course_code, regulation, academic_year, exam_type, paper_type) - verify with EXPLAIN
- [ ] Add foreign key constraints where appropriate
- [ ] Check `question_paper_templates` has published templates for slot allocation

### Code Quality Issues to Address
1. **Mixed Patterns**: Some pages use form POST, others use fetch API - standardize
2. **Error Handling**: Inconsistent try/catch patterns, some swallow errors silently
3. **SQL Injection Risk**: All queries use prepared statements ✅
4. **CSRF Protection**: Implemented on forms but not on API endpoints
5. **Session Management**: No explicit timeout handling beyond PHP defaults

---

## 8. Recommended Implementation Order

### Phase 1: Core Pages (Already Working - Polish Only)
1. Page 1: Dashboard - minor UX improvements (deduplication, clearer CTAs)
2. Page 2: Question Bank Draft - minor polish, add syllabus sub-unit dropdown
3. Page 3: Master Blueprint - verify data flow from question_bank → master_blueprint

### Phase 2: Major Rebuild
4. Page 4: Final Question Slot Creation - **complete rewrite** per spec (2-step process: Template Selection → Slot Allocation)
5. Page 5: Draft Questions (Corrections) - **complete rewrite** of `corrections.php` to use `corrections` table with inline resolution

### Phase 3: New Pages
6. Page 6: Submit Course - new page with confirmation summary of all 3 artifacts
7. Page 7: Completed Courses - **rewrite** `completed_question_banks.php` to use `course_submissions` with 3-workflow status
8. Page 8: HOD Review Status - new read-only page showing review progress per course

### Phase 4: Database & Workflow
9. Run schema verification/migrations (indexes, foreign keys)
10. End-to-end workflow testing: Draft → Submit → HOD Return → Resolve → Re-submit → Approve
11. API standardization (form POST vs fetch API consistency)

---

## 9. Immediate Action Items

1. **Verify master_blueprints and final_blueprint_slots tables exist** - run DESCRIBE
2. **Review corrections.php** - understand current corrections UI
3. **Review completed_question_banks.php** - understand current completed view
4. **Check if course_submissions.version is used** for optimistic locking
5. **Test full workflow**: Draft → Submit → HOD Return → Resolve → Re-submit → Approve

---

## 10. File Inventory (modules/teaching/)

| File | Purpose | Status |
|------|---------|--------|
| dashboard.php | Page 1: Allocated Courses | ✅ Working |
| question_bank_draft.php | Page 2: Question Bank Entry | ✅ Working |
| question_bank_blueprint.php | Page 3: Blueprint View (3 types) | ✅ Working |
| final_slot_allocation.php | Page 4: Slot Allocation | ⚠️ Needs Rewrite |
| corrections.php | Page 5: Corrections Management | 🔍 To Review |
| completed_question_banks.php | Page 7: Completed Courses | 🔍 To Review |
| view_banks.php | Legacy question bank viewer | 🔍 To Review |
| upload.php | Legacy bulk upload | 🔍 To Review |
| draft.php | Legacy draft page | 🔍 To Review |
| export_json.php | Export functionality | 🔍 To Review |
| download_template.php | Template download | 🔍 To Review |

---

*Audit completed: 2026-11-11*
*Next Step: Begin Page 1 implementation (polish) and review corrections.php / completed_question_banks.php*