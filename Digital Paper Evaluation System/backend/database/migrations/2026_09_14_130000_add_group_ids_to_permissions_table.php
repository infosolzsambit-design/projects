<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_group_id')->nullable()->after('guard_name');
            $table->unsignedBigInteger('permission_sub_group_id')->nullable()->after('permission_group_id');
            $table->index('permission_group_id');
            $table->index('permission_sub_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['permission_group_id', 'permission_sub_group_id']);
        });
    }
};
