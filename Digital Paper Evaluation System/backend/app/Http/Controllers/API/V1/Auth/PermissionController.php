<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\StorePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PermissionController extends Controller implements HasMiddleware
{
    use ApiResponse;

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            // No dedicated "permission-view" in the permission list — list
            // covers viewing a single permission too.
            new Middleware('permission:permission-list', only: ['index', 'show']),
            new Middleware('permission:permission-create', only: ['store']),
            new Middleware('permission:permission-update', only: ['update']),
            new Middleware('permission:permission-delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $query = Permission::query()->with(['group:id,name,sort_order', 'subGroup:id,name,sort_order']);

        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }

        $query->orderBy('id', 'desc');

        // ?status=all → every matching permission, unpaginated (same escape
        // hatch as the Master-module controllers) — needed by pickers like
        // RoleFormView's permission checklist, which need the *whole* list,
        // not just what fits under max_per_page.
        if ($request->string('status')->toString() === 'all') {
            $permissions = $query->get();

            return $this->success(PermissionResource::collection($permissions), 'Permissions retrieved successfully.');
        }

        $permissions = $query->paginate($perPage);

        return $this->paginated($permissions, 'Permissions retrieved successfully.', PermissionResource::collection($permissions));
    }

    public function store(StorePermissionRequest $request): JsonResponse
    {
        $permission = Permission::create([
            'name' => $request->validated('name'),
            'guard_name' => config('auth.defaults.guard'),
            'permission_group_id' => $request->validated('permission_group_id'),
            'permission_sub_group_id' => $request->validated('permission_sub_group_id'),
        ]);

        return $this->success(new PermissionResource($permission->load(['group:id,name,sort_order', 'subGroup:id,name,sort_order'])), 'Permission created successfully.', 201);
    }

    public function show(Permission $permission): JsonResponse
    {
        return $this->success(new PermissionResource($permission->load(['group:id,name,sort_order', 'subGroup:id,name,sort_order'])), 'Permission retrieved successfully.');
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        $permission->update($request->validated());

        return $this->success(new PermissionResource($permission->fresh()->load(['group:id,name,sort_order', 'subGroup:id,name,sort_order'])), 'Permission updated successfully.');
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $permission->delete();

        return $this->success(null, 'Permission deleted successfully.');
    }
}
