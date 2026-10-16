<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * teacher_details.department_id / department → teacher_department_mappings
     * (a teacher can now be in several departments), plus the "Sync Teacher"
     * columns. Existing departments are copied over first, so no teacher
     * loses theirs.
     */
    public function up(): void
    {
        $now = now();
        DB::table('teacher_details')->whereNotNull('department_id')->orderBy('id')
            ->get(['user_id', 'department_id'])
            ->each(fn ($row) => DB::table('teacher_department_mappings')->insertOrIgnore([
                'user_id' => $row->user_id,
                'department_id' => $row->department_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        Schema::table('teacher_details', function (Blueprint $table) {
            $table->dropIndex(['department']);
            $table->dropIndex(['department_id']);
            $table->dropColumn(['department', 'department_id']);
        });

        Schema::table('teacher_details', function (Blueprint $table) {
            $table->bigInteger('inserted_id')->nullable()->after('id')->index()
                ->comment('Auto-increment id from the main db');
            $table->boolean('deleted_from_main_db')->default(false)->after('designation')
                ->comment('1 = deleted in the main db, 0 = not deleted. From the sync API\'s delete_status, where 0 = deleted and 1 = not deleted');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_details', function (Blueprint $table) {
            $table->dropIndex(['inserted_id']);
            $table->dropColumn(['inserted_id', 'deleted_from_main_db']);
            $table->string('department')->nullable()->after('emp_code')->index();
            $table->unsignedBigInteger('department_id')->nullable()->after('department')->index();
        });

        // Back to one department each — the first one mapped.
        DB::table('teacher_department_mappings')
            ->join('departments', 'departments.id', '=', 'teacher_department_mappings.department_id')
            ->orderBy('teacher_department_mappings.id')
            ->get(['teacher_department_mappings.user_id', 'departments.id', 'departments.name'])
            ->unique('user_id')
            ->each(fn ($row) => DB::table('teacher_details')->where('user_id', $row->user_id)
                ->update(['department_id' => $row->id, 'department' => $row->name]));
    }
};
