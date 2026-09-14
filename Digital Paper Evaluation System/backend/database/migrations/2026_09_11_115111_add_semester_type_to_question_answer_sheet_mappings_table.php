<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same "Odd"/"Even" session field as question_papers.semester_type —
     * auto-filled from the picked question paper (see
     * AnswerSheetUploadView.vue's onQuestionPaperChange(), same as
     * course_id/semester already are) but still its own column here rather
     * than only read off the paper, since this packet's own value can be
     * overridden same as course_id/semester can. Nullable for the same
     * reason as question_papers.semester_type: packets created before this
     * field existed have nothing to backfill it with.
     */
    public function up(): void
    {
        Schema::table('question_answer_sheet_mappings', function (Blueprint $table) {
            $table->string('semester_type', 10)->nullable()->after('semester')
                ->comment('odd | even — the exam session this packet belongs to');
            $table->index('semester_type');
        });
    }

    public function down(): void
    {
        Schema::table('question_answer_sheet_mappings', function (Blueprint $table) {
            $table->dropIndex(['semester_type']);
            $table->dropColumn('semester_type');
        });
    }
};
