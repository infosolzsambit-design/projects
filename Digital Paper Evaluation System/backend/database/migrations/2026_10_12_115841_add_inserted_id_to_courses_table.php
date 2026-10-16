<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Courses can come from a college's own system through its API — this
     * keeps that system's id for each course.
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->bigInteger('inserted_id')->nullable()->after('id')
                ->comment('Auto-increment id from the main db');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('inserted_id');
        });
    }
};
