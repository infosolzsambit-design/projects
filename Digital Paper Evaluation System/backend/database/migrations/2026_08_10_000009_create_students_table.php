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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            // Not unique on purpose — multiple students commonly share the
            // same name in real life.
            $table->string('name');
            // "sem" is deliberately varchar, not an integer — e.g. "1st
            // Semester" — per explicit instruction.
            $table->string('sem', 50);
            // Free text, not a foreign key to programs — same pattern as
            // programs.department not linking to the departments table.
            $table->string('program_name')->nullable();
            $table->boolean('status')->default(true)->comment('1 = active, 0 = inactive');

            // No database foreign keys anywhere in this project — these are
            // plain indexed columns, resolved through model relationships.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('name');
            $table->index('sem');
            $table->index('program_name');
            $table->index('status');
            $table->index('deleted_at');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
