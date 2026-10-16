<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_sub_types', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('inserted_id')->nullable()
                ->comment('Auto-increment id from the main db');
            // Not unique at the DB level — see exam_types.name. Unique per
            // exam term + exam type among non-deleted rows (app layer).
            $table->string('name');
            // No database foreign keys anywhere in this project — these are
            // plain indexed columns, resolved through model relationships.
            $table->unsignedBigInteger('exam_type_id')->nullable()
                ->comment('exam_types.id (the sync API sends type_id = exam_types.inserted_id)');
            $table->unsignedBigInteger('exam_term_id')->nullable()
                ->comment('exam_terms.id (the sync API sends term_id = exam_terms.inserted_id)');
            $table->boolean('deleted_from_main_db')->default(false)
                ->comment('1 = deleted in the main db, 0 = not deleted. From the sync API\'s delete_status, where 0 = deleted and 1 = not deleted');
            $table->boolean('status')->default(true)->comment('1 = active, 0 = inactive');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('inserted_id');
            $table->index('name');
            $table->index('exam_type_id');
            $table->index('exam_term_id');
            $table->index('status');
            $table->index('deleted_at');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sub_types');
    }
};
