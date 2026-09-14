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
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('designation');
            $table->string('location')->nullable()->after('gender');
            $table->text('about')->nullable()->after('location');
            // A base64 data URL of the last captured profile photo — same
            // format the browser's canvas.toDataURL() already produces (see
            // TeacherRegisterView.vue's prior in-memory-only prototype of
            // this). No file-storage subsystem exists in this project yet,
            // so this stays a plain column rather than introducing one.
            $table->longText('photo')->nullable()->after('about');
            // The face-api.js 128-d face descriptor for this photo, JSON-
            // encoded — compared server-side in ProfileController::verifyFace()
            // against a freshly-scanned descriptor, so the reference
            // descriptor itself is never sent back down to the browser.
            $table->longText('face_descriptor')->nullable()->after('photo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teacher_details', function (Blueprint $table) {
            $table->dropColumn(['gender', 'location', 'about', 'photo', 'face_descriptor']);
        });
    }
};
