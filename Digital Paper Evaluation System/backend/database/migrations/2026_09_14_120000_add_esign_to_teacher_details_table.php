<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teacher_details', function (Blueprint $table) {
            // The teacher's uploaded e-signature, stored as a BLOB (per
            // request) rather than alongside `photo`/`face_descriptor`'s
            // plain-text base64 columns — still holds the same "data:
            // image/…;base64,…" string, just as raw bytes instead of text.
            $table->binary('esign')->nullable()->after('face_descriptor');
        });

        // Blueprint::binary() only reaches MySQL's `blob` type (64 KB max)
        // — too small for a signature image's bytes — so widen it to
        // `longblob` (4 GB max, same ceiling as `photo`'s longtext) right
        // after creating it. No fluent longBlob() exists on this Laravel
        // version's schema builder, hence the raw statement.
        DB::statement('ALTER TABLE teacher_details MODIFY esign LONGBLOB NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teacher_details', function (Blueprint $table) {
            $table->dropColumn('esign');
        });
    }
};
