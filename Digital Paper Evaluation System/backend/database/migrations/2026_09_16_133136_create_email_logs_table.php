<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per actual email attempt — starting with AssignTeacherController
     * ::store()'s own "an answer sheet was just assigned to you" notice (see
     * TeacherAssignmentMailService), but deliberately generic (a `type` tag,
     * not a dedicated table per email kind) so it doubles as this app's
     * future in-app notification log too — is_read/read_at exist for
     * exactly that, not just for email bookkeeping.
     */
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();

            // No database foreign keys anywhere in this project — plain
            // indexed columns, resolved through model relationships.
            $table->unsignedBigInteger('sender_id')->nullable()
                ->comment('User who triggered this email (e.g. the admin who assigned the work); null for a system-generated one');
            $table->unsignedBigInteger('receiver_id')
                ->comment('User this email was addressed to');

            $table->string('type')->nullable()
                ->comment('Category tag for grouping/filtering, e.g. answer_sheet_assigned — not a foreign key, just a label');
            $table->string('subject');
            $table->longText('body')->comment('The exact rendered HTML body that was (or would have been) sent');

            $table->boolean('is_sent')->default(false)
                ->comment('1 = actually handed off to the mail provider successfully, 0 = still pending or failed — see error_message');
            $table->text('error_message')->nullable()
                ->comment('The mailer exception message when is_sent = 0; null on success or before an attempt has run');
            $table->dateTime('sent_at')->nullable();

            $table->boolean('is_read')->default(false)
                ->comment('1 = the receiver has viewed this as an in-app notification, 0 = unread — independent of is_sent');
            $table->dateTime('read_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('sender_id');
            $table->index('receiver_id');
            $table->index('type');
            $table->index('is_sent');
            $table->index('is_read');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
