<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            // Who assigned (or last reassigned) this sheet to its teacher —
            // set alongside assigned_at. No database foreign key
            // (project-wide rule).
            $table->unsignedBigInteger('assigned_by')->nullable()->after('assigned_at')->index()
                ->comment('users.id of the admin who assigned/reassigned this sheet');
        });

        // Backfill already-assigned sheets from the audit log: each Assign /
        // Reassign writes one audit row at the same moment it stamps
        // assigned_at, so the nearest such row (within 5 minutes) gives who
        // did it. Sheets with no matching audit row stay NULL.
        $audits = DB::table('audits')
            ->whereIn('event', ['answer-sheets-assigned', 'answer-sheets-reassigned'])
            ->whereNotNull('user_id')
            ->get(['user_id', 'created_at'])
            ->map(fn ($a) => ['user_id' => $a->user_id, 'at' => strtotime($a->created_at)]);

        if ($audits->isEmpty()) {
            return;
        }

        DB::table('answer_sheets')->whereNotNull('assigned_at')->whereNull('assigned_by')
            ->select('assigned_at')->distinct()->orderBy('assigned_at')
            ->pluck('assigned_at')
            ->each(function ($assignedAt) use ($audits) {
                $at = strtotime($assignedAt);
                $nearest = $audits->sortBy(fn ($a) => abs($a['at'] - $at))->first();
                if ($nearest && abs($nearest['at'] - $at) <= 300) {
                    DB::table('answer_sheets')->where('assigned_at', $assignedAt)->whereNull('assigned_by')
                        ->update(['assigned_by' => $nearest['user_id']]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dropIndex(['assigned_by']);
            $table->dropColumn('assigned_by');
        });
    }
};
