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
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->create($table, function (Blueprint $table) {

            $morphPrefix = config('audit.user.morph_prefix', 'user');

            $table->bigIncrements('id');
            $table->string($morphPrefix.'_type')->nullable();
            $table->unsignedBigInteger($morphPrefix.'_id')->nullable();
            $table->string('event');
            // Nullable: manual events like a failed login (see
            // AuditLogService) legitimately have no specific auditable model.
            $table->nullableMorphs('auditable');
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();

            // Extra context beyond the package defaults, for the "advanced
            // audit context" requirements (module/action/description, plus
            // request tracing).
            $table->string('module')->nullable();
            $table->text('description')->nullable();
            $table->string('http_method', 10)->nullable();
            $table->string('route')->nullable();
            $table->string('request_id')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index([$morphPrefix.'_id', $morphPrefix.'_type']);
            $table->index('event');
            $table->index('module');
            $table->index('request_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->drop($table);
    }
};
