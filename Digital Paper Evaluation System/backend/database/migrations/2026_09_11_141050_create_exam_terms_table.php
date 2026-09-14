<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_terms', function (Blueprint $table) {
            $table->id();
            // No DB-level unique constraint — a plain unique index doesn't
            // know about deleted_at and would block reusing a name that
            // only a *trashed* exam term still holds (see departments.name
            // for the same fix). Uniqueness among active exam terms is
            // enforced at the application layer instead.
            $table->string('name');
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
            $table->index('status');
            $table->index('deleted_at');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_terms');
    }
};
