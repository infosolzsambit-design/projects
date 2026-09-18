<?php

namespace App\Services;

use App\Mail\AnswerSheetAssignedMail;
use App\Models\Course;
use App\Models\EmailLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the "answer sheets assigned" notice for one AssignTeacherController
 * ::store() call — called via dispatch(...)->afterResponse() so the admin's
 * page refresh isn't held up waiting on SendGrid (see that controller). Mails
 * go out one at a time (not queued, not parallel — matches "mail should send
 * one by one in backend"), each attempt logged to email_logs regardless of
 * whether it actually succeeded, so a failed send is visible instead of just
 * silently missing.
 */
class TeacherAssignmentMailService
{
    public function __construct(private readonly MailBrandingService $branding) {}

    /**
     * @param  int  $senderId  the admin who triggered the assignment
     * @param  list<array{teacher_id: int, assigned_count: int}>  $summary  AssignTeacherService::assign()'s own return shape
     */
    public function sendAssignmentEmails(
        int $senderId,
        array $summary,
        string $emailSubject,
        string $emailBody,
        int $courseId,
        string $evaluationStartDate,
        string $evaluationEndDate,
        ?int $evaluationTimePerSheet = null,
    ): void {
        $course = Course::find($courseId);
        $courseName = $course ? "{$course->name} ({$course->code})" : 'the course';
        ['site_title' => $siteTitle, 'logo_url' => $logoUrl] = $this->branding->resolve();
        // Matches utils/date.js's formatDateTime() so the emailed dates
        // read the same as everywhere else in the app.
        $formattedStart = Carbon::parse($evaluationStartDate)->format('d-m-Y h:i A');
        $formattedEnd = Carbon::parse($evaluationEndDate)->format('d-m-Y h:i A');

        foreach ($summary as $row) {
            $teacher = User::find($row['teacher_id']);

            if (! $teacher || ! $teacher->email) {
                continue;
            }

            $mailable = new AnswerSheetAssignedMail(
                teacherName: $teacher->name,
                emailSubject: $emailSubject,
                emailBody: $emailBody,
                courseName: $courseName,
                evaluationStartDate: $formattedStart,
                evaluationEndDate: $formattedEnd,
                sheetsAssigned: $row['assigned_count'],
                siteTitle: $siteTitle,
                logoUrl: $logoUrl,
                evaluationTimePerSheet: $evaluationTimePerSheet,
            );

            $log = EmailLog::create([
                'sender_id' => $senderId,
                'receiver_id' => $teacher->id,
                'type' => 'answer_sheet_assigned',
                'subject' => $emailSubject,
                'body' => $mailable->render(),
                'is_sent' => false,
            ]);

            try {
                Mail::to($teacher->email)->send($mailable);
                $log->update(['is_sent' => true, 'sent_at' => now()]);
            } catch (Throwable $e) {
                Log::warning('Answer-sheet-assigned mail failed', [
                    'receiver_id' => $teacher->id,
                    'error' => $e->getMessage(),
                ]);
                $log->update(['is_sent' => false, 'error_message' => $e->getMessage()]);
            }
        }
    }
}
