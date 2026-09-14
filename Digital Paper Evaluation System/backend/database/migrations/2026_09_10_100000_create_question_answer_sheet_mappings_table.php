<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One exam-center upload "packet" — the question paper it belongs to,
     * the course/semester/program it's for, and the packet code the center
     * itself uses — created by AnswerSheetUploadView.vue's Submit action
     * once its own Check step has validated the CSV against the PDF bunch
     * client-side (uniqueness, row-count-vs-PDF-count, every PDF having a
     * readable QR, every barcode matching one — see
     * AnswerSheetUploadView.vue). Each individual student's row from that
     * CSV becomes one answer_sheets row below (see that migration),
     * belonging to this packet.
     */
    public function up(): void
    {
        Schema::create('question_answer_sheet_mappings', function (Blueprint $table) {
            $table->id();
            // No DB foreign keys anywhere in this project — plain indexed
            // columns, resolved through the questionPaper()/course()
            // relationships.
            $table->unsignedBigInteger('question_paper_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedTinyInteger('semester');
            // Matches Student's own program_name convention (see
            // StudentFormView.vue) — programs and courses are many-to-many,
            // so there's no single program_id a course cleanly resolves to;
            // the exam center just picks the program by name.
            $table->string('program_name');
            $table->string('packet_code');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('question_paper_id');
            $table->index('course_id');
            $table->index('semester');
            $table->index('program_name');
            $table->index('packet_code');
            $table->index('deleted_at');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_answer_sheet_mappings');
    }
};
