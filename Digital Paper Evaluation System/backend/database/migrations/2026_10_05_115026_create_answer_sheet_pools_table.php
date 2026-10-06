<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pool assignment (Assign Teacher → "Pool"): instead of giving each
     * teacher a fixed quantity, a set of pending answer sheets is shared by
     * several teachers — every pool teacher sees every unclaimed sheet, and
     * whoever clicks Start Evaluate first claims it (answer_sheets.teacher_id
     * is set then, after which it's an ordinary assigned sheet).
     *
     *  - answer_sheet_pools          — one row per "Assign as Pool" click.
     *  - answer_sheet_pool_teachers  — the teachers sharing each pool.
     *  - answer_sheets.answer_sheet_pool_id — which pool a sheet was put in.
     *
     * No database foreign keys anywhere in this project — plain indexed
     * columns, resolved through the models' relations.
     */
    public function up(): void
    {
        Schema::create('answer_sheet_pools', function (Blueprint $table) {
            $table->id();
            // The Assign Teacher search the pool was made from.
            $table->string('program_name');
            $table->unsignedBigInteger('course_id')->index()->comment('courses.id');
            $table->unsignedBigInteger('exam_term_id')->index()->comment('exam_terms.id');
            $table->unsignedBigInteger('exam_type_id')->index()->comment('exam_types.id');
            $table->unsignedTinyInteger('semester');
            $table->unsignedSmallInteger('exam_year');
            $table->unsignedBigInteger('department_id')->nullable()->index()
                ->comment('departments.id — the packet department searched on; NULL = all departments');
            // Evaluation window for the pool — also stamped on every sheet.
            $table->dateTime('evaluation_start_date')->nullable();
            $table->dateTime('evaluation_end_date')->nullable();
            $table->unsignedInteger('evaluation_time_per_sheet')->nullable()->comment('Expected minutes per sheet');

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->nullable()->index();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });

        Schema::create('answer_sheet_pool_teachers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('answer_sheet_pool_id')->index()->comment('answer_sheet_pools.id');
            $table->unsignedBigInteger('teacher_id')->index()->comment('users.id of a teacher sharing this pool');
            $table->timestamps();
            $table->unique(['answer_sheet_pool_id', 'teacher_id'], 'pool_teachers_pool_teacher_unique');
        });

        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->unsignedBigInteger('answer_sheet_pool_id')->nullable()->after('teacher_id')->index()
                ->comment('answer_sheet_pools.id — NULL = not pooled. Pooled + teacher_id NULL = waiting in the pool; pooled + teacher_id set = claimed by that teacher');
        });
    }

    public function down(): void
    {
        Schema::table('answer_sheets', function (Blueprint $table) {
            $table->dropIndex(['answer_sheet_pool_id']);
            $table->dropColumn('answer_sheet_pool_id');
        });
        Schema::dropIfExists('answer_sheet_pool_teachers');
        Schema::dropIfExists('answer_sheet_pools');
    }
};
