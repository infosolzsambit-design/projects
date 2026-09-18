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
        // config('roles.super_admin_id') is a list — every id in it gets
        // full authorization bypass (see that config file's own docblock),
        // but only the *first* one is the actual "Super Admin" role this
        // seeder owns creating; any further id is expected to already
        // exist (e.g. a separately created "Admin" role) and is left alone.
        $superAdminId = (int) ((array) config('roles.super_admin_id'))[0];

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

        // No fixed id for Teacher — unlike Super Admin, it has no
        // code-level special treatment (see config/roles.php's own
        // docblock), so this only needs *a* role named "Teacher" to exist
        // for TeacherController::store()/TeacherBulkUploadService to
        // auto-assign on creation; whatever id it lands on is fine.
        $teacherRole = Role::withTrashed()->where('name', 'Teacher')->where('guard_name', $guard)->first();

        if (! $teacherRole) {
            // No permissions synced yet — permission gating for the Teacher
            // role is deferred until permissions are set up per module.
            Role::create([
                'name' => 'Teacher',
                'guard_name' => $guard,
            ]);
        } elseif ($teacherRole->trashed()) {
            $teacherRole->restore();
        }
    }
}
