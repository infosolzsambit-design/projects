<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-app notifications shown under the header's bell icon (see
     * UserNotificationService). Named user_notifications, not
     * notifications — Laravel's own database-notification channel owns
     * that table name (User uses the Notifiable trait for password reset),
     * and the app's "Problems" code is already called Notification*.
     */
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')
                ->comment('users.id of the recipient');
            $table->string('type', 50)
                ->comment('answer_sheets_assigned | answer_sheets_reassigned | issue_raised | issue_resolved');
            $table->string('title');
            $table->text('message');
            $table->string('link')->nullable()
                ->comment('Frontend route path opened when the notification is clicked');
            $table->json('data')->nullable()
                ->comment('Extra context, e.g. answer_sheet_id / mapping_id / course');
            $table->dateTime('read_at')->nullable()
                ->comment('null = unread');
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
