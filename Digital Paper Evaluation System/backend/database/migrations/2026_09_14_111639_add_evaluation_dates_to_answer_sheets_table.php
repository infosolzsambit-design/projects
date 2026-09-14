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
            // The evaluation window an assigned teacher is expected to mark
            // this sheet within — captured once, on AssignTeacherView.vue's
            // "Assign" click (see AssignTeacherService::assign()), and
            // stamped on every sheet that assignment touches. Nullable:
            // sheets that are still pending (teacher_id IS NULL) never get
            // a window. A future "change timings" screen will let these be
            // edited after the fact — hence storing them per-sheet here
            // rather than deriving them from the assignment event alone.
            $table->date('evaluation_start_date')->nullable()->after('teacher_id');
            $table->date('evaluation_end_date')->nullable()->after('evaluation_start_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dropColumn(['evaluation_start_date', 'evaluation_end_date']);
        });
    }
};
