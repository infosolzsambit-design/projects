# Digital Paper Evaluation System — Project Overview

A web app for **on-screen evaluation of scanned exam answer sheets**. An exam
center uploads question papers and scanned answer-sheet PDFs; admins assign
sheets to teachers; teachers mark each sheet on screen (annotations + per-
question marks) inside a fixed time window; admins handle issues, resets and
reports. Branded as **"CJ Paper Check"** (site title comes from General
Settings).

---

## 1. Tech stack & layout

```
Digital Paper Evaluation System/
├── backend/    Laravel 12 (PHP 8.2) — JSON API only, prefix /api/v1
└── frontend/   Vue 3 SPA (Vite 8, Pinia, Vue Router 5, Tailwind CSS 4)
```

**Backend packages:** laravel/sanctum (API tokens), spatie/laravel-permission
(roles/permissions), owen-it/laravel-auditing (+ custom AuditLogService),
barryvdh/laravel-dompdf (PDF reports), mews/purifier (input sanitizing).
Database: MySQL. **No DB foreign keys** — relations are by convention only.
Tests: PHPUnit feature tests (`php artisan test`, ~500 tests, MySQL).

**Frontend packages:** axios, pinia, vue-router, pdfjs-dist (render PDFs),
pdf-lib (build the "evaluated" PDF with annotations), face-api.js (face
verification), jsqr (read QR codes from PDFs), tesseract.js (OCR of question
papers), xlsx (read Excel/CSV uploads).

**Deployment:** two subdomains on one Apache/Ubuntu server — frontend
(static Vite build) and backend (Laravel; docroot is the project root with an
`.htaccess` rewriting into `public/`). FTP-only access; artisan/composer are
run manually on the server. Frontend reaches the backend via absolute
`VITE_API_BASE_URL` (baked in at build time).

---

## 2. Cross-cutting backend conventions

- **Every request** must send an `X-API-KEY` header (`ApiKeyAuth` middleware),
  plus `Authorization: Bearer <sanctum token>` for authenticated routes.
- **Token pinning** (`PinTokenToClient`): a token is bound to the IP/browser
  that created it — the real anti-theft defense (expiry is just a backstop).
- **SanitizeInput** middleware strips HTML from every string input globally
  (passwords exempt).
- **SecurityHeaders** middleware adds hardening headers on every response
  (`Cross-Origin-Resource-Policy: same-origin`, except `cross-origin` for
  `/storage/*`).
- **RequestId** middleware tags each request for logs.
- **Response shape** (`ApiResponse` trait): `{ status: bool, message, data }`;
  validation errors → 422 with `errors`. All exceptions render as JSON.
- **Index endpoints** follow one multi-purpose pattern: `?id=`, `?status=
  deleted|all`, `?search=`, filters, `?table_fields[]=`, pagination
  (`per_page`, capped by config).
- **Soft deletes + restore** on most masters; **userstamps**
  (created_by/updated_by/deleted_by) via `HasUserstamps`.
- **Audit log**: `AuditLogService::log(event, module, description, …)` for
  business actions; model-level auditing via owen-it.
- **Integer/boolean migration columns always get a `->comment()`**
  explaining the values.
- **Roles checked by ID, never by name**: `config('roles.super_admin_id')` is
  a list (`[1, 5]`); super admins bypass every permission (Gate::before).
- **Exam scoping** for non-super-admins:
  - `HasExamYearScope` → pinned to `?exam_year=` or the current year.
  - `HasExamTypeScope` → pinned to `?exam_type_id=` or the newest active
    exam type.
  - The header's "Examination" + "Exam Year" pickers send these params.
- **Permissions are enforced mostly in the UI** (`authStore.can('...')`);
  several newer pages intentionally have no permission yet.
- **File storage**: the `public` disk writes **directly to
  `backend/public/storage`** (no `storage:link` symlink — the host can't keep
  one reliably). `/storage/*` requests are forced through Laravel
  (`public/.htaccess`) to a route in `routes/web.php` that streams the file
  **with CORS headers** (the Apache host has no mod_headers). Laravel's own
  auto-serve route is disabled (`local` disk `'serve' => false`).

---

## 3. Core data model

| Model / table | Purpose |
|---|---|
| `User` | Everyone who logs in (admins + teachers). Spatie roles/permissions. |
| `TeacherDetail` | 1:1 with a teacher user: emp_code, department_id, designation, e-sign image, `face_descriptor` (128 floats), `face_scan_applicable` flag. |
| `Department`, `Program`, `Course`, `ExamTerm`, `ExamType` | Masters. Program↔Course mapping table exists. |
| `Student` | Student master (roll no, semester, …). |
| `QuestionPaper` | exam_year, course, semester, exam_term, full_marks, time_allotted, **uploaded PDF** (`pdf_path`), status. |
| `QuestionPaperNode` | Recursive question tree of a paper. `mode`: `leaf` (a question with `marks`), `all` (answer all children), `choose` (answer any `choose_count` of the children). Also label, instruction, bloom_level, CO, sort_order, slots_override. |
| `QuestionAnswerSheetMapping` ("packet") | One uploaded batch of answer sheets: question_paper_id, course_id, program_name, semester, exam_term_id, **exam_type_id**, packet_code. |
| `AnswerSheet` | **One physical scanned script** (the central table). See below. |
| `IssueMaster` | Issue types. Well-known IDs in `config/issues.php`: printing_issue_id, timing_issue_id. |
| `GeneralSetting` | Key/value site settings (site_title, logos incl. `footer_logo`, `is_face_scan_applicable`, …). |
| `EmailLog` | Every outbound email (type, recipient, rendered body) — resendable. |
| `Audit` | Audit trail. |
| `PermissionGroup` / `PermissionSubGroup` / `Permission` / `Role` | Permission UI grouping on top of Spatie. |

**`answer_sheets` key columns**
- Identity: `barcode`, `subject_barcode` (the QR code on the script — the
  "script/unique number"), `roll_no`, `name`, `registration_no`,
  `packet_no`, `pdf_path`/`pdf_name`.
- Assignment: `teacher_id`, `assigned_at`, `evaluation_start_date`,
  `evaluation_end_date` (datetimes), `evaluation_time_per_sheet` (minutes,
  null = no limit).
- Evaluation: `marks` (null = not completed), `evaluated_at`,
  `draft_marks`, `draft_marks_breakdown` (JSON, per leaf node id),
  `draft_annotations` (JSON, per page), `consumed_time` (seconds),
  `evaluation_session_token` + `evaluation_session_expires_at`.
- Issues: `issue_master_id`, `issue_raised_by`, `issue_raised_at`,
  `issue_status` (`open` | `resolved` | null), `issue_remarks`,
  `issue_fixed_by`, `issue_fixed_at`, `issue_admin_remarks`.
- Model scopes: `withOpenIssue()`, `withoutOpenIssue()`; `hasOpenIssue()`.

**Annotation JSON** (`draft_annotations`): `{ "<page>": [ {type:'correct'|
'wrong', point:[x,y]}, {type:'pencil', points:[[x,y],…], color:'red'|
'green'}, {type:'blank', start:[x,y], end:[x,y]} ] }` — coordinates are in
**PDF user space** so they survive zoom/rotation. Page 1 (student cover
sheet) is hidden from teachers (blind evaluation), so annotations start on
page 2.

---

## 4. End-to-end workflow

1. **Masters** — admins maintain Departments, Programs, Courses, Exam Terms,
   Exam Types (incl. bulk Excel upload for several).
2. **Teachers** — create/bulk-upload teachers; capture face (face-api.js
   descriptor) and e-sign; per-teacher "face scan applicable" flag.
3. **Question paper setup** — upload the question paper PDF; the structure
   is parsed (text extraction / OCR) and edited in a drag-and-drop tree
   builder (`QuestionPaperStructureBuilder`, recursive
   `QuestionNodeEditor`): groups → questions → sub-parts, with
   all/choose/leaf modes and marks. Validation rule: a `choose` node's
   `choose_count` is checked against its selectable **slot** count.
4. **Answer sheet upload** (`AnswerSheetUploadView`) — pick a question paper
   + exam type, upload the exam-center CSV (one row per script) and the
   scanned PDFs. Client "Check" step: CSV columns, duplicate barcodes,
   row-count vs PDF-count, QR code read from each PDF (jsqr) matched to a
   CSV row; then "Submit" creates the packet + answer sheets (server
   re-validates). Files go to `public/storage/answer-sheets/{mapping_id}/`.
5. **Assign teacher** (`AssignTeacherView`) — search an exam offering, pick
   teachers, "Distribute Equally" or set quantities, set the evaluation
   window (start/end datetime) and optional minutes-per-sheet, then send an
   editable assignment email → `AssignTeacherService::assign()`.
6. **Assigned Teachers list** — per teacher: allocation breakdown modal
   (Sheets / Completed / In Draft / Problem), courses modal, and row actions:
   - **Reassign** (teacher on leave) — move not-completed sheets of a packet
     to other teachers, with email.
   - **Update Time Span** — change the evaluation window + minutes-per-
     sheet for all of a teacher's sheets in one packet.
7. **Teacher evaluation** (sidebar "Evaluate Course"):
   - **Pending Course** — own sheets with `marks` null and **no open
     issue**, grouped by course. "Start Evaluate" → server check
     (`start-evaluation`): face scan required if the global
     `is_face_scan_applicable` is on AND the teacher's own flag is on;
     mints a one-time **evaluation session token**; the marking screen URL
     is `/my-pending-courses/:token/evaluate` (expires; superseded by the
     next click).
   - **Marking screen** (`EvaluatePaperView`) — pdf.js pages + canvas
     overlay tools (select/pencil red|green/correct ✓/wrong ✗/blank box/
     delete, undo/redo), question-tree marks panel
     (`EvaluateQuestionNode`), countdown timer. **Autosaves** a draft
     (debounced + periodic). "Complete" requires every question filled
     and at least one annotation per page, then submits marks **together
     with the final annotations/breakdown**. After the time limit inputs
     lock.
   - **Problem Course** — sheets with an issue: open ones ("Issue Pending",
     cannot be evaluated, hidden from Pending) and resolved ones (shown as
     "Resolved", also back in Pending).
   - **Completed Course** — sheets with marks set (read-only).
8. **Issues** — teacher raises a **Printing** issue (wipes evaluation
   progress; sheet needs rescan) or **Timing** issue (keeps progress; needs
   a new window). Admins get an email. Admin **Notifications** page resolves:
   printing → upload a replacement PDF; timing → set a new window. Teacher
   gets a "resolved" email. Any open issue blocks evaluation server-side.
9. **Reset Evaluation** (admin page) — search a sheet by barcode/subject
   barcode; view program/course/examination/semester/year, front page
   preview, download the uploaded PDF or the **evaluated PDF** (built in the
   browser with pdf-lib: annotations drawn back + "Marks: X / Y" stamp),
   evaluator, window, consumed time; **Reset** (only while now is within the
   sheet's evaluation window) clears marks/drafts/annotations/consumed time,
   keeping the same teacher.
10. **Reports** (sidebar "Report"):
    - **Teacher Wise Evaluation Report** — per teacher × packet counts
      (allotted, evaluated, problem, pending, window). Excel or PDF.
    - **Answer Book / Top Sheet Report** — per-script question-wise marks
      top sheet (anonymized: QR/script code only). PDF / ZIP.
    - **Problem Report** — every raised issue (teacher, emp code, program,
      course, semester, exam term, examination, year, QR code, issue type,
      status, teacher remarks, issue time, solved by/date, admin remarks).
      Excel only. Course filter optional.
    - Excel exports are **one HTML `<table>` with inline styles served as
      `.xls`** (no spreadsheet library); PDFs via dompdf with a
      `page_script()` "Page X of Y" footer.
11. **Dashboard** — admin KPIs (assignment/evaluation/issue counts,
    course-pending, department progress, teacher workload).
12. **Configurations** — Users, Roles, Permission Groups/Sub Groups,
    Permissions, Email Logs (view + resend), General Settings (site title,
    logos, face-scan toggle).

---

## 5. Emails

Three Mailables in `app/Mail` + Blade views in `resources/views/emails/`:
- `AnswerSheetAssignedMail` — assignment **and** reassignment (admin-
  editable subject/body).
- `IssueRaisedMail` — to every user in `config('roles.admin_recived_issue_mail')`.
- `IssueResolvedMail` — printing vs timing variants.

Sent via services (`TeacherAssignmentMailService`, `TeacherReassignMailService`,
`IssueRaisedMailService`, `IssueResolvedMailService`) with
`dispatch(...)->afterResponse()`, logged to `email_logs`. Branding (site
title + `footer_logo`) from `MailBrandingService`. Templates share a themed
card: red→blue gradient header (`#e81b26 → #2f56c0`), light page background.
Password reset uses Laravel's own notification (separate template).
Mail transport: SMTP (SendGrid).

---

## 6. Frontend structure

- `src/utils/api.js` — axios instance (base URL, `X-API-KEY`, Bearer token
  from `localStorage.auth_token`, 401 handling); `resolveStorageUrl()`
  rewrites `/storage/...` paths to the backend origin.
- `src/stores/` — `auth` (user, `can(permission)`), `branding`, `examYear`
  + `examType` (header pickers, persisted), `notifications` (open-issue
  badge count), `teacher`, `papers` (legacy demo).
- `src/router/index.js` — all routes; layout shell = `AppHeader` +
  `AppSidebar` + `AppFooter`. Evaluate screen is a full-screen route.
- `src/components/layout/AppSidebar.vue` — menu groups (Master, Evaluate
  Course, Assign Teacher, Report, Configurations); items hide via
  `authStore.can()`; permission-less items always show.
- Common components: `SearchableSelect`, `DatePicker` (date or date+time,
  value `YYYY-MM-DD HH:mm`), `RowActionMenu`, `Pagination`, `ConfirmDialog`
  (`useConfirm().confirmDialog()`), `Toaster` (`useToast()`),
  `GlobalLoader`.
- Utilities: `pdf.js` (pdf.js load/render/text extraction/OCR),
  `evaluatedPdf.js` (pdf-lib builder), `questionPaperNode.js`
  (tree helpers/validation), `questionPaperParser.js`, `qr.js`, `face.js`,
  `date.js` (display as `dd-mm-yyyy hh:mm AM/PM`).
- Styling: Tailwind 4 with theme tokens in `src/assets/main.css`
  (`brand-blue #2f56c0`, `btn-gradient` red→blue, `subject-header` blue
  gradient, `page-bg #f0f3f8`). Tables use a compact convention (12px text,
  `py-1` cells, small status toggles).
- Legacy demo screens (`UploadView`, `ReviewView`, `ReviewDetailView`,
  `stores/papers.js`) are client-only prototypes, not the real flow.

---

## 7. Key files to read first

Backend
- `routes/api.php`, `routes/web.php` (storage streaming route), `bootstrap/app.php` (middleware + JSON exception rendering)
- `app/Models/AnswerSheet.php`, `QuestionAnswerSheetMapping.php`, `QuestionPaper.php`, `QuestionPaperNode.php`
- `app/Http/Controllers/API/V1/MyPendingCourseController.php` (evaluation lifecycle)
- `app/Http/Controllers/API/V1/AssignTeacherController.php` + `app/Services/AssignTeacherService.php`
- `app/Http/Controllers/API/V1/TeacherController.php` (teachers, assignments, reassign, time span)
- `app/Http/Controllers/API/V1/NotificationController.php` (issue resolution)
- `app/Http/Controllers/API/V1/QuestionAnswerSheetMappingController.php` (answer sheet upload)
- `app/Http/Controllers/API/V1/Report/*` + `resources/views/reports/*`
- `app/Traits/HasExamYearScope.php`, `HasExamTypeScope.php`, `ApiResponse.php`
- `config/roles.php`, `config/issues.php`, `config/evaluation.php`, `config/filesystems.php`

Frontend
- `src/router/index.js`, `src/components/layout/AppSidebar.vue`, `src/utils/api.js`
- `src/views/EvaluatePaperView.vue` (marking screen)
- `src/views/MyPendingCoursesView.vue`, `MyProblemCoursesView.vue`, `MyCompletedCoursesView.vue`
- `src/views/AssignTeacherView.vue`, `AssignedTeachersView.vue`, `components/teachers/*`
- `src/views/AnswerSheetUploadView.vue`, `QuestionPaperSetupView.vue`, `components/questionPapers/*`
- `src/views/ResetEvaluationView.vue` + `src/utils/evaluatedPdf.js`
- `src/views/reports/*`

---

## 8. Commands

```bash
# backend
cd backend
composer install
php artisan migrate
php artisan db:seed            # roles, admin, general settings, issue masters…
php artisan test               # full suite
php artisan optimize:clear     # after deploying config/route changes

# frontend
cd frontend
npm install
npm run dev                    # Vite dev server (HTTPS), proxies /api and /storage
npm run build                  # production build → dist/
```

Server notes: `backend/bootstrap/cache` and `backend/public/storage` must be
writable by the web server user.
