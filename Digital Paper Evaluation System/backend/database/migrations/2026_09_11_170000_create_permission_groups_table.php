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
        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            // No DB-level unique constraint — same reasoning as
            // departments.name: a plain unique index doesn't know about
            // deleted_at and would block reusing a name that only a
            // *trashed* group still holds. Uniqueness among active groups
            // is enforced at the application layer instead.
            $table->string('name');
            $table->smallInteger('sort_order')->unsigned()->default(0)
                ->comment('Display order among groups — lower value shows first');
            $table->boolean('status')->default(true)->comment('1 = active, 0 = inactive');

            // No database foreign keys anywhere in this project — these are
            // plain indexed columns, resolved through model relationships.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

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
        Schema::dropIfExists('permission_groups');
    }
};
