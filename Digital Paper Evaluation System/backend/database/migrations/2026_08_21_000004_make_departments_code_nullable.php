<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * code is no longer required to create a department (see
     * Store/UpdateDepartmentRequest) — it was never unique anyway (see
     * test_department_code_does_not_need_to_be_unique), just an optional
     * label.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('code')->nullable(false)->change();
        });
    }
};
