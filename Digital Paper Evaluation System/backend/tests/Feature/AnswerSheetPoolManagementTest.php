<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\AnswerSheetPool;
use App\Models\Course;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\TeacherDetail;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pool assignment, step 5 — the Shared Pools page: list pools, change the
 * teachers sharing one, cancel one.
 */
class AnswerSheetPoolManagementTest extends TestCase
{
    use RefreshDatabase;

    private AnswerSheetPool $pool;

    /** @var list<int> */
    private array $teachers;

    private QuestionAnswerSheetMapping $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        $course = Course::factory()->create(['name' => 'Organic Chemistry', 'code' => 'CH-201']);
        $this->mapping = QuestionAnswerSheetMapping::factory()->create(['course_id' => $course->id]);
        $this->pool = AnswerSheetPool::create([
            'program_name' => $this->mapping->program_name,
            'course_id' => $course->id,
            'exam_term_id' => $this->mapping->exam_term_id,
            'exam_type_id' => $this->mapping->exam_type_id,
            'semester' => 2,
            'exam_year' => 2026,
            'evaluation_start_date' => '2026-10-10 09:00:00',
            'evaluation_end_date' => '2026-10-15 18:00:00',
            'evaluation_time_per_sheet' => 30,
        ]);
        $this->teachers = TeacherDetail::factory()->count(2)->create()->pluck('user_id')->all();
        $this->pool->teachers()->attach($this->teachers);

        $base = ['question_answer_sheet_mapping_id' => $this->mapping->id, 'answer_sheet_pool_id' => $this->pool->id, 'marks' => null,
            'evaluation_start_date' => '2026-10-10 09:00:00', 'evaluation_end_date' => '2026-10-15 18:00:00', 'evaluation_time_per_sheet' => 30];
        AnswerSheet::factory()->count(3)->create($base + ['teacher_id' => null]);               // waiting
        AnswerSheet::factory()->create($base + ['teacher_id' => $this->teachers[0]]);           // started
        AnswerSheet::factory()->create(['marks' => 15] + $base + ['teacher_id' => $this->teachers[1]]); // evaluated
    }

    public function test_index_lists_pools_with_teachers_and_progress_counts(): void
    {
        $row = $this->withApiKey()->getJson('/api/v1/answer-sheet-pools')->assertOk()->json('data.items.0');

        $this->assertSame($this->pool->id, $row['id']);
        $this->assertSame('Organic Chemistry', $row['course_name']);
        $this->assertCount(2, $row['teachers']);
        $this->assertSame(5, $row['sheet_count']);
        $this->assertSame(3, $row['waiting_count']);
        $this->assertSame(2, $row['started_count']);
        $this->assertSame(1, $row['evaluated_count']);
        // Per teacher: teacher 0 started 1 (not evaluated), teacher 1 started 1 and evaluated it.
        $breakdown = collect($row['teacher_breakdown'])->keyBy('id');
        $this->assertSame(1, $breakdown[$this->teachers[0]]['started_count']);
        $this->assertSame(0, $breakdown[$this->teachers[0]]['evaluated_count']);
        $this->assertSame(1, $breakdown[$this->teachers[1]]['evaluated_count']);

        $this->withApiKey()->getJson('/api/v1/answer-sheet-pools?search=CH-201')->assertJsonCount(1, 'data.items');
        $this->withApiKey()->getJson('/api/v1/answer-sheet-pools?search=Zyxology')->assertJsonCount(0, 'data.items');
        $this->withApiKey()->getJson('/api/v1/answer-sheet-pools?id='.$this->pool->id)->assertOk()->assertJsonPath('data.id', $this->pool->id);
        $this->withApiKey()->getJson('/api/v1/answer-sheet-pools?id=999999')->assertNotFound();
    }

    public function test_changing_teachers_notifies_only_the_added_ones_and_keeps_started_sheets(): void
    {
        $added = TeacherDetail::factory()->create()->user_id;

        // Remove teacher[0] (who started one sheet), keep teacher[1], add a new one.
        $this->withApiKey()->putJson("/api/v1/answer-sheet-pools/{$this->pool->id}/teachers", ['teacher_ids' => [$this->teachers[1], $added]])
            ->assertOk()
            ->assertJsonCount(2, 'data.teachers');

        $this->assertEqualsCanonicalizing([$this->teachers[1], $added], $this->pool->teachers()->pluck('users.id')->all());
        $this->assertStringContainsString('shared pool of 3 answer sheets', UserNotification::where('user_id', $added)->value('message'));
        $this->assertSame(0, UserNotification::where('user_id', $this->teachers[1])->count());
        // The removed teacher keeps the sheet they started, and still shows in
        // the breakdown (flagged as no longer in the pool).
        $this->assertSame(1, AnswerSheet::where('teacher_id', $this->teachers[0])->count());
        $removed = collect($this->withApiKey()->getJson('/api/v1/answer-sheet-pools')->json('data.items.0.teacher_breakdown'))->firstWhere('id', $this->teachers[0]);
        $this->assertFalse($removed['in_pool']);
        $this->assertSame(1, $removed['started_count']);
        $this->assertDatabaseHas('audits', ['event' => 'answer-sheet-pool-teachers-updated']);
    }

    public function test_changing_teachers_needs_at_least_one_real_teacher(): void
    {
        $this->withApiKey()->putJson("/api/v1/answer-sheet-pools/{$this->pool->id}/teachers", ['teacher_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('teacher_ids');
        $this->withApiKey()->putJson("/api/v1/answer-sheet-pools/{$this->pool->id}/teachers", ['teacher_ids' => [User::factory()->create()->id]])
            ->assertStatus(422)->assertJsonValidationErrors('teacher_ids.0');
    }

    public function test_cancelling_returns_waiting_sheets_to_pending_and_keeps_started_ones(): void
    {
        $this->withApiKey()->postJson("/api/v1/answer-sheet-pools/{$this->pool->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.returned_to_pending', 3)
            ->assertJsonPath('data.kept_by_teachers', 2);

        $this->assertSoftDeleted($this->pool);
        $returned = AnswerSheet::where('question_answer_sheet_mapping_id', $this->mapping->id)->awaitingAssignment()->get();
        $this->assertCount(3, $returned);
        $this->assertNull($returned->first()->evaluation_end_date);
        $this->assertSame(2, AnswerSheet::whereNotNull('teacher_id')->where('answer_sheet_pool_id', $this->pool->id)->count());

        // Pool teachers no longer see anything from it, and it's listed only as cancelled.
        Sanctum::actingAs(User::find($this->teachers[1]));
        $this->assertSame(0, AnswerSheet::availableTo($this->teachers[1])->whereNull('teacher_id')->count());
        $this->withApiKey()->getJson('/api/v1/answer-sheet-pools')->assertJsonCount(0, 'data.items');
        $this->withApiKey()->getJson('/api/v1/answer-sheet-pools?status=deleted')->assertJsonCount(1, 'data.items');
        $this->assertDatabaseHas('audits', ['event' => 'answer-sheet-pool-cancelled']);
    }

    public function test_a_cancelled_pool_cannot_be_changed_or_cancelled_again(): void
    {
        $this->pool->delete();

        $this->withApiKey()->postJson("/api/v1/answer-sheet-pools/{$this->pool->id}/cancel")->assertNotFound();
        $this->withApiKey()->putJson("/api/v1/answer-sheet-pools/{$this->pool->id}/teachers", ['teacher_ids' => $this->teachers])->assertNotFound();
    }
}
