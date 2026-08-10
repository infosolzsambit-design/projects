<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pure pivot table for the Program <-> Course many-to-many relationship
     * — same minimal shape as Spatie's role_has_permissions (see
     * 0001_01_01_000004_create_permission_tables.php): no id, no
     * timestamps, just the two columns and a composite primary key. No
     * database foreign keys, per this project's convention — enforced via
     * validation (Store/UpdateProgramRequest) instead.
     */
    public function up(): void
    {
        Schema::create('program_course_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('program_id');
            $table->unsignedBigInteger('course_id');

            $table->primary(['program_id', 'course_id']);
            $table->index('program_id');
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_course_mappings');
    }
};
