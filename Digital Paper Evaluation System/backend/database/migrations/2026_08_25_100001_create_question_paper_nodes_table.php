<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One node in a question paper's structure tree — self-referencing so
     * the same shape represents every level a real paper can have: a
     * "Group A" section, a plain question inside it, an OR-alternative
     * branch, or a sub-part's own OR-alternative nested inside that. A
     * "group" is just a node with parent_id null; there's no separate
     * groups/questions split any more, because papers don't reliably fit
     * one (see designed_files/question_paper.pdf: some questions are one
     * plain leaf, some branch into 2-3 worded alternatives, and some of
     * those alternatives branch again into lettered sub-parts).
     *
     * `mode` says what this node means for its children:
     *  - leaf   — this node has no children, it's an actual answerable
     *             question worth `marks`.
     *  - all    — every child listed below must be attempted.
     *  - choose — exactly `choose_count` of the children below must be
     *             attempted (this is also how a plain "OR" between two
     *             children is expressed: choose_count = 1).
     * A "Groups C, D & E combined, answer any 12 of 15" scenario is just
     * this same pattern one level higher — a choose node whose children
     * are themselves group-like `all` nodes — so no separate cross-group
     * "pool" concept is needed.
     *
     * Same no-independent-lifecycle reasoning as the two tables this
     * replaces: a paper's whole tree is always deleted and recreated
     * wholesale together on structure-save (see
     * QuestionPaperController::update()), never listed/edited node by
     * node, so no soft deletes/audit trail here unlike every top-level
     * model in this app.
     */
    public function up(): void
    {
        Schema::create('question_paper_nodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('question_paper_id');
            $table->unsignedBigInteger('parent_id')->nullable(); // null = top-level "group"; no FK per project convention, resolved via Eloquent
            $table->string('label'); // this node's own local numbering, e.g. "Group A", "2", "i", "a" — not the full path
            $table->text('instruction')->nullable(); // e.g. "Answer all questions. Each question carries 2 marks." — mainly used on top-level nodes
            $table->string('mode')->default('leaf')->comment('leaf = answerable question (has marks, no children); all = every child below is compulsory; choose = attempt choose_count of the children below');
            $table->unsignedInteger('choose_count')->nullable();
            $table->unsignedInteger('marks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('question_paper_id');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_paper_nodes');
    }
};
