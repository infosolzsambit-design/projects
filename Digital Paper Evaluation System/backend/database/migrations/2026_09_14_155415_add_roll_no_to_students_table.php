<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Added nullable first, not NOT NULL directly — this table can
        // already have real rows (unlike a brand-new table), and a
        // straight NOT NULL add would fail against them. Backfilled below,
        // then tightened to NOT NULL once every existing row has one.
        Schema::table('students', function (Blueprint $table) {
            $table->string('roll_no')->nullable()->after('name');
        });

        DB::table('students')->whereNull('roll_no')->orderBy('id')->get(['id'])->each(function ($student) {
            DB::table('students')->where('id', $student->id)->update(['roll_no' => 'STU-'.$student->id]);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('roll_no')->nullable(false)->change();
            // No DB-level unique constraint (project-wide rule — see
            // emp_code/packet_code elsewhere) — a plain unique index
            // doesn't know about deleted_at and would block reusing a roll
            // number that only a *trashed* student still holds. Uniqueness
            // among non-deleted students is enforced at the application
            // layer (see Store/UpdateStudentRequest).
            $table->index('roll_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['roll_no']);
            $table->dropColumn('roll_no');
        });
    }
};
