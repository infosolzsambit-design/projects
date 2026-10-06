<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\AnswerSheetPool;
use App\Models\Course;
use App\Models\ExamType;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pool assignment, step 3 — the teacher side: every pool teacher sees the
 * unstarted pool sheets in My Pending Course, and the first to click Start
 * Evaluate claims the sheet; it then disappears for the others.
 */
class PoolEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private AnswerSheetPool $pool;

    /** @var list<User> */
    private array $poolTeachers;

    private User $admin;

    /** @var list<AnswerSheet> */
    private array $sheets;

    protected function setUp(): void
    {
        parent::setUp();

        // Same exam-year/exam-type scoping as MyPendingCourseControllerTest.
        $examType = ExamType::factory()->create();
        $this->course = Course::factory()->create();
        $paper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $mapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $examType->id, 'course_id' => $this->course->id, 'question_paper_id' => $paper->id]);

        $this->admin = User::factory()->create();
        Sanctum::actingAs($this->admin);
        $this->pool = AnswerSheetPool::create([
            'program_name' => $mapping->program_name,
            'course_id' => $this->course->id,
            'exam_term_id' => $mapping->exam_term_id,
            'exam_type_id' => $examType->id,
            'semester' => $mapping->semester,
            'exam_year' => now()->year,
        ]);
        $this->poolTeachers = User::factory()->count(2)->create()->all();
        $this->pool->teachers()->attach(collect($this->poolTeachers)->pluck('id'));

        $this->sheets = AnswerSheet::factory()->count(3)->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'answer_sheet_pool_id' => $this->pool->id,
            'teacher_id' => null,
            'marks' => null,
            'evaluation_start_date' => now()->subDay(),
            'evaluation_end_date' => now()->addDay(),
        ])->all();
    }

    private function paperIds(User $teacher): array
    {
        Sanctum::actingAs($teacher);

        return collect($this->withApiKey()->getJson('/api/v1/my-pending-courses/papers?course_id='.$this->course->id)->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    private function start(User $teacher, AnswerSheet $sheet)
    {
        Sanctum::actingAs($teacher);

        return $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/start-evaluation");
    }

    public function test_every_pool_teacher_sees_the_unstarted_pool_sheets_with_a_pool_flag(): void
    {
        [$a, $b] = $this->poolTeachers;
        $ids = collect($this->sheets)->pluck('id')->all();

        $this->assertEqualsCanonicalizing($ids, $this->paperIds($a));
        $this->assertEqualsCanonicalizing($ids, $this->paperIds($b));

        Sanctum::actingAs($a);
        $this->withApiKey()->getJson('/api/v1/my-pending-courses/papers?course_id='.$this->course->id)
            ->assertJsonPath('data.0.in_pool', true);
        $this->withApiKey()->getJson('/api/v1/my-pending-courses')
            ->assertOk()
            ->assertJsonPath('data.0.pending_count', 3);
    }

    public function test_a_teacher_outside_the_pool_sees_nothing_and_cannot_start_a_pool_sheet(): void
    {
        $outsider = User::factory()->create();

        $this->assertSame([], $this->paperIds($outsider));
        $this->start($outsider, $this->sheets[0])->assertNotFound();
        $this->assertNull($this->sheets[0]->fresh()->teacher_id);
    }

    public function test_starting_a_pool_sheet_claims_it_and_hides_it_from_the_other_pool_teachers(): void
    {
        [$a, $b] = $this->poolTeachers;
        $sheet = $this->sheets[0];

        $this->start($a, $sheet)->assertOk()->assertJsonStructure(['data' => ['evaluation_token']]);

        $sheet->refresh();
        $this->assertSame($a->id, $sheet->teacher_id);
        $this->assertNotNull($sheet->assigned_at);
        $this->assertSame($this->admin->id, $sheet->assigned_by); // the admin who created the pool
        $this->assertSame($this->pool->id, $sheet->answer_sheet_pool_id); // kept as history

        // Teacher A still has it (now as their own, no longer flagged in_pool); B no longer sees it.
        $this->assertContains($sheet->id, $this->paperIds($a));
        $this->assertNotContains($sheet->id, $this->paperIds($b));
        $this->assertCount(2, $this->paperIds($b));
        $this->assertDatabaseHas('audits', ['event' => 'answer-sheet-claimed-from-pool']);
    }

    public function test_the_second_teacher_to_start_the_same_sheet_is_refused(): void
    {
        [$a, $b] = $this->poolTeachers;
        $sheet = $this->sheets[0];

        $this->start($a, $sheet)->assertOk();
        $this->start($b, $sheet)->assertStatus(409)
            ->assertJsonPath('message', 'This answer sheet has already been started by another teacher. Your list has been refreshed.');

        $this->assertSame($a->id, $sheet->fresh()->teacher_id);
    }

    public function test_a_claimed_pool_sheet_works_like_an_assigned_one(): void
    {
        [$a] = $this->poolTeachers;
        $sheet = $this->sheets[0];
        $this->start($a, $sheet)->assertOk();

        // Starting again (continue evaluating) still works for the owner.
        $this->start($a, $sheet)->assertOk();
        // Draft save is accepted for the owner.
        $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/save-draft", ['consumed_time' => 30])->assertOk();
        $this->assertSame(30, $sheet->fresh()->consumed_time);
    }

    public function test_a_teacher_can_start_more_than_one_pool_sheet(): void
    {
        [$a, $b] = $this->poolTeachers;

        $this->start($a, $this->sheets[0])->assertOk();
        $this->start($a, $this->sheets[1])->assertOk();

        $this->assertSame(2, AnswerSheet::where('teacher_id', $a->id)->count());
        $this->assertSame([$this->sheets[2]->id], $this->paperIds($b));
    }
}
