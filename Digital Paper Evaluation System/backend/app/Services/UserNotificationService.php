<?php

namespace App\Services;

use App\Helpers\CourseLabel;
use App\Models\Course;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\User;
use App\Models\UserNotification;

/**
 * Creates the in-app notifications shown under the header's bell. One
 * method per event, so who gets notified, the wording and where a click
 * leads all live here:
 *
 *  - answer sheets assigned   -> that teacher            -> Pending Course
 *  - answer sheets reassigned -> each receiving teacher  -> Pending Course
 *  - issue raised             -> admins (same roles as the
 *                                issue-raised email)      -> Problems
 *  - issue resolved           -> the teacher who raised it -> Pending Course
 *  - evaluation reset         -> the sheet's teacher      -> Pending Course
 */
class UserNotificationService
{
    private const PENDING_COURSES = '/my-pending-courses';

    private const PROBLEMS = '/problems';

    /**
     * @param  list<array{teacher_id:int, assigned_count:int}>  $summary
     */
    public function answerSheetsAssigned(array $summary, int $courseId): void
    {
        $course = self::courseLabel(Course::find($courseId));
        $rows = [];
        foreach ($summary as $item) {
            if (($item['assigned_count'] ?? 0) < 1) {
                continue;
            }
            $count = (int) $item['assigned_count'];
            $rows[] = $this->row(
                userId: (int) $item['teacher_id'],
                type: UserNotification::ASSIGNED,
                title: 'New answer sheets assigned',
                message: "{$count} answer sheet".($count === 1 ? ' has' : 's have')." been assigned to you for evaluation in {$course}.",
                link: self::PENDING_COURSES,
                data: ['course_id' => $courseId, 'count' => $count],
            );
        }
        $this->insert($rows);
    }

    /**
     * @param  list<array{teacher_id:int, reassigned_count:int}>  $summary
     */
    public function answerSheetsReassigned(array $summary, int $mappingId, string $fromTeacherName): void
    {
        $course = self::courseLabel(QuestionAnswerSheetMapping::with('course')->find($mappingId)?->course);
        $rows = [];
        foreach ($summary as $item) {
            if (($item['reassigned_count'] ?? 0) < 1) {
                continue;
            }
            $count = (int) $item['reassigned_count'];
            $rows[] = $this->row(
                userId: (int) $item['teacher_id'],
                type: UserNotification::REASSIGNED,
                title: 'Answer sheets reassigned to you',
                message: "{$count} answer sheet".($count === 1 ? '' : 's')." of {$course} ".($count === 1 ? 'has' : 'have')." been reassigned to you from {$fromTeacherName}.",
                link: self::PENDING_COURSES,
                data: ['mapping_id' => $mappingId, 'count' => $count],
            );
        }
        $this->insert($rows);
    }

    public function issueRaised(int $answerSheetId, string $issueTypeName, string $teacherName, string $courseName, ?string $scriptCode): void
    {
        $adminIds = User::whereHas('roles', fn ($q) => $q->whereIn('roles.id', (array) config('roles.admin_recived_issue_mail')))
            ->pluck('id');

        $sheet = $scriptCode ? " on answer sheet {$scriptCode}" : '';
        $this->insert($adminIds->map(fn ($adminId) => $this->row(
            userId: (int) $adminId,
            type: UserNotification::ISSUE_RAISED,
            title: "{$issueTypeName} raised",
            message: "{$teacherName} raised a {$issueTypeName}{$sheet} in {$courseName}.",
            link: self::PROBLEMS,
            data: ['answer_sheet_id' => $answerSheetId],
        ))->all());
    }

    public function issueResolved(int $teacherId, int $answerSheetId, string $issueTypeName, string $courseName, ?string $scriptCode): void
    {
        $sheet = $scriptCode ? " on answer sheet {$scriptCode}" : '';
        $this->insert([$this->row(
            userId: $teacherId,
            type: UserNotification::ISSUE_RESOLVED,
            title: "Your {$issueTypeName} is resolved",
            message: "The {$issueTypeName} you raised{$sheet} in {$courseName} has been resolved. You can continue evaluating it.",
            link: self::PENDING_COURSES,
            data: ['answer_sheet_id' => $answerSheetId],
        )]);
    }

    /** An admin reset this sheet's evaluation (Reset Evaluation) — it's back in the teacher's pending list. */
    public function evaluationReset(int $teacherId, int $answerSheetId, string $courseName, ?string $scriptCode): void
    {
        $sheet = $scriptCode ? "answer sheet {$scriptCode}" : 'an answer sheet';
        $this->insert([$this->row(
            userId: $teacherId,
            type: UserNotification::EVALUATION_RESET,
            title: 'Evaluation reset',
            message: "The evaluation of {$sheet} in {$courseName} has been reset by the admin. Please evaluate it again.",
            link: self::PENDING_COURSES,
            data: ['answer_sheet_id' => $answerSheetId],
        )]);
    }

    public static function courseLabel(?Course $course): string
    {
        return CourseLabel::of($course, 'a course');
    }

    private function row(int $userId, string $type, string $title, string $message, string $link, array $data): array
    {
        return [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'data' => json_encode($data),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** One bulk insert per event, however many recipients. */
    private function insert(array $rows): void
    {
        if ($rows !== []) {
            UserNotification::insert($rows);
        }
    }
}
