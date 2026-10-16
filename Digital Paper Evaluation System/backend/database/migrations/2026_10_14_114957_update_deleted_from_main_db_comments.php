<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['departments', 'courses', 'programs', 'exam_terms'];

    /**
     * The main database's API sends delete_status as 0 = deleted, 1 = not
     * deleted — the reverse of what these columns' comments first said.
     * deleted_from_main_db keeps its own meaning (1 = deleted); see
     * MainDbSyncService::isDeletedInMainDb().
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('deleted_from_main_db')->default(false)
                    ->comment('1 = deleted in the main db, 0 = not deleted. From the sync API\'s delete_status, where 0 = deleted and 1 = not deleted')
                    ->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('deleted_from_main_db')->default(false)
                    ->comment('1 = deleted in the main db (delete_status from the sync API), 0 = not deleted')
                    ->change();
            });
        }
    }
};
