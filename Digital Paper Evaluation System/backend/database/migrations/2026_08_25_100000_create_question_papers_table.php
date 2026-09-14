<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One uploaded question paper (a PDF) plus the exam metadata it was
     * uploaded under. Setup is a two-step flow (see QuestionPaperController
     * and QuestionPapersView.vue / QuestionPaperSetupView.vue /
     * QuestionPaperConfigureView.vue):
     *   1. store() — exam_year/course/semester + the PDF itself, status
     *      starts "draft".
     *   2. update() — the actual group/question/marks breakdown (see
     *      question_paper_groups / question_paper_questions below) is
     *      attached afterward, once someone has actually looked at the PDF
     *      and transcribed its structure; status becomes "ready".
     * No two question papers look alike (compulsory questions, "answer any
     * N of M", OR-alternatives, ...) — that structure is never inferred
     * from the PDF itself, only entered by hand against whatever the PDF
     * actually says.
     */
    public function up(): void
    {
        Schema::create('question_papers', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('exam_year');
            // No DB foreign keys anywhere in this project — plain indexed
            // column, resolved through the course() relationship.
            $table->unsignedBigInteger('course_id');
            $table->unsignedTinyInteger('semester');
            // Always "/storage/question-papers/xxx.pdf" — ready to use as-is
            // on the frontend, same convention as GeneralSettingController's
            // file fields.
            $table->string('pdf_path');
            $table->unsignedInteger('full_marks')->nullable();
            $table->string('time_allotted', 100)->nullable();
            $table->string('status')->default('draft')->comment('draft = PDF uploaded, structure not set up yet; ready = group/question breakdown saved');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('course_id');
            $table->index('exam_year');
            $table->index('semester');
            $table->index('status');
            $table->index('deleted_at');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_papers');
    }
};
