<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time data repair for answer_sheets rows that were already
     * assigned/evaluated *before* the previous migration added
     * assigned_at/evaluated_at — those sheets would otherwise sit at
     * NULL forever and silently never appear in the admin dashboard's
     * "Evaluation Progress" trend chart (see DashboardController::
     * evaluationProgress()), even though their teacher_id/marks are very
     * much real. There's no way to recover the *exact* original moment
     * each of those happened, so this backfills the closest available
     * proxy — updated_at (falling back to created_at on the rare row
     * that somehow has neither) — good enough to place a handful of
     * legacy rows in roughly the right week rather than dropping them
     * from the chart entirely. Every assignment/evaluation from this
     * point forward is still stamped precisely by AssignTeacherService/
     * MyPendingCourseController themselves, not by this backfill.
     */
    public function up(): void
    {
        DB::table('answer_sheets')
            ->whereNotNull('teacher_id')
            ->whereNull('assigned_at')
            ->update(['assigned_at' => DB::raw('COALESCE(updated_at, created_at)')]);

        DB::table('answer_sheets')
            ->whereNotNull('marks')
            ->whereNull('evaluated_at')
            ->update(['evaluated_at' => DB::raw('COALESCE(updated_at, created_at)')]);
    }

    /**
     * Not meaningfully reversible — there's nothing to go back to (the
     * columns were NULL before this ran precisely because nothing had
     * ever stamped them), so this deliberately leaves the backfilled
     * values in place rather than blanking them back out.
     */
    public function down(): void {}
};
