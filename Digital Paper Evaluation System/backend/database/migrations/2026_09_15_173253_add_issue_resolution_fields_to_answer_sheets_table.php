<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the Notifications page's "Resolve" action (see
     * NotificationController::resolveTimingIssue()/resolvePrintingIssue())
     * — who closed out a raised issue, and when. issue_admin_remarks is
     * the admin's own note at resolution time, kept separate from
     * issue_remarks (the teacher's original complaint) so neither
     * overwrites the other.
     */
    public function up(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dateTime('issue_fixed_at')->nullable()->after('issue_remarks');
            $table->unsignedBigInteger('issue_fixed_by')->nullable()->after('issue_fixed_at');
            $table->text('issue_admin_remarks')->nullable()->after('issue_fixed_by');

            $table->index('issue_fixed_by');
        });
    }

    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dropColumn(['issue_fixed_at', 'issue_fixed_by', 'issue_admin_remarks']);
        });
    }
};
