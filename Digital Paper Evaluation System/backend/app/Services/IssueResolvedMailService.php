<?php

namespace App\Services;

use App\Mail\IssueResolvedMail;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the "your issue was resolved" notice for one NotificationController
 * ::resolveTimingIssue() / resolvePrintingIssue() call — dispatched
 * ->afterResponse() there, same reasoning as IssueRaisedMailService (the
 * admin's own success response can't wait on SendGrid). Goes only to the
 * teacher who originally raised the issue (issue_raised_by) — no admin-
 * editable subject/body, this fires automatically the moment Resolve is
 * clicked. Logged to email_logs regardless of outcome, same convention as
 * every other mail in this app.
 */
class IssueResolvedMailService
{
    public function __construct(private readonly MailBrandingService $branding) {}

    public function sendIssueResolvedEmail(
        int $adminId,
        int $teacherId,
        bool $isPrintingIssue,
        string $issueTypeName,
        string $courseName,
        ?string $barcode,
        ?string $adminRemarks,
        string $resolvedByName,
        string $resolvedAt,
        ?string $newEvaluationStartDate = null,
        ?string $newEvaluationEndDate = null,
        ?int $evaluationTimePerSheet = null,
    ): void {
        $teacher = User::find($teacherId);

        if (! $teacher || ! $teacher->email) {
            return;
        }

        ['site_title' => $siteTitle, 'logo_url' => $logoUrl] = $this->branding->resolve();

        $mailable = new IssueResolvedMail(
            teacherName: $teacher->name,
            isPrintingIssue: $isPrintingIssue,
            issueTypeName: $issueTypeName,
            courseName: $courseName,
            barcode: $barcode,
            adminRemarks: $adminRemarks,
            resolvedByName: $resolvedByName,
            resolvedAt: $resolvedAt,
            newEvaluationStartDate: $newEvaluationStartDate,
            newEvaluationEndDate: $newEvaluationEndDate,
            siteTitle: $siteTitle,
            logoUrl: $logoUrl,
            evaluationTimePerSheet: $evaluationTimePerSheet,
        );

        $log = EmailLog::create([
            'sender_id' => $adminId,
            'receiver_id' => $teacher->id,
            'type' => 'issue_resolved',
            'subject' => "Issue Resolved — {$issueTypeName} ({$courseName})",
            'body' => $mailable->render(),
            'is_sent' => false,
        ]);

        try {
            Mail::to($teacher->email)->send($mailable);
            $log->update(['is_sent' => true, 'sent_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Issue-resolved mail failed', [
                'receiver_id' => $teacher->id,
                'error' => $e->getMessage(),
            ]);
            $log->update(['is_sent' => false, 'error_message' => $e->getMessage()]);
        }
    }
}
