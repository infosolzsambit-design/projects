<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fixed set of evaluation-issue types a teacher can raise against
     * an answer sheet (see answer_sheets.issue_master_id, added alongside
     * this — MyPendingCourseController::raiseIssue()). Same shape as every
     * other master table (Department, Course, ...); seeded with two rows
     * by IssueMasterSeeder and never managed through its own admin
     * screen — nothing in this app edits it beyond that seeder for now.
     */
    public function up(): void
    {
        Schema::create('issue_masters', function (Blueprint $table) {
            $table->id();
            // No DB-level unique constraint — same reasoning as every
            // other master table here (departments, courses, ...): a
            // plain unique index doesn't know about deleted_at and would
            // block reusing a name only a *trashed* row still holds.
            $table->string('name');
            $table->boolean('status')->default(true)->comment('1 = active, 0 = inactive');

            // No database foreign keys anywhere in this project — these
            // are plain indexed columns, resolved through model relationships.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('name');
            $table->index('status');
            $table->index('deleted_at');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_masters');
    }
};
