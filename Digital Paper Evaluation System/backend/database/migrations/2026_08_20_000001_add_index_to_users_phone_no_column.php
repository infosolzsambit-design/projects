<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * phone_no is about to be used as a login identifier alongside
     * email/username (see AuthController::login). A plain index, not a
     * DB-level unique constraint — same reasoning as courses.code/etc:
     * uniqueness among active users is enforced at the validation layer
     * (Store/UpdateUserRequest, scoped to whereNull('deleted_at')) so a
     * soft-deleted user's phone number stays free to reuse.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('phone_no');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['phone_no']);
        });
    }
};
