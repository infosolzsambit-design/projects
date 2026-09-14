<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $guard = config('auth.defaults.guard');
        $superAdminId = (int) config('roles.super_admin_id');

        $role = Role::withTrashed()->find($superAdminId);

        if (! $role) {
            // forceCreate to set the explicit, well-known ID — role checks
            // throughout the app match on this ID, never the role name.
            $role = Role::forceCreate([
                'id' => $superAdminId,
                'name' => 'Super Admin',
                'guard_name' => $guard,
            ]);
        } elseif ($role->trashed()) {
            $role->restore();
        }

        // Gate::before already grants Super Admin every ability, but the
        // role's own permission list should still reflect that explicitly.
        $role->syncPermissions(Permission::withTrashed()->pluck('id')->all());

        $teacherId = (int) config('roles.teacher_id');
        $teacherRole = Role::withTrashed()->find($teacherId);

        if (! $teacherRole) {
            // No permissions synced yet — permission gating for the Teacher
            // role is deferred until permissions are set up per module.
            Role::forceCreate([
                'id' => $teacherId,
                'name' => 'Teacher',
                'guard_name' => $guard,
            ]);
        } elseif ($teacherRole->trashed()) {
            $teacherRole->restore();
        }
    }
}
