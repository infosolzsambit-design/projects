<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the Shared Pools list's filters and sort columns
     * (AnswerSheetPoolController::index()).
     */
    public function up(): void
    {
        Schema::table('answer_sheet_pools', function (Blueprint $table) {
            $table->index('exam_year');
            $table->index('semester');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('answer_sheet_pools', function (Blueprint $table) {
            $table->dropIndex(['exam_year']);
            $table->dropIndex(['semester']);
            $table->dropIndex(['created_at']);
        });
    }
};
