<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same fix as courses.code / programs.code: a plain DB-level unique
     * constraint on (name, guard_name) doesn't know about deleted_at, so it
     * permanently blocks reusing a name that only a *trashed* role/
     * permission still holds. Uniqueness among active rows is enforced at
     * the application layer instead (see Store/UpdateRoleRequest and
     * Store/UpdatePermissionRequest, scoped to whereNull('deleted_at')).
     * Safe to drop — Spatie's own package code never relies on the DB
     * enforcing this; it does its own name+guard lookups.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_name_guard_name_unique');
            $table->index(['name', 'guard_name']);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropUnique('permissions_name_guard_name_unique');
            $table->index(['name', 'guard_name']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['name', 'guard_name']);
            $table->unique(['name', 'guard_name']);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex(['name', 'guard_name']);
            $table->unique(['name', 'guard_name']);
        });
    }
};
