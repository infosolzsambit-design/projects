<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A bearer token that's visible in localStorage is, by definition,
     * usable by anyone who copies the exact string — no backend logic can
     * change that. What these columns enable instead: pinning each token to
     * the IP and browser it was issued to (see PinTokenToClient middleware),
     * so a copied token fails the moment it's replayed from a different
     * network or device, without relying on aggressive expiration windows
     * that log out active legitimate users.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('abilities');
            $table->string('user_agent', 255)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
