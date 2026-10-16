<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same as courses.inserted_id: these masters can come from a college's
     * own system through its API — this keeps that system's id for each row.
     */
    private const TABLES = ['programs', 'departments', 'exam_types', 'exam_terms'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->bigInteger('inserted_id')->nullable()->after('id')
                    ->comment('Auto-increment id from the main db');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('inserted_id');
            });
        }
    }
};
