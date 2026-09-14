<?php

namespace App\Http\Controllers\API\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\PermissionGroup\StorePermissionGroupRequest;
use App\Http\Requests\PermissionGroup\UpdatePermissionGroupRequest;
use App\Http\Resources\PermissionGroupResource;
use App\Models\PermissionGroup;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// NOTE: no permission gating yet — every authenticated user (auth:sanctum,
// applied at the route-group level) can manage permission groups for now.
// Per-action permission middleware is intentionally deferred until
// permissions are set up separately (same as DepartmentController).
class PermissionGroupController extends Controller
{
    use ApiResponse;

    /**
     * One multi-purpose listing endpoint (same shape as DepartmentController::index):
     *  - ?id=5                    → single record (like show(), but via query param)
     *  - ?status=deleted          → only soft-deleted groups
     *  - ?status=all              → every matching group, unpaginated
     *      - ?table_fields=["name"]  → trims the SELECT to just those columns (+id)
     *      - ?is_active=yes|no    → filter by the group's own active/inactive flag
     *  - default                  → paginated, searchable listing
     *      - ?search=, ?name=, ?is_active=yes|no, ?sort_by=, ?sort_by_field=, ?per_page=
     *      - ?table_fields=["name"]  → same column-trimming as the "all" branch
     */
    public function index(Request $request): JsonResponse
    {
        $query = PermissionGroup::query();

        if ($request->filled('status') && $request->string('status')->toString() === 'deleted') {
            $query->onlyTrashed();
        }

        if ($request->filled('id')) {
            $group = $query->find($request->input('id'));

            if (! $group) {
                return $this->notFound('No permission group found.');
            }

            return $this->success(new PermissionGroupResource($group), 'Permission group fetched successfully.');
        }

        if ($request->filled('status') && $request->string('status')->toString() === 'all') {
            if ($request->filled('table_fields')) {
                $this->applyFieldSelection($query, $request);
            }

            $this->applyActiveFilter($query, $request);
            $this->applySorting($query, $request);

            $groups = $query->get();

            return $this->success($groups, 'Permission groups fetched successfully.');
        }

        // Searches every column the list actually shows (see
        // PermissionGroupsView.vue's table: Name, Sort Order, Status) —
        // "Active"/"Inactive" match the boolean `status` column since
        // that's how it's displayed.
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
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

        $groups = $query->paginate($perPage);

        return $this->paginated(
            $groups,
            'Permission groups fetched successfully.',
            $fieldsRequested ? null : PermissionGroupResource::collection($groups),
        );
    }

    /**
     * Trims the SELECT to just the requested column names (always keeping
     * id). PermissionGroup has no relations, so there's no dot-notation
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
        // Default: last inserted first (highest id = most recently
        // created row) — `sort_order` is still available on request via
        // ?sort_by_field=sort_order for whoever wants the display-order
        // sequence instead.
        $sortField = in_array($request->input('sort_by_field'), ['id', 'name', 'sort_order', 'status', 'created_at'], true)
            ? $request->input('sort_by_field')
            : 'id';
        $sortDir = Str::lower((string) $request->input('sort_by')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortDir);
    }

    public function store(StorePermissionGroupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= true;

        $group = PermissionGroup::create($data);

        return $this->success(new PermissionGroupResource($group), 'Permission group created successfully.', 201);
    }

    public function show(PermissionGroup $permissionGroup): JsonResponse
    {
        return $this->success(new PermissionGroupResource($permissionGroup), 'Permission group retrieved successfully.');
    }

    public function update(UpdatePermissionGroupRequest $request, PermissionGroup $permissionGroup): JsonResponse
    {
        $permissionGroup->update($request->validated());

        return $this->success(new PermissionGroupResource($permissionGroup->fresh()), 'Permission group updated successfully.');
    }

    public function destroy(PermissionGroup $permissionGroup): JsonResponse
    {
        $permissionGroup->delete();

        return $this->success(null, 'Permission group deleted successfully.');
    }

    public function restore(PermissionGroup $permissionGroup): JsonResponse
    {
        $permissionGroup->restore();

        return $this->success(new PermissionGroupResource($permissionGroup), 'Permission group restored successfully.');
    }
}
