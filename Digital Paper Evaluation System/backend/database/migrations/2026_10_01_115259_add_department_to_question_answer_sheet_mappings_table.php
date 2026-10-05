<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_answer_sheet_mappings', function (Blueprint $table) {
            // Which of the question paper's departments this packet belongs
            // to (chosen on Answer Sheet Upload). No database foreign key
            // (project-wide rule). department_name is a copy of the name at
            // upload time, same as program_name, so lists/reports/exports
            // never need a join. Nullable: packets uploaded before this
            // existed have none.
            $table->unsignedBigInteger('department_id')->nullable()->after('program_name')->index()
                ->comment('departments.id — one of the question paper\'s departments');
            $table->string('department_name')->nullable()->after('department_id')
                ->comment('Department name copied at upload time');
        });
    }

    public function down(): void
    {
        Schema::table('question_answer_sheet_mappings', function (Blueprint $table) {
            $table->dropIndex(['department_id']);
            $table->dropColumn(['department_id', 'department_name']);
        });
    }
};
