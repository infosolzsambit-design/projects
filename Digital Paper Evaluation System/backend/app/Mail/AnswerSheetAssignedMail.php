<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by TeacherAssignmentMailService, one per teacher, right after
 * AssignTeacherController::store() commits the assignment — see that
 * service for why this is dispatched ->afterResponse() rather than queued.
 * Subject/body are the admin's own (possibly edited) text from the
 * "Send Mail and Assign" modal; everything else here (course, dates, sheet
 * count) is generated, not editable, so it can't drift from what was
 * actually assigned.
 */
class AnswerSheetAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $teacherName,
        public readonly string $emailSubject,
        public readonly string $emailBody,
        public readonly string $courseName,
        public readonly string $evaluationStartDate,
        public readonly string $evaluationEndDate,
        public readonly int $sheetsAssigned,
        public readonly string $siteTitle,
        public readonly ?string $logoUrl = null,
        public readonly ?int $evaluationTimePerSheet = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.answer-sheet-assigned',
            with: [
                'teacherName' => $this->teacherName,
                'emailSubject' => $this->emailSubject,
                'bodyText' => $this->emailBody,
                'courseName' => $this->courseName,
                'evaluationStartDate' => $this->evaluationStartDate,
                'evaluationEndDate' => $this->evaluationEndDate,
                'sheetsAssigned' => $this->sheetsAssigned,
                'siteTitle' => $this->siteTitle,
                'logoUrl' => $this->logoUrl,
                'evaluationTimePerSheet' => $this->evaluationTimePerSheet,
            ],
        );
    }
}
