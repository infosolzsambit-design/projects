<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Required by the form/API for every new or edited course, but
            // nullable here so courses created before this column existed
            // stay valid until someone edits them. Uniqueness is the
            // combination name + code + type among non-deleted courses,
            // enforced in the app (see Course::hasDuplicate()) — a DB
            // unique index would also block reusing a trashed course's
            // values, same reasoning as courses.code.
            $table->string('type', 50)->nullable()->after('code')
                ->comment('Course type, e.g. T / P / Theory / Practical (free text). Unique together with name + code among non-deleted courses');
            $table->index(['name', 'code', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['name', 'code', 'type']);
            $table->dropColumn('type');
        });
    }
};
