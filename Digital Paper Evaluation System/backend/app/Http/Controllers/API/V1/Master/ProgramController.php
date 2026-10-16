<?php

namespace App\Http\Controllers\API\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Program\StoreProgramRequest;
use App\Http\Requests\Program\UpdateProgramRequest;
use App\Http\Resources\ProgramResource;
use App\Models\Course;
use App\Models\Department;
use App\Models\MainDbSyncConfig;
use App\Models\Program;
use App\Services\AuditLogService;
use App\Services\MainDbApiService;
use App\Services\MainDbSyncService;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

// NOTE: no permission gating yet — every authenticated user (auth:sanctum,
// applied at the route-group level) can manage programs for now. Per-action
// permission middleware is intentionally deferred until permissions are set
// up separately (same as CourseController).
class ProgramController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * One multi-purpose listing endpoint (same shape as CourseController::index):
     *  - ?id=5                    → single record (like show(), but via query param)
     *  - ?status=deleted          → only soft-deleted programs
     *  - ?status=all              → every matching program, unpaginated
     *      - ?table_fields=["name","code"]  → trims the SELECT to just those columns (+id)
     *      - ?is_active=yes|no    → filter by the program's own active/inactive flag
     *  - default                  → paginated, searchable listing
     *      - ?search=, ?name=, ?label=, ?department=, ?code=, ?is_active=yes|no, ?sort_by=, ?sort_by_field=, ?per_page=
     *      - ?table_fields=["name","code"]  → same column-trimming as the "all" branch
     */
    public function index(Request $request): JsonResponse
    {
        $query = Program::query();

        if ($request->filled('status') && $request->string('status')->toString() === 'deleted') {
            $query->onlyTrashed();
        }

        if ($request->filled('id')) {
            $program = $query->with('courses')->find($request->input('id'));

            if (! $program) {
                return $this->notFound('No program found.');
            }

            return $this->success(new ProgramResource($program), 'Program fetched successfully.');
        }

        if ($request->filled('status') && $request->string('status')->toString() === 'all') {
            $fieldsRequested = $request->filled('table_fields');
            if ($fieldsRequested) {
                $this->applyFieldSelection($query, $request);
            } else {
                $query->with('courses:id,name,code,type');
            }

            $this->applyActiveFilter($query, $request);
            $this->applySorting($query, $request);

            $programs = $query->get();

            return $this->success($programs, 'Programs fetched successfully.');
        }

        // Searches every column the list actually shows (see
        // ProgramsView.vue's table: Name, Label, Department, Code, Courses,
        // Status) rather than just name/department/code — "Active"/
        // "Inactive" match the boolean `status` column since that's how
        // it's displayed, and a mapped course's name/code match too.
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('courses', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
                    });
                // Prefix match, not "contains" — "active" is itself a
                // substring of "inactive" ("in-active"), so a naive
                // stripos() on either word would make searching "active"
                // wrongly match inactive rows too.
                $needle = strtolower($search);
                if (str_starts_with('active', $needle)) {
                    $q->orWhere('status', true);
                }
                if (str_starts_with('inactive', $needle)) {
                    $q->orWhere('status', false);
                }
            });
        }

        if ($request->filled('name')) {
            $query->where('name', 'like', '%'.$request->string('name')->toString().'%');
        }

        if ($request->filled('label')) {
            $query->where('label', 'like', '%'.$request->string('label')->toString().'%');
        }

        if ($request->filled('department')) {
            $query->where('department', 'like', '%'.$request->string('department')->toString().'%');
        }

        if ($request->filled('code')) {
            $query->where('code', 'like', '%'.$request->string('code')->toString().'%');
        }

        $fieldsRequested = $request->filled('table_fields');
        if ($fieldsRequested) {
            $this->applyFieldSelection($query, $request);
        } else {
            $query->with('courses:id,name,code,type');
        }

        $this->applyActiveFilter($query, $request);
        $this->applySorting($query, $request);

        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $programs = $query->paginate($perPage);

        return $this->paginated(
            $programs,
            'Programs fetched successfully.',
            $fieldsRequested ? null : ProgramResource::collection($programs),
        );
    }

    /**
     * Trims the SELECT to just the requested column names (always keeping
     * id). Program has no relations, so there's no dot-notation
     * relation-field handling here.
     */
    private function applyFieldSelection(Builder $query, Request $request): void
    {
        $fields = $request->input('table_fields');

        if (is_string($fields)) {
            $fields = json_decode(str_replace("'", '"', $fields), true);
        }

        if (is_array($fields) && $fields !== []) {
            $query->select(array_values(array_unique(array_merge(['id'], $fields))));
        }
    }

    private function applyActiveFilter(Builder $query, Request $request): void
    {
        if (! $request->filled('is_active')) {
            return;
        }

        $statusMap = ['yes' => true, 'no' => false];
        $isActive = Str::lower($request->string('is_active')->toString());

        if (array_key_exists($isActive, $statusMap)) {
            $query->where('status', $statusMap[$isActive]);
        }
    }

    private function applySorting(Builder $query, Request $request): void
    {
        $sortField = in_array($request->input('sort_by_field'), ['id', 'name', 'label', 'department', 'code', 'status', 'created_at'], true)
            ? $request->input('sort_by_field')
            : 'id';
        $sortDir = Str::lower((string) $request->input('sort_by')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortDir);
    }

    public function store(StoreProgramRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= true;
        // Courses are optional at creation — no course_ids at all just
        // means the program starts out with none mapped.
        $courseIds = $data['course_ids'] ?? [];
        unset($data['course_ids']);

        // department_id is what's actually selected (a searchable dropdown
        // sourced from the Department master list — see
        // TeacherController::store() for the identical pattern); `department`
        // is kept alongside as the name at the time it was picked, so
        // listing/search/exports never need to join out to departments.
        $data['department'] = Department::find($data['department_id'])->name;

        // The program row and its course pivot rows must land together —
        // a sync() failure after create() would otherwise leave an orphan
        // program with no courses mapped and no audit trail of it either.
        $program = DB::transaction(function () use ($data, $courseIds) {
            $program = Program::create($data);
            $program->courses()->sync($courseIds);

            $this->auditLog->log(
                event: 'courses-synced',
                module: 'Program Management',
                description: 'Courses mapped to newly created program.',
                auditable: $program,
                newValues: ['course_ids' => $courseIds],
                tags: ['course-mapping'],
            );

            return $program;
        });

        return $this->success(new ProgramResource($program->load('courses')), 'Program created successfully.', 201);
    }

    public function show(Program $program): JsonResponse
    {
        return $this->success(new ProgramResource($program->load('courses')), 'Program retrieved successfully.');
    }

    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $data = $request->validated();

        // The pivot sync and the program's own column update must land
        // together — same reasoning as store() above.
        DB::transaction(function () use ($program, $data) {
            if (array_key_exists('course_ids', $data)) {
                $courseIds = $data['course_ids'];
                unset($data['course_ids']);

                $before = $program->courses()->pluck('courses.id')->all();
                $program->courses()->sync($courseIds);

                $this->auditLog->log(
                    event: 'courses-synced',
                    module: 'Program Management',
                    description: 'Courses mapped to program updated.',
                    auditable: $program,
                    oldValues: ['course_ids' => $before],
                    newValues: ['course_ids' => $courseIds],
                    tags: ['course-mapping'],
                );
            }

            if (array_key_exists('department_id', $data)) {
                // Re-derive the denormalized name from whatever department was
                // actually (re-)selected, same as store().
                $data['department'] = Department::find($data['department_id'])->name;
            }

            $program->update($data);
        });

        return $this->success(new ProgramResource($program->fresh()->load('courses')), 'Program updated successfully.');
    }

    public function destroy(Program $program): JsonResponse
    {
        $program->delete();

        return $this->success(null, 'Program deleted successfully.');
    }

    public function restore(Program $program): JsonResponse
    {
        // A new program may have taken the same name + code + label while
        // this one was deleted — restoring it would make a duplicate.
        if (Program::hasDuplicate($program->name, $program->code, $program->label, $program->id)) {
            return $this->error('Cannot restore: an active program with the same Name, Code and Label already exists.', 422);
        }

        $program->restore();

        return $this->success(new ProgramResource($program), 'Program restored successfully.');
    }

    /**
     * POST /programs/sync — see MainDbSyncService (matched by inserted_id;
     * new → added, existing → updated/restored, gone → soft-deleted; hand-
     * added programs untouched). The API's department_id and course_ids are
     * the main database's own ids, so they're matched to our departments /
     * courses by their inserted_id — sync Departments and Courses first.
     *  - department: an unknown (or empty) department_id leaves it blank;
     *  - courses: a non-empty course_ids sets the program's course mapping
     *    to exactly those (ids we don't have are ignored); an empty one
     *    leaves the mapping as it is here.
     * The API sends no code: new programs start with an empty one, and a
     * code entered here is never overwritten. Deleted-in-main-database
     * programs ("delete_status": "0") are kept, just flagged
     * (deleted_from_main_db). The Name + Code + Label duplicate check isn't
     * applied — the main database is the source.
     */
    public function sync(MainDbApiService $mainDb, MainDbSyncService $syncer): JsonResponse
    {
        try {
            $config = MainDbSyncConfig::forEntity('programs');
            $remote = $mainDb->fetch($config->path, $config->method, $config->queryParams());
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        // main database id → our department / course
        $departments = Department::withTrashed()->whereNotNull('inserted_id')->get(['id', 'inserted_id', 'name'])->keyBy('inserted_id');
        $courseIds = Course::withTrashed()->whereNotNull('inserted_id')->pluck('id', 'inserted_id');

        $summary = $syncer->sync(
            Program::class,
            $remote,
            function (array $row) use ($departments) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    return null;
                }
                $label = trim((string) ($row['label'] ?? ''));
                $remoteDepartment = trim((string) ($row['department_id'] ?? ''));
                $department = is_numeric($remoteDepartment) ? $departments->get((int) $remoteDepartment) : null;

                return [
                    'name' => Str::limit($name, 255, ''),
                    'label' => $label === '' ? null : Str::limit($label, 20, ''),
                    'department_id' => $department?->id,
                    'department' => $department?->name ?? '',
                    'deleted_from_main_db' => MainDbSyncService::isDeletedInMainDb($row),
                ];
            },
            'programs',
            ['code' => ''],
            function (Program $program, array $row) use ($courseIds) {
                $remote = array_filter(array_map('trim', explode(',', (string) ($row['course_ids'] ?? ''))), 'is_numeric');
                if ($remote === []) {
                    return false;
                }
                $ids = collect($remote)->map(fn ($id) => $courseIds->get((int) $id))->filter()->unique()->values()->all();
                $changes = $program->courses()->sync($ids);

                return $changes['attached'] !== [] || $changes['detached'] !== [];
            },
        );

        return $this->success($summary, 'Programs synced successfully.');
    }
}
