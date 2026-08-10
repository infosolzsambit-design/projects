<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Naming convention: {module}-{action}. Paper/evaluation permissions are
     * seeded ahead of those future modules per the project's prep-the-ground
     * rule — no controller uses them yet.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'dashboard-view',

        'user-list', 'user-create', 'user-view', 'user-update', 'user-delete', 'user-restore',

        'role-list', 'role-create', 'role-view', 'role-update', 'role-delete',

        'permission-list', 'permission-create', 'permission-update', 'permission-delete',

        'audit-list', 'audit-view',

        'paper-list', 'paper-create', 'paper-view', 'paper-update', 'paper-delete',

        'evaluation-list', 'evaluation-create', 'evaluation-view', 'evaluation-update', 'evaluation-delete',
    ];

    public function run(): void
    {
        $guard = config('auth.defaults.guard');

        foreach (self::PERMISSIONS as $name) {
            Permission::withTrashed()->firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]);
        }
    }
}
