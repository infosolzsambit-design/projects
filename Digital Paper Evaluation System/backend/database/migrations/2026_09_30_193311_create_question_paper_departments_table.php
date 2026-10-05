<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A question paper can belong to more than one department, so the single
     * question_papers.department_id (added just before) becomes a pivot.
     * Any department already set is carried over, then the column is dropped.
     */
    public function up(): void
    {
        // No database foreign keys anywhere in this project — plain indexed
        // columns, resolved through QuestionPaper::departments().
        Schema::create('question_paper_departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('question_paper_id')->index();
            $table->unsignedBigInteger('department_id')->index();
            $table->timestamps();
            $table->unique(['question_paper_id', 'department_id'], 'qp_departments_paper_department_unique');
        });

        if (Schema::hasColumn('question_papers', 'department_id')) {
            $now = now();
            DB::table('question_papers')->whereNotNull('department_id')->orderBy('id')
                ->each(function ($paper) use ($now) {
                    DB::table('question_paper_departments')->insert([
                        'question_paper_id' => $paper->id,
                        'department_id' => $paper->department_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });

            Schema::table('question_papers', function (Blueprint $table) {
                $table->dropIndex(['department_id']);
                $table->dropColumn('department_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('question_papers', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->after('course_id')->index()
                ->comment('Department this question paper belongs to (departments.id)');
        });

        // Keep one department per paper (the first) when going back.
        DB::table('question_paper_departments')->orderBy('id')->get()->groupBy('question_paper_id')
            ->each(fn ($rows, $paperId) => DB::table('question_papers')->where('id', $paperId)->update(['department_id' => $rows->first()->department_id]));

        Schema::dropIfExists('question_paper_departments');
    }
};
