<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by IssueResolvedMailService to the teacher who originally raised the
 * issue, the moment an admin resolves it — see NotificationController
 * ::resolveTimingIssue() / resolvePrintingIssue(). No admin-editable
 * subject/body (same as IssueRaisedMail) — this fires automatically the
 * instant Resolve is clicked, nothing to review first.
 */
class IssueResolvedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $teacherName,
        public readonly bool $isPrintingIssue,
        public readonly string $issueTypeName,
        public readonly string $courseName,
        public readonly ?string $barcode,
        public readonly ?string $adminRemarks,
        public readonly string $resolvedByName,
        public readonly string $resolvedAt,
        public readonly ?string $newEvaluationStartDate,
        public readonly ?string $newEvaluationEndDate,
        public readonly string $siteTitle,
        public readonly ?string $logoUrl = null,
        public readonly ?int $evaluationTimePerSheet = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Issue Resolved — {$this->issueTypeName} ({$this->courseName})");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.issue-resolved',
            with: [
                'teacherName' => $this->teacherName,
                'isPrintingIssue' => $this->isPrintingIssue,
                'issueTypeName' => $this->issueTypeName,
                'courseName' => $this->courseName,
                'barcode' => $this->barcode,
                'adminRemarks' => $this->adminRemarks,
                'resolvedByName' => $this->resolvedByName,
                'resolvedAt' => $this->resolvedAt,
                'newEvaluationStartDate' => $this->newEvaluationStartDate,
                'newEvaluationEndDate' => $this->newEvaluationEndDate,
                'siteTitle' => $this->siteTitle,
                'logoUrl' => $this->logoUrl,
                'evaluationTimePerSheet' => $this->evaluationTimePerSheet,
            ],
        );
    }
}
