<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "sem" (varchar, e.g. "3rd Semester") replaced with "semester"
     * (integer, e.g. 3). Existing string values aren't valid integers, so
     * this drops and recreates the column rather than attempting an
     * in-place type coercion — acceptable here since all current data is
     * seeded dummy data (see StudentSeeder), not real records.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('sem');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->unsignedInteger('semester')->after('name');
            $table->index('semester');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('semester');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('sem', 50)->after('name');
            $table->index('sem');
        });
    }
};
