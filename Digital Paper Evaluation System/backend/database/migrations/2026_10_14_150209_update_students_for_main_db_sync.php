<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Students for "Sync Student" (the college main database's
     * DataApi/student): the roll number becomes the registration number,
     * semester goes (the main database has none), and batch +
     * inserted_id / deleted_from_main_db are added. Existing roll numbers
     * are kept, just under the new name.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['semester']);
            $table->dropColumn('semester');
            $table->renameColumn('roll_no', 'registration_no');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->renameIndex('students_roll_no_index', 'students_registration_no_index');
            $table->string('registration_no')->comment('Registration number (from the main db\'s regisration_id)')->change();
            $table->bigInteger('inserted_id')->nullable()->after('id')->index()
                ->comment('Auto-increment id from the main db');
            $table->string('batch', 20)->nullable()->after('registration_no')->index()
                ->comment('Admission batch (year), e.g. 2024');
            $table->boolean('deleted_from_main_db')->default(false)->after('program_name')
                ->comment('1 = deleted in the main db, 0 = not deleted. From the sync API\'s delete_status, where 0 = deleted and 1 = not deleted');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['inserted_id']);
            $table->dropIndex(['batch']);
            $table->dropColumn(['inserted_id', 'batch', 'deleted_from_main_db']);
            $table->renameIndex('students_registration_no_index', 'students_roll_no_index');
            $table->renameColumn('registration_no', 'roll_no');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('roll_no')->comment('')->change();
            // Semester can't be recovered — existing rows get 1.
            $table->unsignedInteger('semester')->default(1)->after('roll_no')->index();
        });
    }
};
