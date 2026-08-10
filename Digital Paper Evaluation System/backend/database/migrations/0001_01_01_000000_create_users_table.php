<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('phone_no', 20)->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            // No database foreign keys anywhere in this project — these are
            // plain indexed columns, resolved through model relationships.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('is_active');
            $table->index('deleted_at');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
