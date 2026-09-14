<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teacher_details', function (Blueprint $table) {
            // No DB-level unique constraint — a plain unique index doesn't
            // know about deleted_at and would block reusing a code that
            // only a *trashed* teacher still holds (same fix as
            // courses.code/programs.code/departments.name). Uniqueness
            // among active teachers is enforced at the application layer.
            $table->string('emp_code')->nullable()->after('user_id');
            $table->index('emp_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teacher_details', function (Blueprint $table) {
            $table->dropIndex(['emp_code']);
            $table->dropColumn('emp_code');
        });
    }
};
