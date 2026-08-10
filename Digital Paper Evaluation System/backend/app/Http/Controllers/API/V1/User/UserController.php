<?php

namespace App\Http\Controllers\API\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncPermissionsRequest;
use App\Http\Requests\User\AssignPermissionRequest;
use App\Http\Requests\User\AssignRoleRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\SyncRolesRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class UserController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:user-list', only: ['index']),
            new Middleware('permission:user-create', only: ['store']),
            new Middleware('permission:user-view', only: ['show']),
            new Middleware('permission:user-update', only: ['update', 'updateStatus']),
            new Middleware('permission:user-delete', only: ['destroy']),
            new Middleware('permission:user-restore', only: ['restore']),
            new Middleware('permission:role-update', only: ['assignRole', 'removeRole', 'syncRoles']),
            new Middleware('permission:permission-update', only: ['assignPermission', 'removePermission', 'syncPermissions']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $query = User::query()->with('roles');

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->boolean('status'));
        }

        if ($request->filled('role')) {
            $roleId = (int) $request->input('role');
            $query->whereHas('roles', fn ($q) => $q->where('roles.id', $roleId));
        }

        $sortBy = in_array($request->string('sort_by')->toString(), ['name', 'username', 'email', 'created_at'], true)
            ? $request->string('sort_by')->toString()
            : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $users = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return $this->paginated($users, 'Users retrieved successfully.', UserResource::collection($users));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        // Explicit rather than relying on the DB column default, so the
        // in-memory model (and thus the response) reflects it immediately.
        $data['is_active'] ??= true;

        $user = User::create($data);

        return $this->success(new UserResource($user), 'User created successfully.', 201);
    }

    public function show(User $user): JsonResponse
    {
        return $this->success(
            new UserResource($user->load(['roles', 'permissions'])),
            'User retrieved successfully.',
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('password', $data) && empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        return $this->success(
            new UserResource($user->fresh(['roles', 'permissions'])),
            'User updated successfully.',
        );
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->forbidden('You cannot delete your own account.');
        }

        $user->delete();

        return $this->success(null, 'User deleted successfully.');
    }

    public function restore(User $user): JsonResponse
    {
        $user->restore();

        return $this->success(new UserResource($user), 'User restored successfully.');
    }

    /**
     * Force delete is restricted to Super Admin only, checked directly by
     * role ID (never by name) rather than a normal permission — this is a
     * deliberately different, higher tier of access than user-delete.
     */
    public function forceDestroy(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->hasRole(config('roles.super_admin_id'))) {
            return $this->forbidden('Only a Super Admin can permanently delete a user.');
        }

        if ($user->id === $request->user()->id) {
            return $this->forbidden('You cannot delete your own account.');
        }

        $user->forceDelete();

        return $this->success(null, 'User permanently deleted.');
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $old = (bool) $user->is_active;
        $new = (bool) $request->validated('is_active');

        $user->is_active = $new;
        $user->save();

        $this->auditLog->log(
            event: 'status-changed',
            module: 'User Management',
            description: 'User status changed from '.($old ? 'Active' : 'Inactive').' to '.($new ? 'Active' : 'Inactive').'.',
            auditable: $user,
            oldValues: ['is_active' => $old],
            newValues: ['is_active' => $new],
            tags: ['status'],
        );

        return $this->success(new UserResource($user), 'User status updated successfully.');
    }

    public function assignRole(AssignRoleRequest $request, User $user): JsonResponse
    {
        $roleId = (int) $request->validated('role_id');
        $user->assignRole($roleId);

        $this->auditLog->log(
            event: 'role-assigned',
            module: 'Role Management',
            description: "Role [{$roleId}] assigned to user.",
            auditable: $user,
            newValues: ['role_id' => $roleId],
            tags: ['role'],
        );

        return $this->success(new UserResource($user->fresh('roles')), 'Role assigned successfully.');
    }

    public function removeRole(AssignRoleRequest $request, User $user): JsonResponse
    {
        $roleId = (int) $request->validated('role_id');
        $user->removeRole($roleId);

        $this->auditLog->log(
            event: 'role-removed',
            module: 'Role Management',
            description: "Role [{$roleId}] removed from user.",
            auditable: $user,
            oldValues: ['role_id' => $roleId],
            tags: ['role'],
        );

        return $this->success(new UserResource($user->fresh('roles')), 'Role removed successfully.');
    }

    public function syncRoles(SyncRolesRequest $request, User $user): JsonResponse
    {
        $roleIds = array_map('intval', $request->validated('role_ids', []));
        $before = $user->roles()->pluck('roles.id')->all();

        DB::transaction(function () use ($user, $roleIds) {
            $user->syncRoles($roleIds);
        });

        $this->auditLog->log(
            event: 'roles-synced',
            module: 'Role Management',
            description: 'Roles synced for user.',
            auditable: $user,
            oldValues: ['role_ids' => $before],
            newValues: ['role_ids' => $roleIds],
            tags: ['role'],
        );

        return $this->success(new UserResource($user->fresh('roles')), 'Roles synced successfully.');
    }

    public function assignPermission(AssignPermissionRequest $request, User $user): JsonResponse
    {
        $permissionId = (int) $request->validated('permission_id');
        $user->givePermissionTo($permissionId);

        $this->auditLog->log(
            event: 'permission-assigned',
            module: 'Permission Management',
            description: "Permission [{$permissionId}] assigned directly to user.",
            auditable: $user,
            newValues: ['permission_id' => $permissionId],
            tags: ['permission'],
        );

        return $this->success(new UserResource($user->fresh('permissions')), 'Permission assigned successfully.');
    }

    public function removePermission(AssignPermissionRequest $request, User $user): JsonResponse
    {
        $permissionId = (int) $request->validated('permission_id');
        $user->revokePermissionTo($permissionId);

        $this->auditLog->log(
            event: 'permission-removed',
            module: 'Permission Management',
            description: "Permission [{$permissionId}] removed from user.",
            auditable: $user,
            oldValues: ['permission_id' => $permissionId],
            tags: ['permission'],
        );

        return $this->success(new UserResource($user->fresh('permissions')), 'Permission removed successfully.');
    }

    public function syncPermissions(SyncPermissionsRequest $request, User $user): JsonResponse
    {
        $permissionIds = array_map('intval', $request->validated('permission_ids', []));
        $before = $user->permissions()->pluck('permissions.id')->all();

        DB::transaction(function () use ($user, $permissionIds) {
            $user->syncPermissions($permissionIds);
        });

        $this->auditLog->log(
            event: 'permissions-synced',
            module: 'Permission Management',
            description: 'Direct permissions synced for user.',
            auditable: $user,
            oldValues: ['permission_ids' => $before],
            newValues: ['permission_ids' => $permissionIds],
            tags: ['permission'],
        );

        return $this->success(new UserResource($user->fresh('permissions')), 'Permissions synced successfully.');
    }
}
