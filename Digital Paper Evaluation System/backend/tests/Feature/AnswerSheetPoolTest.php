<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\AnswerSheetPool;
use App\Models\Course;
use App\Models\EmailLog;
use App\Models\ExamTerm;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\TeacherDetail;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pool assignment, step 1: the pool tables/relations, and that a pooled
 * sheet (no teacher yet, but already handed to a pool) is never offered
 * for assignment again.
 */
class AnswerSheetPoolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{mapping: QuestionAnswerSheetMapping, filters: array<string, mixed>, pool: AnswerSheetPool}
     */
    private function mappingWithPooledAndPendingSheets(int $pooled, int $pending): array
    {
        $paper = QuestionPaper::factory()->create(['exam_year' => 2026]);
        $mapping = QuestionAnswerSheetMapping::factory()->create([
            'question_paper_id' => $paper->id,
            'course_id' => Course::factory()->create()->id,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'semester' => 3,
            'program_name' => 'Bachelor of Science (B.Sc)',
        ]);
        $pool = AnswerSheetPool::create([
            'program_name' => $mapping->program_name,
            'course_id' => $mapping->course_id,
            'exam_term_id' => $mapping->exam_term_id,
            'exam_type_id' => $mapping->exam_type_id,
            'semester' => 3,
            'exam_year' => 2026,
        ]);
        AnswerSheet::factory()->count($pooled)->create(['question_answer_sheet_mapping_id' => $mapping->id, 'answer_sheet_pool_id' => $pool->id]);
        AnswerSheet::factory()->count($pending)->create(['question_answer_sheet_mapping_id' => $mapping->id]);

        return [
            'mapping' => $mapping,
            'pool' => $pool,
            'filters' => [
                'program_name' => $mapping->program_name,
                'exam_term_id' => $mapping->exam_term_id,
                'exam_type_id' => $mapping->exam_type_id,
                'course_id' => $mapping->course_id,
                'semester' => 3,
                'exam_year' => 2026,
            ],
        ];
    }

    public function test_pool_relations_link_teachers_and_sheets(): void
    {
        ['pool' => $pool] = $this->mappingWithPooledAndPendingSheets(pooled: 4, pending: 1);
        $teachers = TeacherDetail::factory()->count(2)->create()->pluck('user_id');
        $pool->teachers()->attach($teachers);

        $this->assertEqualsCanonicalizing($teachers->all(), $pool->teachers()->pluck('users.id')->all());
        $this->assertSame(4, $pool->sheets()->count());
        $this->assertTrue($pool->sheets()->first()->pool->is($pool));
    }

    public function test_awaiting_assignment_excludes_pooled_and_assigned_sheets(): void
    {
        ['mapping' => $mapping] = $this->mappingWithPooledAndPendingSheets(pooled: 3, pending: 2);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => TeacherDetail::factory()->create()->user_id]);

        $this->assertSame(2, AnswerSheet::where('question_answer_sheet_mapping_id', $mapping->id)->awaitingAssignment()->count());
    }

    public function test_distribution_never_assigns_a_pooled_sheet(): void
    {
        Sanctum::actingAs(User::factory()->create());
        ['filters' => $filters, 'pool' => $pool] = $this->mappingWithPooledAndPendingSheets(pooled: 3, pending: 2);
        $teacher = TeacherDetail::factory()->create()->user_id;
        $payload = $filters + [
            'evaluation_start_date' => now()->addDay()->format('Y-m-d H:i'),
            'evaluation_end_date' => now()->addDays(5)->format('Y-m-d H:i'),
            'email_subject' => 'Answer Sheets Assigned',
            'email_body' => 'You have been assigned answer sheets to evaluate.',
        ];

        // Only the 2 un-pooled sheets are available — asking for 3 fails.
        $this->withApiKey()->postJson('/api/v1/assign-teacher', $payload + ['assignments' => [['teacher_id' => $teacher, 'quantity' => 3]]])
            ->assertStatus(422);

        $this->withApiKey()->postJson('/api/v1/assign-teacher', $payload + ['assignments' => [['teacher_id' => $teacher, 'quantity' => 2]]])
            ->assertOk();
        $this->assertSame(0, AnswerSheet::where('teacher_id', $teacher)->whereNotNull('answer_sheet_pool_id')->count());
        $this->assertSame(3, $pool->sheets()->whereNull('teacher_id')->count());
    }

    public function test_pending_to_assign_count_on_answer_sheets_list_ignores_pooled_sheets(): void
    {
        Sanctum::actingAs(User::factory()->create());
        ['mapping' => $mapping] = $this->mappingWithPooledAndPendingSheets(pooled: 3, pending: 2);

        $row = collect($this->withApiKey()->getJson('/api/v1/answer-sheet-mappings')->assertOk()->json('data.items'))
            ->firstWhere('id', $mapping->id);

        $this->assertSame(5, $row['answer_sheet_count']);
        $this->assertSame(2, $row['pending_answer_sheet_count']);
        $this->assertSame(3, $row['in_pool_answer_sheet_count']);
    }

    /** @return array<string, mixed> */
    private function poolPayload(array $filters, array $teacherIds, int $quantity = 5): array
    {
        return $filters + [
            'mode' => 'pool',
            'teacher_ids' => $teacherIds,
            'pool_quantity' => $quantity,
            'evaluation_start_date' => '2026-10-10 09:00',
            'evaluation_end_date' => '2026-10-15 18:00',
            'evaluation_time_per_sheet' => 30,
            'email_subject' => 'Answer Sheets Pool',
            'email_body' => 'You have been added to a shared pool.',
        ];
    }

    public function test_pool_mode_puts_every_pending_sheet_in_one_pool_shared_by_the_teachers(): void
    {
        Sanctum::actingAs(User::factory()->create());
        ['filters' => $filters, 'mapping' => $mapping] = $this->mappingWithPooledAndPendingSheets(pooled: 0, pending: 5);
        $alreadyAssigned = AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => TeacherDetail::factory()->create()->user_id]);
        $teachers = TeacherDetail::factory()->count(3)->create()->pluck('user_id')->all();

        $this->withApiKey()->postJson('/api/v1/assign-teacher', $this->poolPayload($filters, $teachers))
            ->assertOk()
            ->assertJsonPath('data.total_pooled', 5)
            ->assertJsonPath('data.teacher_count', 3);

        $pool = AnswerSheetPool::latest('id')->first();
        $this->assertEqualsCanonicalizing($teachers, $pool->teachers()->pluck('users.id')->all());
        // Every pending sheet is in the pool, none has a teacher yet, and the window is stamped.
        $this->assertSame(5, $pool->sheets()->whereNull('teacher_id')->count());
        $this->assertSame('2026-10-15 18:00:00', $pool->sheets()->first()->evaluation_end_date->format('Y-m-d H:i:s'));
        $this->assertSame(30, $pool->sheets()->first()->evaluation_time_per_sheet);
        // The already-assigned sheet is untouched.
        $this->assertNull($alreadyAssigned->fresh()->answer_sheet_pool_id);
        $this->assertSame(0, AnswerSheet::where('question_answer_sheet_mapping_id', $mapping->id)->awaitingAssignment()->count());

        // Each pool teacher: one bell notification and one email mentioning the shared pool.
        foreach ($teachers as $teacherId) {
            $this->assertStringContainsString('shared pool of 5 answer sheets', UserNotification::where('user_id', $teacherId)->value('message'));
            $log = EmailLog::where('receiver_id', $teacherId)->first();
            $this->assertNotNull($log);
            $this->assertStringContainsString('shared by 3 teachers', $log->body);
        }
        $this->assertDatabaseHas('audits', ['event' => 'answer-sheets-pooled']);
    }

    public function test_pool_mode_needs_at_least_two_teachers(): void
    {
        Sanctum::actingAs(User::factory()->create());
        ['filters' => $filters] = $this->mappingWithPooledAndPendingSheets(pooled: 0, pending: 2);

        $this->withApiKey()->postJson('/api/v1/assign-teacher', $this->poolPayload($filters, [TeacherDetail::factory()->create()->user_id], 2))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['teacher_ids']);
    }

    public function test_pool_mode_takes_only_the_requested_quantity(): void
    {
        Sanctum::actingAs(User::factory()->create());
        ['filters' => $filters, 'mapping' => $mapping] = $this->mappingWithPooledAndPendingSheets(pooled: 0, pending: 5);
        $teachers = TeacherDetail::factory()->count(2)->create()->pluck('user_id')->all();

        $this->withApiKey()->postJson('/api/v1/assign-teacher', $this->poolPayload($filters, $teachers, 3))
            ->assertOk()->assertJsonPath('data.total_pooled', 3);

        $this->assertSame(3, AnswerSheetPool::latest('id')->first()->sheets()->count());
        $this->assertSame(2, AnswerSheet::where('question_answer_sheet_mapping_id', $mapping->id)->awaitingAssignment()->count());
    }

    public function test_pool_quantity_must_be_a_whole_number_within_the_pending_count(): void
    {
        Sanctum::actingAs(User::factory()->create());
        ['filters' => $filters] = $this->mappingWithPooledAndPendingSheets(pooled: 0, pending: 5);
        $teachers = TeacherDetail::factory()->count(2)->create()->pluck('user_id')->all();
        $url = '/api/v1/assign-teacher';
        $poolsBefore = AnswerSheetPool::count(); // the fixture's own (empty) pool

        $this->withApiKey()->postJson($url, ['pool_quantity' => 2.5] + $this->poolPayload($filters, $teachers))
            ->assertStatus(422)->assertJsonValidationErrors('pool_quantity');
        $this->withApiKey()->postJson($url, ['pool_quantity' => 0] + $this->poolPayload($filters, $teachers))
            ->assertStatus(422)->assertJsonValidationErrors('pool_quantity');
        $this->withApiKey()->postJson($url, $this->poolPayload($filters, $teachers, 6))
            ->assertStatus(422)->assertJsonPath('message', 'Only 5 answer sheet(s) are still pending for this search — 6 were requested.');
        $this->assertSame($poolsBefore, AnswerSheetPool::count());
    }

    public function test_pool_mode_fails_when_nothing_is_pending(): void
    {
        Sanctum::actingAs(User::factory()->create());
        ['filters' => $filters] = $this->mappingWithPooledAndPendingSheets(pooled: 3, pending: 0);
        $teachers = TeacherDetail::factory()->count(2)->create()->pluck('user_id')->all();

        $this->withApiKey()->postJson('/api/v1/assign-teacher', $this->poolPayload($filters, $teachers))
            ->assertStatus(422)
            ->assertJsonPath('message', 'No answer sheets are still pending for this search.');
    }
}
