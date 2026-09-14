<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bloom's Taxonomy level (K1, K2, ...) and Course Outcome (CO1, CO2,
     * ...) — the two extra columns many question papers print alongside
     * each question (see designed_files/question_paper.pdf's right-hand
     * columns). Only meaningful on leaf nodes, same as `marks`; both
     * nullable since plenty of papers don't print either at all — see
     * questionPaperParser.js, which only ever fills these in when it can
     * actually read them off the PDF, never guesses a value.
     */
    public function up(): void
    {
        Schema::table('question_paper_nodes', function (Blueprint $table) {
            $table->string('bloom_level', 10)->nullable()->after('marks');
            $table->string('co', 10)->nullable()->after('bloom_level');
        });
    }

    public function down(): void
    {
        Schema::table('question_paper_nodes', function (Blueprint $table) {
            $table->dropColumn(['bloom_level', 'co']);
        });
    }
};
