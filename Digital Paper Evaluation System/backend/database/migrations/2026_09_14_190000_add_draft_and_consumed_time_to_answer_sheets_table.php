<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * EvaluatePaperView.vue autosaves everything as a draft while a
     * teacher is working — marks-in-progress, drawn annotations, and how
     * long they've spent so far — so nothing is lost to a closed tab, a
     * crashed browser, or a machine simply turning off mid-evaluation.
     * None of this ever counts as the sheet being evaluated — only
     * `marks` (unchanged, still what whereNull('marks') checks
     * everywhere) does that, set once via "Complete", not by autosave.
     *
     * - consumed_time: whole seconds actually spent so far — finer-grained
     *   than evaluation_time_per_sheet's own minutes (that's an admin-set
     *   budget, not a live-ticking value), converted to/from minutes only
     *   where the two are compared (the marking screen's Total/Remaining).
     * - draft_marks / draft_marks_breakdown: the running total and the
     *   per-question values behind it (JSON, keyed by question-paper node
     *   id) — restored into the marks sidebar on reopening a
     *   not-yet-completed sheet.
     * - draft_annotations: the pencil/correct/wrong/blank marks drawn on
     *   the PDF so far (JSON, keyed by page number) — was session-only
     *   before this; now survives a reload the same way the marks do.
     */
    public function up(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->unsignedInteger('consumed_time')->nullable()->after('evaluation_time_per_sheet');
            $table->decimal('draft_marks', 6, 2)->nullable()->after('marks');
            $table->longText('draft_marks_breakdown')->nullable()->after('draft_marks');
            $table->longText('draft_annotations')->nullable()->after('draft_marks_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dropColumn(['consumed_time', 'draft_marks', 'draft_marks_breakdown', 'draft_annotations']);
        });
    }
};
