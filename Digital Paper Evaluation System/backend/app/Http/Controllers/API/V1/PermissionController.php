<?php

namespace App\Http\Controllers\API\V1;

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

        $query = Permission::query();

        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }

        $permissions = $query->orderBy('name')->paginate($perPage);

        return $this->paginated($permissions, 'Permissions retrieved successfully.', PermissionResource::collection($permissions));
    }

    public function store(StorePermissionRequest $request): JsonResponse
    {
        $permission = Permission::create([
            'name' => $request->validated('name'),
            'guard_name' => config('auth.defaults.guard'),
        ]);

        return $this->success(new PermissionResource($permission), 'Permission created successfully.', 201);
    }

    public function show(Permission $permission): JsonResponse
    {
        return $this->success(new PermissionResource($permission), 'Permission retrieved successfully.');
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        $permission->update(['name' => $request->validated('name')]);

        return $this->success(new PermissionResource($permission->fresh()), 'Permission updated successfully.');
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $permission->delete();

        return $this->success(null, 'Permission deleted successfully.');
    }
}
