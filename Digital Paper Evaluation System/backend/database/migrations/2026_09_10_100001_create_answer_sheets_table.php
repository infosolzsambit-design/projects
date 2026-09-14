<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per student in an uploaded CSV mapping — columns mirror
     * CSV_COLUMNS in AnswerSheetUploadView.vue exactly (the exam center's
     * own export shape — see that file's own docblock for the raw ->
     * proper-case column naming). Created together as a whole batch
     * alongside its one question_answer_sheet_mappings row, but — unlike
     * question_paper_nodes — carries the same full audit trail as every
     * other top-level model in this app (created_by/updated_by/
     * deleted_by, soft deletes): each row represents a real physical
     * answer sheet, worth tracking/recovering on its own, not just
     * disposable structure.
     */
    public function up(): void
    {
        Schema::create('answer_sheets', function (Blueprint $table) {
            $table->id();
            // No DB foreign key per project convention — resolved via the
            // mapping() relationship.
            $table->unsignedBigInteger('question_answer_sheet_mapping_id');

            $table->string('branch_code')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('subject_code')->nullable();
            $table->string('subject_name')->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            // The code read off each PDF's own QR cover page — validated
            // unique within the batch and matched 1:1 against the uploaded
            // PDFs' QR codes before this row is ever created (see
            // AnswerSheetUploadView.vue's Check step).
            $table->string('subject_barcode');
            $table->string('fi_code')->nullable();
            $table->string('roll_no');
            $table->string('name')->nullable();
            $table->string('registration_no')->nullable();
            $table->boolean('absent')->default(false)->comment('1 = marked absent in the CSV, 0 = present');
            // Free-form — the CSV's own format for this isn't a fixed
            // datetime shape, so this isn't cast/parsed, just stored as-is.
            $table->string('locked_time')->nullable();
            $table->string('packet_no')->nullable();
            // Distinct from subject_barcode above — a second barcode value
            // the CSV carries per its own "Barcode" column.
            $table->string('barcode')->nullable();
            $table->decimal('marks', 6, 2)->nullable();
            $table->string('top_sheet')->nullable();
            // The matched PDF (resolved by subject_barcode == the PDF's
            // decoded QR value — see the controller), stored under a freshly
            // generated unique name (never the original uploaded filename —
            // exam centers reuse plain names like "scan1.pdf" across
            // batches, and Laravel's own store() already guarantees
            // uniqueness the same way question_papers.pdf_path does).
            // pdf_name is just that generated filename on its own (handy for
            // display); pdf_path is the full "/storage/..." URL. Both
            // nullable because a row could in principle be stored without
            // ever having had a matching PDF, though the Check step's own
            // validation means that should never happen through the normal
            // upload flow.
            $table->string('pdf_name')->nullable();
            $table->string('pdf_path')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('question_answer_sheet_mapping_id');
            $table->index('subject_barcode');
            $table->index('roll_no');
            $table->index('deleted_at');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_sheets');
    }
};
