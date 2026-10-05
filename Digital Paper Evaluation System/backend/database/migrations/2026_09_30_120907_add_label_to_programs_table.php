<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            // Required by the form/API for every new or edited program, but
            // nullable here so programs created before this column existed
            // stay valid until someone edits them. Uniqueness is the
            // combination name + code + label among non-deleted programs,
            // enforced in the app (see Program::hasDuplicate()) — a DB
            // unique index would also block reusing a trashed program's
            // values, same reasoning as programs.code.
            $table->string('label', 20)->nullable()->after('name')
                ->comment('Program level, e.g. UG / PG. Unique together with name + code among non-deleted programs');
            $table->index(['name', 'code', 'label']);
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropIndex(['name', 'code', 'label']);
            $table->dropColumn('label');
        });
    }
};
