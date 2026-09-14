<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many minutes the assigning admin expects one answer sheet in
     * this assignment to take to evaluate — a whole number of minutes
     * (e.g. 60, 90), no decimals. Entered once per "Assign" click on
     * AssignTeacherView.vue, alongside the evaluation start/end window,
     * and stamped on every sheet that click touches (see
     * AssignTeacherService::assign()). Purely informational for now (no
     * scheduling logic reads it back yet).
     */
    public function up(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->unsignedInteger('evaluation_time_per_sheet')->nullable()->after('evaluation_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dropColumn('evaluation_time_per_sheet');
        });
    }
};
