<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs EvaluatePaperView.vue's "Problem" action — a teacher can raise
     * a printing/timing issue against the physical sheet instead of
     * marking it (see MyPendingCourseController::raiseIssue()). Raising
     * one also resets marks/draft_marks/draft_marks_breakdown/
     * draft_annotations/consumed_time back to null (same "clean slate"
     * reasoning as AssignTeacherService::reassign()) so the sheet returns
     * to this teacher's pending list ready to be re-attempted once
     * whatever the issue was gets sorted out.
     *
     * issue_master_id points at issue_masters (no DB foreign key, same as
     * everywhere else in this app) — resolved by id, never by matching the
     * issue's name string.
     */
    public function up(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->unsignedBigInteger('issue_master_id')->nullable()->after('draft_annotations');
            $table->unsignedBigInteger('issue_raised_by')->nullable()->after('issue_master_id');
            $table->dateTime('issue_raised_at')->nullable()->after('issue_raised_by');
            $table->string('issue_status')->nullable()->after('issue_raised_at');
            $table->text('issue_remarks')->nullable()->after('issue_status');

            $table->index('issue_master_id');
            $table->index('issue_raised_by');
            $table->index('issue_status');
        });
    }

    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dropColumn(['issue_master_id', 'issue_raised_by', 'issue_raised_at', 'issue_status', 'issue_remarks']);
        });
    }
};
