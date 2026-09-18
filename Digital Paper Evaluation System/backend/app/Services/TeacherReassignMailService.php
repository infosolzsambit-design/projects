<?php

namespace App\Services;

use App\Mail\AnswerSheetAssignedMail;
use App\Models\EmailLog;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the "answer sheets assigned" notice for one TeacherController
 * ::reassignAssignment() call — called via dispatch(...)->afterResponse(),
 * same reasoning as TeacherAssignmentMailService (the admin's own page
 * refresh can't wait on SendGrid). Reuses AnswerSheetAssignedMail itself —
 * from the receiving teacher's point of view a reassignment is the same
 * "you've got answer sheets to evaluate, here's the window" notice, just
 * logged under its own `type` so it's distinguishable later. Unlike the
 * main Assign flow there's no single evaluation window for the whole
 * batch — reassign() never changes a sheet's existing window (see that
 * method's own docblock), so each summary row already carries the actual
 * min/max window of the sheets that teacher just received.
 */
class TeacherReassignMailService
{
    public function __construct(private readonly MailBrandingService $branding) {}

    /**
     * @param  int  $senderId  the admin who triggered the reassignment
     * @param  list<array{teacher_id: int, reassigned_count: int, evaluation_start_date: ?string, evaluation_end_date: ?string, evaluation_time_per_sheet: ?int}>  $summary  AssignTeacherService::reassign()'s own return shape
     */
    public function sendReassignmentEmails(
        int $senderId,
        array $summary,
        string $emailSubject,
        string $emailBody,
        int $mappingId,
    ): void {
        $mapping = QuestionAnswerSheetMapping::with('course')->find($mappingId);
        $courseName = $mapping?->course ? "{$mapping->course->name} ({$mapping->course->code})" : 'the course';
        ['site_title' => $siteTitle, 'logo_url' => $logoUrl] = $this->branding->resolve();

        foreach ($summary as $row) {
            $teacher = User::find($row['teacher_id']);

            if (! $teacher || ! $teacher->email) {
                continue;
            }

            // Matches utils/date.js's formatDateTime() so the emailed dates
            // read the same as everywhere else in the app.
            $formattedStart = $row['evaluation_start_date'] ? Carbon::parse($row['evaluation_start_date'])->format('d-m-Y h:i A') : '—';
            $formattedEnd = $row['evaluation_end_date'] ? Carbon::parse($row['evaluation_end_date'])->format('d-m-Y h:i A') : '—';

            $mailable = new AnswerSheetAssignedMail(
                teacherName: $teacher->name,
                emailSubject: $emailSubject,
                emailBody: $emailBody,
                courseName: $courseName,
                evaluationStartDate: $formattedStart,
                evaluationEndDate: $formattedEnd,
                sheetsAssigned: $row['reassigned_count'],
                siteTitle: $siteTitle,
                logoUrl: $logoUrl,
                evaluationTimePerSheet: $row['evaluation_time_per_sheet'] ?? null,
            );

            $log = EmailLog::create([
                'sender_id' => $senderId,
                'receiver_id' => $teacher->id,
                'type' => 'answer_sheet_reassigned',
                'subject' => $emailSubject,
                'body' => $mailable->render(),
                'is_sent' => false,
            ]);

            try {
                Mail::to($teacher->email)->send($mailable);
                $log->update(['is_sent' => true, 'sent_at' => now()]);
            } catch (Throwable $e) {
                Log::warning('Answer-sheet-reassigned mail failed', [
                    'receiver_id' => $teacher->id,
                    'error' => $e->getMessage(),
                ]);
                $log->update(['is_sent' => false, 'error_message' => $e->getMessage()]);
            }
        }
    }
}
