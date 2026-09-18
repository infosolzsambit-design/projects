<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssignTeacherTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * @return array{start:string,end:string}
     */
    private function evaluationWindow(): array
    {
        return [
            'evaluation_start_date' => '2026-01-10',
            'evaluation_end_date' => '2026-01-20',
            'evaluation_time_per_sheet' => '60',
            'email_subject' => 'Answer Sheets Assigned',
            'email_body' => 'You have been assigned answer sheets to evaluate.',
        ];
    }

    /**
     * @return array{mapping: QuestionAnswerSheetMapping, filters: array<string, mixed>}
     */
    private function mappingWithPendingSheets(int $count, array $overrides = []): array
    {
        $paper = QuestionPaper::factory()->create(['exam_year' => 2026]);
        $course = Course::factory()->create();
        $examTerm = ExamTerm::factory()->create();

        $mapping = QuestionAnswerSheetMapping::factory()->create(array_merge([
            'question_paper_id' => $paper->id,
            'course_id' => $course->id,
            'exam_term_id' => $examTerm->id,
            'semester' => 3,
            'program_name' => 'Bachelor of Science (B.Sc)',
        ], $overrides));

        AnswerSheet::factory()->count($count)->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
        ]);

        return [
            'mapping' => $mapping,
            'filters' => [
                'program_name' => $mapping->program_name,
                'exam_term_id' => $mapping->exam_term_id,
                'exam_type_id' => $mapping->exam_type_id,
                'course_id' => $mapping->course_id,
                'semester' => $mapping->semester,
                'exam_year' => 2026,
            ],
        ];
    }

    public function test_assign_splits_pending_sheets_across_teachers_and_reduces_pending_count(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(11);

        $teacherA = TeacherDetail::factory()->create()->user_id;
        $teacherB = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            ...$this->evaluationWindow(),
            'assignments' => [
                ['teacher_id' => $teacherA, 'quantity' => 6],
                ['teacher_id' => $teacherB, 'quantity' => 5],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.total_assigned', 11);

        $this->assertSame(6, AnswerSheet::where('teacher_id', $teacherA)->count());
        $this->assertSame(5, AnswerSheet::where('teacher_id', $teacherB)->count());
        $this->assertSame(0, AnswerSheet::whereNull('teacher_id')->count());

        // The mapping's own pending_answer_sheet_count should now reflect
        // the assignment, distinct from its always-11 answer_sheet_count.
        $show = $this->withApiKey()->getJson('/api/v1/answer-sheet-mappings');
        $row = collect($show->json('data.items'))->firstWhere('program_name', $filters['program_name']);
        $this->assertSame(11, $row['answer_sheet_count']);
        $this->assertSame(0, $row['pending_answer_sheet_count']);
    }

    public function test_assign_stamps_the_evaluation_window_on_every_assigned_sheet(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(4);
        $teacher = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            'evaluation_start_date' => '2026-02-01',
            'evaluation_end_date' => '2026-02-15',
            'evaluation_time_per_sheet' => '90',
            'email_subject' => 'Answer Sheets Assigned',
            'email_body' => 'You have been assigned answer sheets to evaluate.',
            'assignments' => [['teacher_id' => $teacher, 'quantity' => 4]],
        ]);

        $response->assertOk();

        AnswerSheet::where('teacher_id', $teacher)->get()->each(function (AnswerSheet $sheet) {
            $this->assertSame('2026-02-01', $sheet->evaluation_start_date->toDateString());
            $this->assertSame('2026-02-15', $sheet->evaluation_end_date->toDateString());
            $this->assertSame(90, $sheet->evaluation_time_per_sheet);
        });
    }

    public function test_assign_stores_the_time_component_of_the_evaluation_window(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(2);
        $teacher = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            'evaluation_start_date' => '2026-02-01 09:30',
            'evaluation_end_date' => '2026-02-15 17:45',
            'evaluation_time_per_sheet' => '75',
            'email_subject' => 'Answer Sheets Assigned',
            'email_body' => 'You have been assigned answer sheets to evaluate.',
            'assignments' => [['teacher_id' => $teacher, 'quantity' => 2]],
        ]);

        $response->assertOk();

        AnswerSheet::where('teacher_id', $teacher)->get()->each(function (AnswerSheet $sheet) {
            $this->assertSame('2026-02-01 09:30', $sheet->evaluation_start_date->format('Y-m-d H:i'));
            $this->assertSame('2026-02-15 17:45', $sheet->evaluation_end_date->format('Y-m-d H:i'));
        });
    }

    public function test_assign_rejects_an_evaluation_end_time_before_the_start_time_on_the_same_day(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(2);
        $teacher = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            'evaluation_start_date' => '2026-02-10 15:00',
            'evaluation_end_date' => '2026-02-10 09:00',
            'evaluation_time_per_sheet' => '1',
            'assignments' => [['teacher_id' => $teacher, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['evaluation_end_date']);
    }

    /**
     * evaluation_time_per_sheet is optional — a blank value means no
     * per-sheet time limit for the evaluator (see EvaluatePaperView.vue's
     * totalMinutes, which already treats a null value as "no limit").
     */
    public function test_assign_succeeds_with_no_evaluation_time_per_sheet(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(2);
        $teacher = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            'evaluation_start_date' => '2026-02-10 09:00',
            'evaluation_end_date' => '2026-02-10 15:00',
            'email_subject' => 'Answer Sheets Assigned',
            'email_body' => 'You have been assigned answer sheets to evaluate.',
            'assignments' => [['teacher_id' => $teacher, 'quantity' => 1]],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('answer_sheets', [
            'teacher_id' => $teacher,
            'evaluation_time_per_sheet' => null,
        ]);
    }

    public function test_assign_rejects_a_decimal_evaluation_time_per_sheet(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(2);
        $teacher = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            'evaluation_start_date' => '2026-02-10 09:00',
            'evaluation_end_date' => '2026-02-10 15:00',
            'evaluation_time_per_sheet' => '1.5',
            'assignments' => [['teacher_id' => $teacher, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['evaluation_time_per_sheet']);
    }

    public function test_assign_requires_both_evaluation_dates(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(2);
        $teacher = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            'assignments' => [['teacher_id' => $teacher, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['evaluation_start_date', 'evaluation_end_date']);
        $this->assertSame(0, AnswerSheet::whereNotNull('teacher_id')->count());
    }

    public function test_assign_rejects_an_evaluation_end_date_before_the_start_date(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(2);
        $teacher = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            'evaluation_start_date' => '2026-02-15',
            'evaluation_end_date' => '2026-02-01',
            'assignments' => [['teacher_id' => $teacher, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['evaluation_end_date']);
    }

    public function test_assign_rejects_more_than_the_pending_count(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(3);
        $teacher = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            ...$this->evaluationWindow(),
            'assignments' => [['teacher_id' => $teacher, 'quantity' => 10]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, AnswerSheet::whereNotNull('teacher_id')->count());
    }

    public function test_assign_rejects_when_no_packet_matches_the_filters(): void
    {
        $this->actingAdmin();
        $teacher = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            'program_name' => 'Nonexistent Program',
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'exam_type_id' => ExamType::factory()->create()->id,
            'course_id' => Course::factory()->create()->id,
            'semester' => 1,
            'exam_year' => 2026,
            ...$this->evaluationWindow(),
            'assignments' => [['teacher_id' => $teacher, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
    }

    public function test_assign_rejects_a_teacher_id_that_is_not_a_real_teacher(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(3);
        $notATeacher = User::factory()->create(); // no TeacherDetail row

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            ...$this->evaluationWindow(),
            'assignments' => [['teacher_id' => $notATeacher->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['assignments.0.teacher_id']);
    }

    public function test_assign_leaves_the_remainder_pending_when_quantities_dont_cover_everything(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(5);
        $teacher = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            ...$this->evaluationWindow(),
            'assignments' => [['teacher_id' => $teacher, 'quantity' => 2]],
        ]);

        $response->assertOk();
        $this->assertSame(2, AnswerSheet::where('teacher_id', $teacher)->count());
        $this->assertSame(3, AnswerSheet::whereNull('teacher_id')->count());
    }

    public function test_assign_requires_email_subject_and_body(): void
    {
        $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(2);
        $teacher = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            'evaluation_start_date' => '2026-02-10 09:00',
            'evaluation_end_date' => '2026-02-10 15:00',
            'assignments' => [['teacher_id' => $teacher, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email_subject', 'email_body']);
    }

    /**
     * dispatch(...)->afterResponse() still runs within a test (the test
     * kernel's terminate() is called — see MakesHttpRequests::call()), and
     * MAIL_MAILER=array/QUEUE_CONNECTION=sync in phpunit.xml means this
     * runs synchronously with no real network call — so this can assert
     * the real end-to-end effect (an EmailLog row per assigned teacher)
     * instead of just faking the send.
     */
    public function test_assign_logs_and_sends_an_email_to_every_assigned_teacher(): void
    {
        $admin = $this->actingAdmin();
        ['filters' => $filters] = $this->mappingWithPendingSheets(6);
        $teacherA = TeacherDetail::factory()->create()->user_id;
        $teacherB = TeacherDetail::factory()->create()->user_id;

        $response = $this->withApiKey()->postJson('/api/v1/assign-teacher', [
            ...$filters,
            ...$this->evaluationWindow(),
            'assignments' => [
                ['teacher_id' => $teacherA, 'quantity' => 4],
                ['teacher_id' => $teacherB, 'quantity' => 2],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseCount('email_logs', 2);
        $this->assertDatabaseHas('email_logs', [
            'sender_id' => $admin->id,
            'receiver_id' => $teacherA,
            'type' => 'answer_sheet_assigned',
            'subject' => 'Answer Sheets Assigned',
            'is_sent' => true,
        ]);
        $this->assertDatabaseHas('email_logs', [
            'sender_id' => $admin->id,
            'receiver_id' => $teacherB,
            'type' => 'answer_sheet_assigned',
            'subject' => 'Answer Sheets Assigned',
            'is_sent' => true,
        ]);
    }
}
