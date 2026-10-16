<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A teacher can belong to several departments — one row per teacher +
     * department (replaces teacher_details.department_id). No foreign keys,
     * per the project rule; indexed instead.
     */
    public function up(): void
    {
        Schema::create('teacher_department_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index()->comment('users.id — the teacher');
            $table->unsignedBigInteger('department_id')->index()->comment('departments.id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['user_id', 'department_id'], 'teacher_dept_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_department_mappings');
    }
};
