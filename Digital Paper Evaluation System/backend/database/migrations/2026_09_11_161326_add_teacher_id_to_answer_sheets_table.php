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
        Schema::table('answer_sheets', function (Blueprint $table) {
            // No database foreign key (project-wide rule) — resolved
            // through App\Models\User the same way every other *_by/
            // *_id column in this app is. Null means "still pending" —
            // that's how AssignTeacherController/AssignTeacherService
            // find the pool of un-assigned sheets for a search, and how
            // QuestionAnswerSheetMappingResource's pending_answer_sheet_count
            // is computed.
            $table->unsignedBigInteger('teacher_id')->nullable()->after('question_answer_sheet_mapping_id');
            $table->index('teacher_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dropIndex(['teacher_id']);
            $table->dropColumn('teacher_id');
        });
    }
};
