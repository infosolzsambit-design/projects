<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Resends an email_logs row that previously failed (is_sent = false) — the
 * "Resend" row action on EmailLogsView.vue. Unlike the type-specific mail
 * services (TeacherAssignmentMailService and friends), this never
 * reconstructs the original branded Mailable/view: email_logs.body is
 * already the exact rendered HTML that was (or would have been) sent, so
 * resending it as raw HTML is both simpler and guaranteed identical to the
 * original attempt when the admin doesn't change anything in
 * ResendEmailModal.vue. If they do edit the subject/body first (see
 * EmailLogController::resend()), that edited HTML is what goes out instead
 * — same mechanism either way, nothing here needs to know or care whether
 * it was edited.
 *
 * Always creates a NEW email_logs row for the attempt rather than
 * overwriting the failed one, matching that table's own "one row per
 * actual email attempt" docblock — so a run of failed retries stays fully
 * visible instead of erasing its own history.
 */
class EmailResendService
{
    public function resend(EmailLog $original, int $senderId, string $subject, string $body): EmailLog
    {
        $log = EmailLog::create([
            'sender_id' => $senderId,
            'receiver_id' => $original->receiver_id,
            'type' => $original->type,
            'subject' => $subject,
            'body' => $body,
            'is_sent' => false,
        ]);

        try {
            Mail::html($body, function ($message) use ($original, $subject) {
                $message->to($original->receiver->email)->subject($subject);
            });
            $log->update(['is_sent' => true, 'sent_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Email resend failed', [
                'email_log_id' => $log->id,
                'receiver_id' => $original->receiver_id,
                'error' => $e->getMessage(),
            ]);
            $log->update(['is_sent' => false, 'error_message' => $e->getMessage()]);
        }

        return $log->fresh();
    }
}
