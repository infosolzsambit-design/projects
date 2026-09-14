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
            // No database foreign key (project-wide rule) — resolved through
            // App\Models\Department the same way user_id resolves through
            // User. The `department` string column is kept alongside this
            // as a denormalized copy of the department's name at the time
            // it was picked, so listings/search never need to join out.
            $table->unsignedBigInteger('department_id')->nullable()->after('department');
            $table->index('department_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teacher_details', function (Blueprint $table) {
            $table->dropIndex(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
