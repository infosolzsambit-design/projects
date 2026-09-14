<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A teacher can now be created without a password (see
     * TeacherController::store()) — they'd use "Forgot password?" to set
     * one on first login. Hash::check() already returns false (not an
     * error) against a null hash, so a null password just behaves like
     * "invalid credentials" on login — no AuthController change needed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
