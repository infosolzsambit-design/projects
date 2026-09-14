<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Odd/Even was a free-text stand-in for a real "which exam term is this
     * for" reference — now that exam_terms exists as its own master (see
     * that table's own migration), both question_papers and
     * question_answer_sheet_mappings switch to a real exam_term_id instead.
     * Safe to drop semester_type outright rather than keep both columns —
     * it was only added earlier this same session and nothing has real
     * data in it yet. No DB foreign key, same as every other reference in
     * this project — resolved through the examTerm() relationship.
     */
    public function up(): void
    {
        Schema::table('question_papers', function (Blueprint $table) {
            $table->dropIndex(['semester_type']);
            $table->dropColumn('semester_type');
            $table->unsignedBigInteger('exam_term_id')->nullable()->after('semester');
            $table->index('exam_term_id');
        });

        Schema::table('question_answer_sheet_mappings', function (Blueprint $table) {
            $table->dropIndex(['semester_type']);
            $table->dropColumn('semester_type');
            $table->unsignedBigInteger('exam_term_id')->nullable()->after('semester');
            $table->index('exam_term_id');
        });
    }

    public function down(): void
    {
        Schema::table('question_papers', function (Blueprint $table) {
            $table->dropIndex(['exam_term_id']);
            $table->dropColumn('exam_term_id');
            $table->string('semester_type', 10)->nullable()->after('semester');
            $table->index('semester_type');
        });

        Schema::table('question_answer_sheet_mappings', function (Blueprint $table) {
            $table->dropIndex(['exam_term_id']);
            $table->dropColumn('exam_term_id');
            $table->string('semester_type', 10)->nullable()->after('semester');
            $table->index('semester_type');
        });
    }
};
