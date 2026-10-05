<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by EvaluationResetMailService to the sheet's teacher the moment an
 * admin resets its evaluation — see ResetEvaluationController::reset(). No
 * admin-editable subject/body (same as IssueResolvedMail) — this fires
 * automatically the instant Reset is clicked, nothing to review first.
 */
class EvaluationResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $teacherName,
        public readonly string $courseName,
        public readonly ?string $programName,
        public readonly ?string $departmentName,
        public readonly ?string $barcode,
        public readonly string $resetByName,
        public readonly string $resetAt,
        public readonly ?string $evaluationEndDate,
        public readonly string $siteTitle,
        public readonly ?string $logoUrl = null,
        public readonly ?int $evaluationTimePerSheet = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Evaluation Reset — {$this->courseName}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.evaluation-reset',
            with: [
                'teacherName' => $this->teacherName,
                'courseName' => $this->courseName,
                'programName' => $this->programName,
                'departmentName' => $this->departmentName,
                'barcode' => $this->barcode,
                'resetByName' => $this->resetByName,
                'resetAt' => $this->resetAt,
                'evaluationEndDate' => $this->evaluationEndDate,
                'siteTitle' => $this->siteTitle,
                'logoUrl' => $this->logoUrl,
                'evaluationTimePerSheet' => $this->evaluationTimePerSheet,
            ],
        );
    }
}
