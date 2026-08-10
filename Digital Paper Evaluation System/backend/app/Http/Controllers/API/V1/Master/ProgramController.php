<?php

namespace App\Http\Controllers\API\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Program\StoreProgramRequest;
use App\Http\Requests\Program\UpdateProgramRequest;
use App\Http\Resources\ProgramResource;
use App\Models\Program;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
     *      - ?search=, ?name=, ?department=, ?code=, ?is_active=yes|no, ?sort_by=, ?sort_by_field=, ?per_page=
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
                $query->with('courses:id,name,code');
            }

            $this->applyActiveFilter($query, $request);
            $this->applySorting($query, $request);

            $programs = $query->get();

            return $this->success($programs, 'Programs fetched successfully.');
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('name')) {
            $query->where('name', 'like', '%'.$request->string('name')->toString().'%');
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
            $query->with('courses:id,name,code');
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
        $sortField = in_array($request->input('sort_by_field'), ['id', 'name', 'department', 'code', 'status', 'created_at'], true)
            ? $request->input('sort_by_field')
            : 'id';
        $sortDir = Str::lower((string) $request->input('sort_by')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortDir);
    }

    public function store(StoreProgramRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= true;
        $courseIds = $data['course_ids'];
        unset($data['course_ids']);

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

        return $this->success(new ProgramResource($program->load('courses')), 'Program created successfully.', 201);
    }

    public function show(Program $program): JsonResponse
    {
        return $this->success(new ProgramResource($program->load('courses')), 'Program retrieved successfully.');
    }

    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $data = $request->validated();

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

        $program->update($data);

        return $this->success(new ProgramResource($program->fresh()->load('courses')), 'Program updated successfully.');
    }

    public function destroy(Program $program): JsonResponse
    {
        $program->delete();

        return $this->success(null, 'Program deleted successfully.');
    }

    public function restore(Program $program): JsonResponse
    {
        $program->restore();

        return $this->success(new ProgramResource($program), 'Program restored successfully.');
    }
}
