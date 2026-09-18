<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by IssueRaisedMailService to every admin (see
 * config('roles.admin_recived_issue_mail')) the moment a teacher raises an
 * issue on an answer sheet — see MyPendingCourseController::raiseIssue().
 * No admin-editable subject/body here (unlike AnswerSheetAssignedMail) —
 * this fires automatically the instant the issue is raised, so there's
 * nothing for anyone to review first.
 */
class IssueRaisedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $adminName,
        public readonly string $teacherName,
        public readonly ?string $teacherEmpCode,
        public readonly bool $isPrintingIssue,
        public readonly string $issueTypeName,
        public readonly string $remarks,
        public readonly string $courseName,
        public readonly string $rollNo,
        public readonly ?string $barcode,
        public readonly string $raisedAt,
        public readonly string $siteTitle,
        public readonly ?string $logoUrl = null,
        public readonly ?int $evaluationTimePerSheet = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Issue Raised — {$this->issueTypeName} ({$this->courseName})");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.issue-raised',
            with: [
                'adminName' => $this->adminName,
                'teacherName' => $this->teacherName,
                'teacherEmpCode' => $this->teacherEmpCode,
                'isPrintingIssue' => $this->isPrintingIssue,
                'issueTypeName' => $this->issueTypeName,
                'remarks' => $this->remarks,
                'courseName' => $this->courseName,
                'rollNo' => $this->rollNo,
                'barcode' => $this->barcode,
                'raisedAt' => $this->raisedAt,
                'siteTitle' => $this->siteTitle,
                'logoUrl' => $this->logoUrl,
                'evaluationTimePerSheet' => $this->evaluationTimePerSheet,
            ],
        );
    }
}
