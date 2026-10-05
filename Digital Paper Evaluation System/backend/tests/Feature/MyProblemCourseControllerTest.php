<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\ExamType;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyProblemCourseControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Course $course;

    private QuestionAnswerSheetMapping $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        Sanctum::actingAs($this->teacher);

        $this->course = Course::factory()->create();
        $paper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $this->mapping = QuestionAnswerSheetMapping::factory()->create([
            'exam_type_id' => ExamType::factory()->create()->id,
            'course_id' => $this->course->id,
            'question_paper_id' => $paper->id,
        ]);
    }

    private function sheet(array $attributes = []): AnswerSheet
    {
        return AnswerSheet::factory()->create($attributes + [
            'teacher_id' => $this->teacher->id,
            'question_answer_sheet_mapping_id' => $this->mapping->id,
            'marks' => null,
        ]);
    }

    public function test_lists_this_teachers_open_and_resolved_issue_sheets_open_first(): void
    {
        $openPrinting = $this->sheet(['issue_master_id' => (int) config('issues.printing_issue_id'), 'issue_status' => 'open', 'issue_raised_at' => now()->subHour()]);
        $openTiming = $this->sheet(['issue_master_id' => (int) config('issues.timing_issue_id'), 'issue_status' => 'open', 'issue_raised_at' => now()->subHours(2)]);
        $resolved = $this->sheet(['issue_master_id' => (int) config('issues.printing_issue_id'), 'issue_status' => 'resolved', 'issue_raised_at' => now()]);
        $this->sheet();
        $this->sheet(['teacher_id' => User::factory()->create()->id, 'issue_status' => 'open']);

        $this->withApiKey()->getJson('/api/v1/my-problem-courses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.course_id', $this->course->id)
            ->assertJsonPath('data.0.problem_count', 2)
            ->assertJsonPath('data.0.resolved_count', 1);

        $response = $this->withApiKey()->getJson('/api/v1/my-problem-courses/papers?course_id='.$this->course->id);

        $response->assertOk();
        $this->assertSame(
            [$openPrinting->id, $openTiming->id, $resolved->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
        $this->assertSame('resolved', $response->json('data.2.issue_status'));
    }

    public function test_a_resolved_sheet_moves_back_to_pending_and_stays_listed_as_resolved(): void
    {
        $sheet = $this->sheet(['issue_master_id' => (int) config('issues.timing_issue_id'), 'issue_status' => 'open']);

        $this->withApiKey()->getJson('/api/v1/my-pending-courses/papers?course_id='.$this->course->id)
            ->assertOk()->assertJsonCount(0, 'data');

        $sheet->update(['issue_status' => 'resolved']);

        $this->withApiKey()->getJson('/api/v1/my-problem-courses/papers?course_id='.$this->course->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.issue_status', 'resolved');
        $this->withApiKey()->getJson('/api/v1/my-pending-courses/papers?course_id='.$this->course->id)
            ->assertOk()->assertJsonPath('data.0.id', $sheet->id);
    }

    public function test_papers_requires_a_course_id(): void
    {
        $this->withApiKey()->getJson('/api/v1/my-problem-courses/papers')->assertStatus(422);
    }
}
