<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Odd"/"Even" semester session — a separate, explicitly-picked field
     * rather than derived from `semester`'s own parity, since a paper's
     * session type doesn't always follow straightforwardly from its
     * semester number (e.g. a supplementary/back exam). Nullable: existing
     * papers created before this field existed have no value for it yet
     * (required going forward — see StoreQuestionPaperRequest /
     * UpdateQuestionPaperRequest — but there's nothing to backfill old rows
     * with).
     */
    public function up(): void
    {
        Schema::table('question_papers', function (Blueprint $table) {
            $table->string('semester_type', 10)->nullable()->after('semester')
                ->comment('odd | even — the exam session this paper belongs to');
            $table->index('semester_type');
        });
    }

    public function down(): void
    {
        Schema::table('question_papers', function (Blueprint $table) {
            $table->dropIndex(['semester_type']);
            $table->dropColumn('semester_type');
        });
    }
};
