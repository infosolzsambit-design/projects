<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plain DB-level unique index on `code` doesn't know about soft
     * deletes — it blocks re-using a code that only a *trashed* course still
     * holds. Uniqueness among active courses is enforced at the application
     * layer instead (see StoreCourseRequest/UpdateCourseRequest, scoped to
     * whereNull('deleted_at')); the DB keeps a plain index for lookups.
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropUnique('courses_code_unique');
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['code']);
            $table->unique('code');
        });
    }
};
