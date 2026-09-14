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
        Schema::table('teacher_details', function (Blueprint $table) {
            // Whether this teacher is even expected to have a face scan on
            // file at all — distinct from face_descriptor (whether one has
            // actually been captured yet). Set via the Teachers list's own
            // "Face Scan Applicable" row action (see TeachersView.vue),
            // not the main edit form. Defaults true: most teachers do need
            // one, this flag exists for the exceptions.
            $table->boolean('face_scan_applicable')->default(true)
                ->comment('1 = this teacher is expected to have a face scan, 0 = exempt')
                ->after('face_descriptor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teacher_details', function (Blueprint $table) {
            $table->dropColumn('face_scan_applicable');
        });
    }
};
