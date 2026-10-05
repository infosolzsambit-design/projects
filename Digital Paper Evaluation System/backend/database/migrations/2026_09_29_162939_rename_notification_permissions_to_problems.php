<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The "Notifications" page was renamed "Problems". Renamed in place (not
     * deleted/recreated) so every role/user that already holds these
     * permissions keeps them. Safe to run on a database that never had
     * them — each update just matches nothing.
     */
    private const PERMISSIONS = [
        'notification-list' => 'problem-list',
        'notification-resolve' => 'problem-resolve',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $old => $new) {
            DB::table('permissions')->where('name', $old)->update(['name' => $new, 'updated_at' => now()]);
        }
        DB::table('permission_groups')->where('name', 'Notifications')->update(['name' => 'Problems', 'updated_at' => now()]);
        DB::table('permission_sub_groups')->where('name', 'Notifications')->update(['name' => 'Problems', 'updated_at' => now()]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::PERMISSIONS as $old => $new) {
            DB::table('permissions')->where('name', $new)->update(['name' => $old, 'updated_at' => now()]);
        }
        DB::table('permission_groups')->where('name', 'Problems')->update(['name' => 'Notifications', 'updated_at' => now()]);
        DB::table('permission_sub_groups')->where('name', 'Problems')->update(['name' => 'Notifications', 'updated_at' => now()]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
