<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreTeacherRequest;
use App\Http\Requests\Teacher\UpdateTeacherRequest;
use App\Http\Resources\TeacherResource;
use App\Models\AnswerSheet;
use App\Models\Department;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\Role;
use App\Models\TeacherDetail;
use App\Models\User;
use App\Services\AssignTeacherService;
use App\Services\AuditLogService;
use App\Services\TeacherReassignMailService;
use App\Traits\ApiResponse;
use App\Traits\HasDepartmentScope;
use App\Traits\HasExamTypeScope;
use App\Traits\HasExamYearScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// NOTE: no permission gating yet — same as StudentController/CourseController,
// deferred until permissions are set up per module. Placed flat under
// API/V1 — a "teacher" isn't its own table/resource, it's a User (with the
// Teacher role) plus a teacher_details row, so it doesn't fit Auth/Master/
// User either.
class TeacherController extends Controller
{
    use ApiResponse, HasDepartmentScope, HasExamTypeScope, HasExamYearScope;

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly AssignTeacherService $assignTeacherService,
    ) {}

    /**
     * Same multi-purpose listing shape as StudentController/CourseController:
     *  - ?id=5             → single record
     *  - ?status=deleted   → only soft-deleted teachers
     *  - ?search=          → matches name/email/phone_no/emp_code/department/designation
     *  - ?department_id=   → only teachers in that department (see
     *    AssignTeacherView.vue's own admin-editable department filter —
     *    independent of ?department_scope= below)
     *  - ?has_assignments=yes|no → only teachers with (or without) any
     *    answer sheet currently allocated to them (see
     *    AssignedTeachersView.vue) — "yes" is also exam-year-scoped for
     *    anyone but a super admin (see HasExamYearScope)
     *  - ?department_scope=self → only teachers in the *calling user's own*
     *    department, for anyone but a super admin (see HasDepartmentScope)
     *    — sent by AssignTeacherView.vue's teacher picker and
     *    AssignedTeachersView.vue; the plain Teachers master list never
     *    sends this and stays unrestricted
     *  - ?is_active=yes|no, ?sort_by_field=, ?sort_by=, ?per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $onlyDeleted = $request->filled('status') && $request->string('status')->toString() === 'deleted';

        // Only meaningful together with ?has_assignments=yes below (the
        // Assigned Teacher List — see AssignedTeachersView.vue); computed
        // up front so both the filter and the allocated-count below use
        // the exact same year. Every other listing that reuses this same
        // index() (the plain Teachers page, AssignTeacherView.vue's
        // teacher picker, ...) is completely unaffected — this only ever
        // does anything when has_assignments=yes is actually present.
        $hasAssignments = $request->filled('has_assignments')
            && Str::lower($request->string('has_assignments')->toString()) === 'yes';
        $assignmentsExamYear = $hasAssignments ? $this->examYearScope($request) : null;
        $assignmentsExamType = $hasAssignments ? $this->examTypeScope($request) : null;
        $scopeToExamYear = function ($q) use ($assignmentsExamYear, $assignmentsExamType) {
            if ($assignmentsExamYear !== null) {
                $q->whereHas('mapping.questionPaper', fn ($q2) => $q2->where('exam_year', $assignmentsExamYear));
            }
            if ($assignmentsExamType !== null) {
                $q->whereHas('mapping', fn ($q2) => $q2->where('exam_type_id', $assignmentsExamType));
            }
        };

        // "Allocated Answer Sheets" is every sheet ever handed to this
        // teacher (completed or not); "Completed Sheets" is the subset of
        // those with marks already set — shown as its own column on
        // AssignedTeachersView.vue's table so an admin can see at a glance
        // how much of a teacher's load is actually done. "Courses" is the
        // count of *distinct* courses those sheets span — see that
        // column's own "Courses" modal (TeacherCoursesModal.vue, backed by
        // courses() below) for the simpler, course-only view this number
        // opens into, as opposed to assignments()'s packet-level one.
        $query = User::query()
            ->withCount(['assignedAnswerSheets' => $scopeToExamYear])
            ->withCount(['assignedAnswerSheets as assigned_answer_sheets_completed_count' => function ($q) use ($scopeToExamYear) {
                $scopeToExamYear($q);
                $q->whereNotNull('marks');
            }])
            ->addSelect(['assigned_courses_count' => $this->allocatedCoursesCountSubquery($assignmentsExamYear, $assignmentsExamType)]);

        if ($onlyDeleted) {
            $query->onlyTrashed()
                ->whereHas('teacherDetail', fn ($q) => $q->withTrashed())
                ->with(['teacherDetail' => fn ($q) => $q->withTrashed()]);
        } else {
            $query->whereHas('teacherDetail')->with('teacherDetail');
        }

        if ($request->filled('id')) {
            $teacher = $query->find($request->input('id'));

            if (! $teacher) {
                return $this->notFound('No teacher found.');
            }

            return $this->success(new TeacherResource($teacher), 'Teacher fetched successfully.');
        }

        // Matches every column the list actually shows (see
        // TeachersView.vue's table: Name, Emp Code, Email, Phone,
        // Department, Designation, Face Scan, Status) — "Active"/
        // "Inactive" and "Scanned"/"Not Scanned" match their boolean
        // columns since that's how they're displayed, not literal text.
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_no', 'like', "%{$search}%")
                    ->orWhereHas('teacherDetail', function ($q2) use ($search) {
                        $q2->where('department', 'like', "%{$search}%")
                            ->orWhere('designation', 'like', "%{$search}%")
                            ->orWhere('emp_code', 'like', "%{$search}%");
                    });
                // Prefix match, not "contains" — "active" is itself a
                // substring of "inactive" ("in-active"), and "scanned" is
                // itself a substring of "not scanned"/"unscanned", so a
                // naive stripos() either direction would cross-match.
                $needle = strtolower($search);
                if (str_starts_with('active', $needle)) {
                    $q->orWhere('is_active', true);
                }
                if (str_starts_with('inactive', $needle)) {
                    $q->orWhere('is_active', false);
                }
                if (str_starts_with('scanned', $needle)) {
                    $q->orWhereHas('teacherDetail', fn ($q2) => $q2->whereNotNull('face_descriptor'));
                }
                if (str_starts_with('not scanned', $needle) || str_starts_with('unscanned', $needle)) {
                    $q->orWhereHas('teacherDetail', fn ($q2) => $q2->whereNull('face_descriptor'));
                }
            });
        }

        if ($request->filled('is_active')) {
            $statusMap = ['yes' => true, 'no' => false];
            $isActive = Str::lower($request->string('is_active')->toString());

            if (array_key_exists($isActive, $statusMap)) {
                $query->where('is_active', $statusMap[$isActive]);
            }
        }

        if ($request->filled('department_id')) {
            $query->whereHas('teacherDetail', fn ($q) => $q->where('department_id', $request->integer('department_id')));
        }

        if ($request->filled('department_scope') && $request->string('department_scope')->toString() === 'self') {
            $departmentScope = $this->departmentScope($request);
            if ($departmentScope === false) {
                // Non-super-admin caller with no department of their own
                // set — matches nothing rather than silently showing
                // everyone (see HasDepartmentScope's own docblock).
                $query->whereRaw('1 = 0');
            } elseif ($departmentScope !== null) {
                $query->whereHas('teacherDetail', fn ($q) => $q->where('department_id', $departmentScope));
            }
        }

        // Backs the "Assigned Teacher List" page — only teachers who
        // currently have at least one answer sheet allocated to them
        // (within the scoped exam year for anyone but a super admin — see
        // $scopeToExamYear above).
        if ($request->filled('has_assignments')) {
            $hasAssignments = Str::lower($request->string('has_assignments')->toString());

            if ($hasAssignments === 'yes') {
                $query->whereHas('assignedAnswerSheets', $scopeToExamYear);
            } elseif ($hasAssignments === 'no') {
                // "No assignments at all, in any year" — exam-year scoping
                // doesn't apply here; nothing in this app currently needs
                // has_assignments=no narrowed to one year.
                $query->whereDoesntHave('assignedAnswerSheets');
            }
        }

        $sortField = in_array($request->input('sort_by_field'), ['id', 'name', 'email', 'created_at'], true)
            ? $request->input('sort_by_field')
            : 'id';
        $sortDir = Str::lower((string) $request->input('sort_by')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDir);

        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $teachers = $query->paginate($perPage);

        return $this->paginated($teachers, 'Teachers fetched successfully.', TeacherResource::collection($teachers));
    }

    public function store(StoreTeacherRequest $request): JsonResponse
    {
        $data = $request->validated();

        $teacher = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'username' => $this->generateUsername($data['name'], $data['email']),
                'email' => $data['email'],
                'phone_no' => $data['phone_no'] ?? null,
                // Left null when no password was given — the 'hashed' cast
                // skips hashing a null value, and Hash::check() against a
                // null hash just returns false (not an error), so this
                // account simply can't log in until a password is set via
                // "Forgot password?" or an admin edit.
                'password' => $data['password'] ?? null,
                'is_active' => true,
            ]);

            // Resolved by name, not a fixed id — unlike Super Admin (see
            // config('roles.super_admin_id')'s own docblock), the Teacher
            // role has no special code-level treatment, so nothing here
            // needs to survive it being renamed the way an id would; this
            // just needs *a* role called "Teacher" to exist, same as any
            // admin-managed role.
            $teacherRole = Role::where('name', 'Teacher')->first();
            if ($teacherRole) {
                $user->assignRole($teacherRole);

                $this->auditLog->log(
                    event: 'role-assigned',
                    module: 'Teacher Management',
                    description: 'Teacher role auto-assigned on creation.',
                    auditable: $user,
                    newValues: ['role_id' => $teacherRole->id],
                    tags: ['role', 'teacher'],
                );
            }

            // department_id is what's actually selected (a native dropdown
            // sourced from the Department master list — see
            // designed_files/form.html's "Department (Select)" pattern);
            // `department` is kept alongside as the name at the time it was
            // picked, so listing/search/exports never need to join out to
            // departments for it.
            $department = Department::find($data['department_id']);

            TeacherDetail::create([
                'user_id' => $user->id,
                'emp_code' => $data['emp_code'],
                'department_id' => $department->id,
                'department' => $department->name,
                'designation' => $data['designation'],
            ]);

            return $user;
        });

        return $this->success(
            new TeacherResource($teacher->fresh('teacherDetail')),
            'Teacher created successfully.',
            201,
        );
    }

    public function show(User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail) {
            return $this->notFound('No teacher found.');
        }

        return $this->success(new TeacherResource($teacher->load('teacherDetail')), 'Teacher retrieved successfully.');
    }

    /**
     * GET /teachers/{teacher}/assignments — the "Already Allocated" number
     * on AssignTeacherView.vue's teacher table (and the "Allocated Answer
     * Sheets" count on AssignedTeachersView.vue) is clickable; this backs
     * the modal it opens (TeacherAllocationModal.vue), breaking that total
     * down by packet (one row per question_answer_sheet_mapping this
     * teacher has any sheets in, not one row per sheet — a teacher's
     * sheets for the same course/exam are one line with a count, not a
     * wall of individual rows) — and, within each packet's row, further
     * into how many of its sheets are completed vs in-draft vs untouched.
     */
    public function assignments(User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail) {
            return $this->notFound('No teacher found.');
        }

        $counts = AnswerSheet::query()
            ->select('question_answer_sheet_mapping_id')
            ->selectRaw('COUNT(*) as sheet_count')
            // completed = marks actually submitted (see
            // MyPendingCourseController::submitMarks()'s own "Complete");
            // in_draft = not yet submitted, but has at least one autosave
            // (see saveDraft() — draft_marks gets set the first time that
            // fires, even to 0). Anything in neither bucket simply hasn't
            // been opened yet.
            ->selectRaw('SUM(CASE WHEN marks IS NOT NULL THEN 1 ELSE 0 END) as completed_count')
            ->selectRaw('SUM(CASE WHEN marks IS NULL AND draft_marks IS NOT NULL THEN 1 ELSE 0 END) as draft_count')
            ->where('teacher_id', $teacher->id)
            ->groupBy('question_answer_sheet_mapping_id')
            ->get();

        $mappings = QuestionAnswerSheetMapping::with(['course', 'examTerm', 'examType', 'questionPaper'])
            ->whereIn('id', $counts->pluck('question_answer_sheet_mapping_id'))
            ->get()
            ->keyBy('id');

        $breakdown = $counts
            ->map(function ($row) use ($mappings) {
                $mapping = $mappings->get($row->question_answer_sheet_mapping_id);

                return [
                    'mapping_id' => $row->question_answer_sheet_mapping_id,
                    'program_name' => $mapping?->program_name,
                    'course_name' => $mapping?->course?->name,
                    'course_code' => $mapping?->course?->code,
                    'semester' => $mapping?->semester,
                    'exam_term_name' => $mapping?->examTerm?->name,
                    'exam_type_name' => $mapping?->examType?->name,
                    'exam_year' => $mapping?->questionPaper?->exam_year,
                    'packet_code' => $mapping?->packet_code,
                    'sheet_count' => (int) $row->sheet_count,
                    'completed_count' => (int) $row->completed_count,
                    'draft_count' => (int) $row->draft_count,
                ];
            })
            ->sortByDesc('sheet_count')
            ->values();

        return $this->success([
            'total' => (int) $breakdown->sum('sheet_count'),
            'breakdown' => $breakdown,
        ], 'Teacher assignments fetched successfully.');
    }

    /**
     * Correlated subquery counting the *distinct* courses a teacher's own
     * answer sheets span — used both by index()'s "Courses" column (see
     * assigned_courses_count above, correlated against the outer users.id)
     * and, unscoped, by courses() below. $examYear narrows it to one exam
     * year the same way $scopeToExamYear narrows assignedAnswerSheets
     * above — null (a super admin who didn't ask for one) means every
     * year.
     */
    private function allocatedCoursesCountSubquery(?int $examYear, ?int $examType = null): Builder
    {
        $query = AnswerSheet::query()
            ->join('question_answer_sheet_mappings', 'question_answer_sheet_mappings.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->whereColumn('answer_sheets.teacher_id', 'users.id')
            ->whereNull('question_answer_sheet_mappings.deleted_at')
            ->whereNull('answer_sheets.deleted_at')
            ->selectRaw('COUNT(DISTINCT question_answer_sheet_mappings.course_id)');

        if ($examYear !== null) {
            $query->join('question_papers', 'question_papers.id', '=', 'question_answer_sheet_mappings.question_paper_id')
                ->where('question_papers.exam_year', $examYear);
        }

        if ($examType !== null) {
            $query->where('question_answer_sheet_mappings.exam_type_id', $examType);
        }

        return $query;
    }

    /**
     * GET /teachers/{teacher}/courses — the "Courses" count on
     * AssignedTeachersView.vue's table is clickable; this backs the modal
     * it opens (TeacherCoursesModal.vue). Deliberately the simpler
     * counterpart to assignments() above: one row per *course* (summed
     * across however many packets/exam terms/years that course spans for
     * this teacher), not one row per packet — "show only courses," not
     * the full packet breakdown that already has its own modal.
     */
    public function courses(User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail) {
            return $this->notFound('No teacher found.');
        }

        $courses = AnswerSheet::query()
            ->join('question_answer_sheet_mappings', 'question_answer_sheet_mappings.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('courses', 'courses.id', '=', 'question_answer_sheet_mappings.course_id')
            // A course can span more than one packet, and those packets
            // don't have to share the same exam type — GROUP_CONCAT collects
            // every distinct one this teacher's own sheets for the course
            // actually touch, rather than picking an arbitrary single value.
            ->leftJoin('exam_types', 'exam_types.id', '=', 'question_answer_sheet_mappings.exam_type_id')
            ->where('answer_sheets.teacher_id', $teacher->id)
            ->whereNull('question_answer_sheet_mappings.deleted_at')
            ->groupBy('courses.id', 'courses.name', 'courses.code')
            ->orderBy('courses.name')
            ->selectRaw(
                'courses.id as course_id, courses.name as course_name, courses.code as course_code, '.
                'GROUP_CONCAT(DISTINCT exam_types.name ORDER BY exam_types.name SEPARATOR ", ") as exam_type_names, '.
                'COUNT(*) as sheet_count, SUM(CASE WHEN answer_sheets.marks IS NOT NULL THEN 1 ELSE 0 END) as completed_count',
            )
            ->get()
            ->map(fn ($row) => [
                'course_id' => (int) $row->course_id,
                'course_name' => $row->course_name,
                'course_code' => $row->course_code,
                'exam_type_names' => $row->exam_type_names,
                'sheet_count' => (int) $row->sheet_count,
                'completed_count' => (int) $row->completed_count,
            ]);

        return $this->success(['courses' => $courses], 'Teacher courses fetched successfully.');
    }

    /**
     * POST /teachers/{teacher}/assignments/reassign — moves some or all of
     * $teacher's already-assigned sheets for one packet to one or more
     * other teachers at once (the "this teacher is on leave, split their
     * work across the rest of the team" case
     * TeacherAllocationModal.vue's per-row "Reassign" panel covers — same
     * Distribute-Equally-then-edit shape as the main Assign flow, just
     * drawing from one teacher's own sheets instead of the pending pool).
     * $teacher here is the *from* teacher; the targets are in the body.
     */
    public function reassignAssignment(Request $request, User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail) {
            return $this->notFound('No teacher found.');
        }

        $data = $request->validate([
            'mapping_id' => ['required', 'integer', Rule::exists('question_answer_sheet_mappings', 'id')->whereNull('deleted_at')],
            'reassignments' => ['required', 'array', 'min:1'],
            'reassignments.*.teacher_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('teacher_details', 'user_id')->whereNull('deleted_at'),
            ],
            'reassignments.*.quantity' => ['required', 'integer', 'min:1'],
            // The (admin-editable) subject/body from the "Send Mail and
            // Assign" modal — same flow as AssignTeacherController::store(),
            // see SendAssignmentEmailModal.vue.
            'email_subject' => ['required', 'string', 'max:255'],
            'email_body' => ['required', 'string'],
        ]);

        foreach ($data['reassignments'] as $reassignment) {
            if ((int) $reassignment['teacher_id'] === $teacher->id) {
                throw ValidationException::withMessages([
                    'reassignments' => ['Pick a different teacher to reassign to.'],
                ]);
            }
        }

        try {
            $result = $this->assignTeacherService->reassign(
                mappingId: (int) $data['mapping_id'],
                fromTeacherId: $teacher->id,
                reassignments: $data['reassignments'],
            );
        } catch (ValidationException $e) {
            return $this->error(collect($e->errors())->flatten()->first() ?? 'Could not reassign.', 422, $e->errors());
        }

        $totalReassigned = collect($result['summary'])->sum('reassigned_count');

        $this->auditLog->log(
            event: 'answer-sheets-reassigned',
            module: 'Assign Teacher',
            description: "Reassigned {$totalReassigned} answer sheet(s) from {$teacher->name} across ".count($result['summary']).' teacher(s).',
            newValues: [
                'mapping_id' => $data['mapping_id'],
                'from_teacher_id' => $teacher->id,
                'summary' => $result['summary'],
            ],
            tags: ['assign-teacher', 'answer-sheet', 'reassign'],
        );

        // Sent after the HTTP response goes out, not queued — same
        // reasoning as AssignTeacherController::store()'s own dispatch.
        $senderId = $request->user()->id;
        $summary = $result['summary'];
        $emailSubject = $data['email_subject'];
        $emailBody = $data['email_body'];
        $mappingId = (int) $data['mapping_id'];

        dispatch(function () use ($senderId, $summary, $emailSubject, $emailBody, $mappingId) {
            App::make(TeacherReassignMailService::class)->sendReassignmentEmails(
                senderId: $senderId,
                summary: $summary,
                emailSubject: $emailSubject,
                emailBody: $emailBody,
                mappingId: $mappingId,
            );
        })->afterResponse();

        return $this->success($result, "Reassigned {$totalReassigned} answer sheet(s) across ".count($result['summary']).' teacher(s).');
    }

    public function update(UpdateTeacherRequest $request, User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail) {
            return $this->notFound('No teacher found.');
        }

        $data = $request->validated();

        if (array_key_exists('password', $data) && empty($data['password'])) {
            unset($data['password']);
        }

        DB::transaction(function () use ($teacher, $data) {
            $userData = array_intersect_key($data, array_flip(['name', 'email', 'phone_no', 'password', 'is_active']));
            if ($userData !== []) {
                $teacher->update($userData);
            }

            $detailData = array_intersect_key($data, array_flip(['emp_code', 'department_id', 'designation', 'face_scan_applicable']));
            if (array_key_exists('department_id', $detailData)) {
                // Re-derive the denormalized name from whatever department
                // was actually (re-)selected, same as store().
                $detailData['department'] = Department::find($detailData['department_id'])->name;
            }
            if ($detailData !== []) {
                $teacher->teacherDetail->update($detailData);
            }
        });

        return $this->success(
            new TeacherResource($teacher->fresh('teacherDetail')),
            'Teacher updated successfully.',
        );
    }

    public function destroy(User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail) {
            return $this->notFound('No teacher found.');
        }

        DB::transaction(function () use ($teacher) {
            $teacher->teacherDetail->delete();
            $teacher->delete();
        });

        return $this->success(null, 'Teacher deleted successfully.');
    }

    public function restore(User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail()->withTrashed()->exists()) {
            return $this->notFound('No teacher found.');
        }

        DB::transaction(function () use ($teacher) {
            $teacher->restore();
            TeacherDetail::withTrashed()->where('user_id', $teacher->id)->restore();
        });

        return $this->success(new TeacherResource($teacher->fresh('teacherDetail')), 'Teacher restored successfully.');
    }

    /**
     * The Store request only collects name/email/phone/password/department/
     * designation (see StoreTeacherRequest) — users.username is still
     * required and unique, so one is derived from the name (falling back to
     * the email's local part) and de-duplicated with a numeric suffix.
     */
    private function generateUsername(string $name, string $email): string
    {
        $base = Str::slug($name, '_');
        if ($base === '') {
            $base = Str::slug(Str::before($email, '@'), '_');
        }
        if ($base === '') {
            $base = 'teacher';
        }

        $username = $base;
        $suffix = 1;

        while (User::withTrashed()->where('username', $username)->exists()) {
            $suffix++;
            $username = "{$base}_{$suffix}";
        }

        return $username;
    }
}
