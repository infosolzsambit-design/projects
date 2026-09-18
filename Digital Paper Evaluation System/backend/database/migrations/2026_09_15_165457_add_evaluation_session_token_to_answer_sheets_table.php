<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the marking screen's own URL (/my-pending-courses/:token/
     * evaluate) — a fresh, random, short-lived token is generated every
     * time "Start Evaluate"/"Continue Evaluate" is actually clicked (see
     * MyPendingCourseController::startEvaluation()), and that's the only
     * thing the URL now carries, not the sheet's own id. A copied/
     * bookmarked link stops working once it expires (see
     * config('evaluation.session_token_ttl_minutes')) or once a fresh one
     * is issued by a later legitimate click — see
     * MyPendingCourseController::showByToken().
     */
    public function up(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->string('evaluation_session_token')->nullable()->after('issue_remarks');
            $table->dateTime('evaluation_session_expires_at')->nullable()->after('evaluation_session_token');

            $table->index('evaluation_session_token');
        });
    }

    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dropColumn(['evaluation_session_token', 'evaluation_session_expires_at']);
        });
    }
};
