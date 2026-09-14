<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same fix as courses.code / roles.name / permissions.name /
     * departments.name / users.phone_no: a plain DB-level unique constraint
     * on email doesn't know about deleted_at, so it permanently blocks
     * reusing an email address that only a *trashed* user still holds —
     * exactly the bug this surfaced (Teacher creation 500ing on a 500-level
     * SQL integrity error even though Store/UpdateTeacherRequest's
     * Rule::unique(...)->whereNull('deleted_at') correctly allowed it).
     * Uniqueness among active users is enforced at the application layer
     * instead (see Store/UpdateUserRequest and Store/UpdateTeacherRequest).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->unique('email');
        });
    }
};
