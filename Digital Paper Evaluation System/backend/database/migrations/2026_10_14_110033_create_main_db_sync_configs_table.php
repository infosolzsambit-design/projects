<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The request each "Sync …" makes to the college's main database API —
     * one row per master (entity), edited (auto-saved) in the Sync modal.
     * The base URL and security code stay in General Settings.
     */
    public function up(): void
    {
        Schema::create('main_db_sync_configs', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 50)->unique()->comment('Which master: departments / courses / …');
            $table->string('method', 10)->default('POST')->comment('HTTP method: GET or POST');
            $table->string('path')->comment('Endpoint after the base URL, e.g. DataApi/course');
            $table->json('params')->nullable()->comment('Query params like Postman: [{key, value, enabled}] — only enabled ones are sent');
            $table->unsignedBigInteger('updated_by')->nullable()->index()->comment('users.id — who last changed it');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('main_db_sync_configs');
    }
};
