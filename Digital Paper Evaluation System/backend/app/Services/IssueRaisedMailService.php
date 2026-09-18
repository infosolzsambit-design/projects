<?php

namespace App\Services;

use App\Mail\IssueRaisedMail;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the "an issue was raised" notice for one MyPendingCourseController
 * ::raiseIssue() call — dispatched ->afterResponse() there, same reasoning
 * as TeacherAssignmentMailService (the teacher's own success response can't
 * wait on SendGrid). Recipients are resolved fresh on every raise — every
 * user currently holding any role in config('roles.admin_recived_issue_mail'),
 * not a fixed admin list — and mailed one at a time, each attempt logged to
 * email_logs regardless of outcome. Unlike the assignment email, there's no
 * admin-editable subject/body: this fires automatically the moment the
 * issue is raised, nothing to review first.
 */
class IssueRaisedMailService
{
    public function __construct(private readonly MailBrandingService $branding) {}

    public function sendIssueRaisedEmails(
        int $teacherId,
        string $teacherName,
        ?string $teacherEmpCode,
        bool $isPrintingIssue,
        string $issueTypeName,
        string $remarks,
        string $courseName,
        string $rollNo,
        ?string $barcode,
        string $raisedAt,
        ?int $evaluationTimePerSheet = null,
    ): void {
        $adminRoleIds = (array) config('roles.admin_recived_issue_mail');

        $admins = User::whereHas('roles', fn ($q) => $q->whereIn('roles.id', $adminRoleIds))
            ->whereNotNull('email')
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        ['site_title' => $siteTitle, 'logo_url' => $logoUrl] = $this->branding->resolve();

        foreach ($admins as $admin) {
            $mailable = new IssueRaisedMail(
                adminName: $admin->name,
                teacherName: $teacherName,
                teacherEmpCode: $teacherEmpCode,
                isPrintingIssue: $isPrintingIssue,
                issueTypeName: $issueTypeName,
                remarks: $remarks,
                courseName: $courseName,
                rollNo: $rollNo,
                barcode: $barcode,
                raisedAt: $raisedAt,
                siteTitle: $siteTitle,
                logoUrl: $logoUrl,
                evaluationTimePerSheet: $evaluationTimePerSheet,
            );

            $log = EmailLog::create([
                'sender_id' => $teacherId,
                'receiver_id' => $admin->id,
                'type' => 'issue_raised',
                'subject' => "Issue Raised — {$issueTypeName} ({$courseName})",
                'body' => $mailable->render(),
                'is_sent' => false,
            ]);

            try {
                Mail::to($admin->email)->send($mailable);
                $log->update(['is_sent' => true, 'sent_at' => now()]);
            } catch (Throwable $e) {
                Log::warning('Issue-raised mail failed', [
                    'receiver_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
                $log->update(['is_sent' => false, 'error_message' => $e->getMessage()]);
            }
        }
    }
}
