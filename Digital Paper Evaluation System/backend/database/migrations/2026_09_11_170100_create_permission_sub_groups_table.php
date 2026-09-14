<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('permission_sub_groups', function (Blueprint $table) {
            $table->id();
            // No database foreign keys anywhere in this project — plain
            // indexed column, resolved through the group() relationship.
            $table->unsignedBigInteger('permission_group_id');
            // No DB-level unique constraint — same reasoning as
            // permission_groups.name (see that migration).
            $table->string('name');
            $table->smallInteger('sort_order')->unsigned()->default(0)
                ->comment('Display order among sub-groups — lower value shows first');
            $table->boolean('status')->default(true)->comment('1 = active, 0 = inactive');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('permission_group_id');
            $table->index('name');
            $table->index('sort_order');
            $table->index('status');
            $table->index('deleted_at');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_sub_groups');
    }
};
