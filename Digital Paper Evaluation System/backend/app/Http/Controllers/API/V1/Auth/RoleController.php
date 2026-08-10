<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Requests\SyncPermissionsRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:role-list', only: ['index']),
            new Middleware('permission:role-create', only: ['store']),
            new Middleware('permission:role-view', only: ['show']),
            new Middleware('permission:role-update', only: ['update', 'syncPermissions']),
            new Middleware('permission:role-delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $query = Role::query()->with('permissions');

        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }

        $roles = $query->orderBy('name')->paginate($perPage);

        return $this->paginated($roles, 'Roles retrieved successfully.', RoleResource::collection($roles));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = Role::create([
            'name' => $request->validated('name'),
            'guard_name' => config('auth.defaults.guard'),
        ]);

        return $this->success(new RoleResource($role), 'Role created successfully.', 201);
    }

    public function show(Role $role): JsonResponse
    {
        return $this->success(new RoleResource($role->load('permissions')), 'Role retrieved successfully.');
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role->update(['name' => $request->validated('name')]);

        return $this->success(new RoleResource($role->fresh('permissions')), 'Role updated successfully.');
    }

    public function destroy(Role $role): JsonResponse
    {
        if ((int) $role->id === (int) config('roles.super_admin_id')) {
            return $this->forbidden('The Super Admin role cannot be deleted.');
        }

        $role->delete();

        return $this->success(null, 'Role deleted successfully.');
    }

    public function syncPermissions(SyncPermissionsRequest $request, Role $role): JsonResponse
    {
        $permissionIds = array_map('intval', $request->validated('permission_ids', []));
        $before = $role->permissions()->pluck('permissions.id')->all();

        DB::transaction(function () use ($role, $permissionIds) {
            $role->syncPermissions($permissionIds);
        });

        $this->auditLog->log(
            event: 'permissions-synced',
            module: 'Role Management',
            description: 'Permissions synced for role.',
            auditable: $role,
            oldValues: ['permission_ids' => $before],
            newValues: ['permission_ids' => $permissionIds],
            tags: ['permission', 'role'],
        );

        return $this->success(new RoleResource($role->fresh('permissions')), 'Permissions synced successfully.');
    }
}
