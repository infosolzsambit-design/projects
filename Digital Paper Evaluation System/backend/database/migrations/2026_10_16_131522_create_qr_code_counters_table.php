<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The last QR number handed out (see QrCodeNumberService) — one row per
     * counter, locked (SELECT … FOR UPDATE) while numbers are reserved so
     * two people generating at the same time never get overlapping numbers.
     */
    public function up(): void
    {
        Schema::create('qr_code_counters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique()->comment('Which counter, e.g. answer_sheets');
            $table->unsignedBigInteger('last_value')->comment('Last QR number handed out; the next is last_value + 1');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_code_counters');
    }
};
