<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Two "Is Face Scan Applicable" rows existed on the General Settings
     * page; 'is_face_scan_applicable' is the one kept (and now the one
     * MyPendingCourseController::startEvaluation() reads).
     */
    public function up(): void
    {
        DB::table('general_settings')->where('field_name', 'face_scan_applicable')->delete();
    }

    public function down(): void
    {
        //
    }
};
