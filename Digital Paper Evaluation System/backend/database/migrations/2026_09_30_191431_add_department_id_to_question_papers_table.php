<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_papers', function (Blueprint $table) {
            // No database foreign key (project-wide rule) — resolved through
            // QuestionPaper::department(). Required by the setup/edit form for
            // every new or edited paper, but nullable here so papers set up
            // before this column existed stay valid until someone edits them.
            $table->unsignedBigInteger('department_id')->nullable()->after('course_id')->index()
                ->comment('Department this question paper belongs to (departments.id)');
        });
    }

    public function down(): void
    {
        Schema::table('question_papers', function (Blueprint $table) {
            $table->dropIndex(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
