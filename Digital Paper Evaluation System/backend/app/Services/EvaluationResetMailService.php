<?php

namespace App\Services;

use App\Mail\EvaluationResetMail;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the "your evaluation was reset" notice for one
 * ResetEvaluationController::reset() call — dispatched ->afterResponse()
 * there, same reasoning as IssueResolvedMailService (the admin's own
 * success response can't wait on SendGrid). Goes only to the sheet's
 * teacher. Logged to email_logs regardless of outcome, same convention as
 * every other mail in this app.
 */
class EvaluationResetMailService
{
    public function __construct(private readonly MailBrandingService $branding) {}

    public function sendEvaluationResetEmail(
        int $adminId,
        int $teacherId,
        string $courseName,
        ?string $programName,
        ?string $departmentName,
        ?string $barcode,
        string $resetByName,
        string $resetAt,
        ?string $evaluationEndDate = null,
        ?int $evaluationTimePerSheet = null,
    ): void {
        $teacher = User::find($teacherId);

        if (! $teacher || ! $teacher->email) {
            return;
        }

        ['site_title' => $siteTitle, 'logo_url' => $logoUrl] = $this->branding->resolve();

        $mailable = new EvaluationResetMail(
            teacherName: $teacher->name,
            courseName: $courseName,
            programName: $programName,
            departmentName: $departmentName,
            barcode: $barcode,
            resetByName: $resetByName,
            resetAt: $resetAt,
            evaluationEndDate: $evaluationEndDate,
            siteTitle: $siteTitle,
            logoUrl: $logoUrl,
            evaluationTimePerSheet: $evaluationTimePerSheet,
        );

        $log = EmailLog::create([
            'sender_id' => $adminId,
            'receiver_id' => $teacher->id,
            'type' => 'evaluation_reset',
            'subject' => "Evaluation Reset — {$courseName}",
            'body' => $mailable->render(),
            'is_sent' => false,
        ]);

        try {
            Mail::to($teacher->email)->send($mailable);
            $log->update(['is_sent' => true, 'sent_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Evaluation-reset mail failed', [
                'receiver_id' => $teacher->id,
                'error' => $e->getMessage(),
            ]);
            $log->update(['is_sent' => false, 'error_message' => $e->getMessage()]);
        }
    }
}
