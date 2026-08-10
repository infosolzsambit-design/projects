<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `name` is in the sortable-fields allow-list on CourseController::index
     * (and searched/filtered on) but had no index — every other
     * filter/sort column on this table already does.
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });
    }
};
