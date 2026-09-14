<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widens evaluation_start_date/evaluation_end_date (see the migration
     * that first added them) from date-only to a full datetime — the admin
     * now picks a time of day alongside the day on AssignTeacherView.vue's
     * two DatePicker(:with-time) fields, so the window a teacher's
     * evaluation is open for can start/end at a specific hour:minute, not
     * just "some time that whole day". Existing rows (date-only so far)
     * keep their date and pick up a 00:00 time component automatically —
     * nothing to backfill by hand.
     */
    public function up(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dateTime('evaluation_start_date')->nullable()->change();
            $table->dateTime('evaluation_end_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->date('evaluation_start_date')->nullable()->change();
            $table->date('evaluation_end_date')->nullable()->change();
        });
    }
};
