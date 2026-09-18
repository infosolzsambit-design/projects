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
        Schema::table('question_answer_sheet_mappings', function (Blueprint $table) {
            // Which exam type this packet belongs to (e.g. Regular/Backlog/
            // Supplementary — see exam_types' own migration), a sibling
            // reference alongside exam_term_id rather than a replacement for
            // it — exam term and exam type are separate concepts. Nullable
            // and no DB foreign key, same as exam_term_id's own column
            // (2026_09_11_145648_replace_semester_type_with_exam_term_id.php)
            // — this table can already hold packets uploaded before this
            // field existed.
            $table->unsignedBigInteger('exam_type_id')->nullable()->after('exam_term_id');
            $table->index('exam_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('question_answer_sheet_mappings', function (Blueprint $table) {
            $table->dropIndex(['exam_type_id']);
            $table->dropColumn('exam_type_id');
        });
    }
};
