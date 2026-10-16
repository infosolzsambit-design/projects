<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_types', function (Blueprint $table) {
            $table->boolean('deleted_from_main_db')->default(false)->after('name')
                ->comment('1 = deleted in the main db, 0 = not deleted. From the sync API\'s delete_status, where 0 = deleted and 1 = not deleted');
        });
    }

    public function down(): void
    {
        Schema::table('exam_types', function (Blueprint $table) {
            $table->dropColumn('deleted_from_main_db');
        });
    }
};
