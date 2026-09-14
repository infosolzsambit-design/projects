<?php

namespace App\Http\Controllers\API\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\PermissionSubGroup\StorePermissionSubGroupRequest;
use App\Http\Requests\PermissionSubGroup\UpdatePermissionSubGroupRequest;
use App\Http\Resources\PermissionSubGroupResource;
use App\Models\PermissionSubGroup;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// NOTE: no permission gating yet — every authenticated user (auth:sanctum,
// applied at the route-group level) can manage permission sub-groups for
// now. Per-action permission middleware is intentionally deferred until
// permissions are set up separately (same as PermissionGroupController).
class PermissionSubGroupController extends Controller
{
    use ApiResponse;

    /**
     * One multi-purpose listing endpoint (same shape as
     * PermissionGroupController::index):
     *  - ?id=5                       → single record (like show(), but via query param)
     *  - ?status=deleted             → only soft-deleted sub-groups
     *  - ?status=all                 → every matching sub-group, unpaginated
     *      - ?table_fields=["name"]  → trims the SELECT to just those columns (+id)
     *      - ?is_active=yes|no       → filter by the sub-group's own active/inactive flag
     *  - default                     → paginated, searchable listing
     *      - ?search=, ?name=, ?permission_group_id=, ?is_active=yes|no, ?sort_by=, ?sort_by_field=, ?per_page=
     *      - ?table_fields=["name"]  → same column-trimming as the "all" branch
     */
    public function index(Request $request): JsonResponse
    {
        $query = PermissionSubGroup::query();

        if ($request->filled('status') && $request->string('status')->toString() === 'deleted') {
            $query->onlyTrashed();
        }

        if ($request->filled('id')) {
            $subGroup = $query->with('group:id,name')->find($request->input('id'));

            if (! $subGroup) {
                return $this->notFound('No permission sub-group found.');
            }

            return $this->success(new PermissionSubGroupResource($subGroup), 'Permission sub-group fetched successfully.');
        }

        if ($request->filled('status') && $request->string('status')->toString() === 'all') {
            $fieldsRequested = $request->filled('table_fields');
            if ($fieldsRequested) {
                $this->applyFieldSelection($query, $request);
            } else {
                $query->with('group:id,name');
            }

            $this->applyGroupFilter($query, $request);
            $this->applyActiveFilter($query, $request);
            $this->applySorting($query, $request);

            $subGroups = $query->get();

            return $this->success($subGroups, 'Permission sub-groups fetched successfully.');
        }

        // Searches every column the list actually shows (see
        // PermissionSubGroupsView.vue's table: Name, Group, Sort Order,
        // Status) — "Active"/"Inactive" match the boolean `status` column
        // since that's how it's displayed, and a parent group's name
        // matches too.
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('group', function ($gq) use ($search) {
                        $gq->where('name', 'like', "%{$search}%");
                    });
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

        $this->applyGroupFilter($query, $request);

        $fieldsRequested = $request->filled('table_fields');
        if ($fieldsRequested) {
            $this->applyFieldSelection($query, $request);
        } else {
            $query->with('group:id,name');
        }

        $this->applyActiveFilter($query, $request);
        $this->applySorting($query, $request);

        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $subGroups = $query->paginate($perPage);

        return $this->paginated(
            $subGroups,
            'Permission sub-groups fetched successfully.',
            $fieldsRequested ? null : PermissionSubGroupResource::collection($subGroups),
        );
    }

    /**
     * Trims the SELECT to just the requested column names (always keeping
     * id and permission_group_id, since the latter is what the `group`
     * relation is resolved through).
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

    private function applyGroupFilter(Builder $query, Request $request): void
    {
        if ($request->filled('permission_group_id')) {
            $query->where('permission_group_id', $request->integer('permission_group_id'));
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

    public function store(StorePermissionSubGroupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= true;

        $subGroup = PermissionSubGroup::create($data);

        return $this->success(new PermissionSubGroupResource($subGroup->load('group:id,name')), 'Permission sub-group created successfully.', 201);
    }

    public function show(PermissionSubGroup $permissionSubGroup): JsonResponse
    {
        return $this->success(new PermissionSubGroupResource($permissionSubGroup->load('group:id,name')), 'Permission sub-group retrieved successfully.');
    }

    public function update(UpdatePermissionSubGroupRequest $request, PermissionSubGroup $permissionSubGroup): JsonResponse
    {
        $permissionSubGroup->update($request->validated());

        return $this->success(new PermissionSubGroupResource($permissionSubGroup->fresh()->load('group:id,name')), 'Permission sub-group updated successfully.');
    }

    public function destroy(PermissionSubGroup $permissionSubGroup): JsonResponse
    {
        $permissionSubGroup->delete();

        return $this->success(null, 'Permission sub-group deleted successfully.');
    }

    public function restore(PermissionSubGroup $permissionSubGroup): JsonResponse
    {
        $permissionSubGroup->restore();

        return $this->success(new PermissionSubGroupResource($permissionSubGroup->load('group:id,name')), 'Permission sub-group restored successfully.');
    }
}
