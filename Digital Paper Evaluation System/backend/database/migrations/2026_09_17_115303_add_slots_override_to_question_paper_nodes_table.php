<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A "choose" node's own "of N" total is normally computed live from its
     * children (see ValidatesQuestionPaperNodes::slotCount() and its
     * frontend mirror, utils/questionPaperNode.js's slotCount()) — right
     * for the common case, but a scanned paper's auto-filled structure can
     * occasionally misjudge it (e.g. reading a whole alternative *set* as
     * several individually-poolable questions instead of one). Rather than
     * chase every such misreading in the parser, this lets a reviewer just
     * type the right total directly on that one "choose" node — null means
     * "still auto-computed", exactly as before this column existed.
     */
    public function up(): void
    {
        Schema::table('question_paper_nodes', function (Blueprint $table) {
            $table->unsignedInteger('slots_override')->nullable()->after('choose_count')
                ->comment('Manual override for this choose node\'s own "of N" total — null means keep auto-computing it from the children below, same as before this column existed');
        });
    }

    public function down(): void
    {
        Schema::table('question_paper_nodes', function (Blueprint $table) {
            $table->dropColumn('slots_override');
        });
    }
};
