<?php

namespace App\Http\Controllers\API\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// NOTE: no permission gating yet — every authenticated user (auth:sanctum,
// applied at the route-group level) can manage departments for now.
// Per-action permission middleware is intentionally deferred until
// permissions are set up separately (same as CourseController).
class DepartmentController extends Controller
{
    use ApiResponse;

    /**
     * One multi-purpose listing endpoint (same shape as CourseController::index):
     *  - ?id=5                    → single record (like show(), but via query param)
     *  - ?status=deleted          → only soft-deleted departments
     *  - ?status=all              → every matching department, unpaginated
     *      - ?table_fields=["name","code"]  → trims the SELECT to just those columns (+id)
     *      - ?is_active=yes|no    → filter by the department's own active/inactive flag
     *  - default                  → paginated, searchable listing
     *      - ?search=, ?name=, ?code=, ?short_description=, ?is_active=yes|no, ?sort_by=, ?sort_by_field=, ?per_page=
     *      - ?table_fields=["name","code"]  → same column-trimming as the "all" branch
     */
    public function index(Request $request): JsonResponse
    {
        $query = Department::query();

        if ($request->filled('status') && $request->string('status')->toString() === 'deleted') {
            $query->onlyTrashed();
        }

        if ($request->filled('id')) {
            $department = $query->find($request->input('id'));

            if (! $department) {
                return $this->notFound('No department found.');
            }

            return $this->success(new DepartmentResource($department), 'Department fetched successfully.');
        }

        if ($request->filled('status') && $request->string('status')->toString() === 'all') {
            if ($request->filled('table_fields')) {
                $this->applyFieldSelection($query, $request);
            }

            $this->applyActiveFilter($query, $request);
            $this->applySorting($query, $request);

            $departments = $query->get();

            return $this->success($departments, 'Departments fetched successfully.');
        }

        // Searches every column the list actually shows (see
        // DepartmentsView.vue's table: Name, Code, Status) plus
        // short_description (filterable but not itself a shown column,
        // left as-is from before) — "Active"/"Inactive" match the boolean
        // `status` column since that's how it's displayed.
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%");
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

        if ($request->filled('code')) {
            $query->where('code', 'like', '%'.$request->string('code')->toString().'%');
        }

        if ($request->filled('short_description')) {
            $query->where('short_description', 'like', '%'.$request->string('short_description')->toString().'%');
        }

        $fieldsRequested = $request->filled('table_fields');
        if ($fieldsRequested) {
            $this->applyFieldSelection($query, $request);
        }

        $this->applyActiveFilter($query, $request);
        $this->applySorting($query, $request);

        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $departments = $query->paginate($perPage);

        return $this->paginated(
            $departments,
            'Departments fetched successfully.',
            $fieldsRequested ? null : DepartmentResource::collection($departments),
        );
    }

    /**
     * Trims the SELECT to just the requested column names (always keeping
     * id). Department has no relations, so there's no dot-notation
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
        $sortField = in_array($request->input('sort_by_field'), ['id', 'name', 'code', 'status', 'created_at'], true)
            ? $request->input('sort_by_field')
            : 'id';
        $sortDir = Str::lower((string) $request->input('sort_by')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortDir);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= true;

        $department = Department::create($data);

        return $this->success(new DepartmentResource($department), 'Department created successfully.', 201);
    }

    public function show(Department $department): JsonResponse
    {
        return $this->success(new DepartmentResource($department), 'Department retrieved successfully.');
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department->update($request->validated());

        return $this->success(new DepartmentResource($department->fresh()), 'Department updated successfully.');
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->delete();

        return $this->success(null, 'Department deleted successfully.');
    }

    public function restore(Department $department): JsonResponse
    {
        $department->restore();

        return $this->success(new DepartmentResource($department), 'Department restored successfully.');
    }
}
