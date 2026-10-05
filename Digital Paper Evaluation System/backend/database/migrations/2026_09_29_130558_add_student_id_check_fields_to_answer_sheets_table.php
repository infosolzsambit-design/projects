<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Result of reading the handwritten STUDENT'S ID NO. off each sheet's
     * cover page and comparing it with the system roll_no (see
     * StudentIdCheckService / ocr/read_student_id.py). Stored once per
     * sheet so the Generate Marksheet page is a plain count query, never a
     * re-read, even across thousands of sheets.
     */
    public function up(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->string('student_id_read', 32)->nullable()->after('roll_no')
                ->comment('Digits read from the handwritten STUDENT\'S ID NO. boxes on page 1; null until checked or when unreadable');
            // Only the *reading* outcome is stored — whether it matches is
            // compared against the current roll_no at query time, so a
            // roll number corrected later is never judged against a stale
            // result.
            $table->string('roll_no_check_status', 20)->nullable()->after('student_id_read')
                ->comment('null = not checked yet; read = digits read into student_id_read; unreadable = ID boxes not found/empty; error = the reader failed');
            $table->string('student_id_crop_path')->nullable()->after('roll_no_check_status')
                ->comment('/storage/... path of the cropped ID-box image shown for human review');
            $table->dateTime('roll_no_checked_at')->nullable()->after('student_id_crop_path')
                ->comment('When the ID was last read and compared');
            // Set when a person confirms (Generate Marksheet review modal)
            // that the ID written on the sheet is the system roll number —
            // student_id_read is then corrected to that roll number.
            $table->unsignedBigInteger('student_id_verified_by')->nullable()->after('roll_no_checked_at')
                ->comment('users.id of whoever manually confirmed the written ID equals the roll number; null = not manually confirmed');
            $table->dateTime('student_id_verified_at')->nullable()->after('student_id_verified_by')
                ->comment('When that manual confirmation happened');

            $table->index('roll_no_check_status');
        });
    }

    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dropIndex(['roll_no_check_status']);
            $table->dropColumn(['student_id_read', 'roll_no_check_status', 'student_id_crop_path', 'roll_no_checked_at', 'student_id_verified_by', 'student_id_verified_at']);
        });
    }
};
