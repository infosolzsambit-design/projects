<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the admin dashboard's "Evaluation Progress" weekly trend chart
     * (see DashboardController::adminSummary()) — neither event previously
     * had its own timestamp: an assignment only ever bumped teacher_id
     * (AssignTeacherService::assign()/reassign()), and a completed
     * evaluation only ever bumped marks (MyPendingCourseController::
     * submitMarks()), with updated_at too noisy to trust for either (draft
     * autosaves, reassignment, admin edits all touch it too). Nullable —
     * a sheet assigned/evaluated before this migration simply has no data
     * point for the trend chart rather than a wrong one.
     */
    public function up(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dateTime('assigned_at')->nullable()->after('teacher_id')
                ->comment('When this sheet was (most recently) handed to its current teacher_id — set by AssignTeacherService::assign()/reassign(), never by hand elsewhere');
            $table->dateTime('evaluated_at')->nullable()->after('marks')
                ->comment('When marks was actually submitted — set by MyPendingCourseController::submitMarks(), never by hand elsewhere');

            $table->index('assigned_at');
            $table->index('evaluated_at');
        });
    }

    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dropColumn(['assigned_at', 'evaluated_at']);
        });
    }
};
