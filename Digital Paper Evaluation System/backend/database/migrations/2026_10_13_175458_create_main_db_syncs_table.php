<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per successful "Sync …" from the college's main database
     * (MainDbSyncService) — the latest row per entity is the "Last sync"
     * shown on that master's list page.
     */
    public function up(): void
    {
        Schema::create('main_db_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 50)->index()->comment('Which master was synced: departments / courses / …');
            $table->json('summary')->nullable()->comment('Counts: fetched, created, updated, restored, unchanged, deleted, skipped');
            $table->unsignedBigInteger('created_by')->nullable()->index()->comment('users.id — who ran the sync');
            $table->dateTime('created_at')->nullable()->comment('When the sync ran');
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('main_db_syncs');
    }
};
