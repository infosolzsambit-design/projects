<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_terms', function (Blueprint $table) {
            $table->boolean('deleted_from_main_db')->default(false)->after('name')
                ->comment('1 = deleted in the main db (delete_status from the sync API), 0 = not deleted');
        });
    }

    public function down(): void
    {
        Schema::table('exam_terms', function (Blueprint $table) {
            $table->dropColumn('deleted_from_main_db');
        });
    }
};
