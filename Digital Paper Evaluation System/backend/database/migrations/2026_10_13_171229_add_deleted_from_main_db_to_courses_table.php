<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // The main database sends combined types such as
            // "Lecture, Practical, Project, Tutorial, Workshop, Studio" (55 chars).
            $table->string('type', 100)->nullable()
                ->comment('Course type, e.g. T / P / Theory / Practical (free text)')->change();
            $table->boolean('deleted_from_main_db')->default(false)->after('type')
                ->comment('1 = deleted in the main db (delete_status from the sync API), 0 = not deleted');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('deleted_from_main_db');
            $table->string('type', 50)->nullable()
                ->comment('Course type, e.g. T / P / Theory / Practical (free text)')->change();
        });
    }
};
