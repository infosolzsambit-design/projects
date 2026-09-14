<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreTeacherRequest;
use App\Http\Requests\Teacher\UpdateTeacherRequest;
use App\Http\Resources\TeacherResource;
use App\Models\AnswerSheet;
use App\Models\Department;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\TeacherDetail;
use App\Models\User;
use App\Services\AssignTeacherService;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    use ApiResponse;

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
     *    AssignTeacherView.vue's own department filter)
     *  - ?has_assignments=yes|no → only teachers with (or without) any
     *    answer sheet currently allocated to them (see
     *    AssignedTeachersView.vue)
     *  - ?is_active=yes|no, ?sort_by_field=, ?sort_by=, ?per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $onlyDeleted = $request->filled('status') && $request->string('status')->toString() === 'deleted';

        $query = User::query()->withCount('assignedAnswerSheets');

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

        // Backs the "Assigned Teacher List" page — only teachers who
        // currently have at least one answer sheet allocated to them.
        if ($request->filled('has_assignments')) {
            $hasAssignments = Str::lower($request->string('has_assignments')->toString());

            if ($hasAssignments === 'yes') {
                $query->whereHas('assignedAnswerSheets');
            } elseif ($hasAssignments === 'no') {
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

            $teacherRoleId = (int) config('roles.teacher_id');
            $user->assignRole($teacherRoleId);

            $this->auditLog->log(
                event: 'role-assigned',
                module: 'Teacher Management',
                description: 'Teacher role auto-assigned on creation.',
                auditable: $user,
                newValues: ['role_id' => $teacherRoleId],
                tags: ['role', 'teacher'],
            );

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
     * on AssignTeacherView.vue's teacher table is clickable; this backs the
     * modal it opens, breaking that total down by packet (one row per
     * question_answer_sheet_mapping this teacher has any sheets in, not
     * one row per sheet — a teacher's sheets for the same course/exam are
     * one line with a count, not a wall of individual rows).
     */
    public function assignments(User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail) {
            return $this->notFound('No teacher found.');
        }

        $counts = AnswerSheet::query()
            ->select('question_answer_sheet_mapping_id')
            ->selectRaw('COUNT(*) as sheet_count')
            ->where('teacher_id', $teacher->id)
            ->groupBy('question_answer_sheet_mapping_id')
            ->get();

        $mappings = QuestionAnswerSheetMapping::with(['course', 'examTerm', 'questionPaper'])
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
                    'exam_year' => $mapping?->questionPaper?->exam_year,
                    'packet_code' => $mapping?->packet_code,
                    'sheet_count' => (int) $row->sheet_count,
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
