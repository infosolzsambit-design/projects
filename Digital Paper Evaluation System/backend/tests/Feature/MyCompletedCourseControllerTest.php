<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\ExamType;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyCompletedCourseControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeacher(): User
    {
        $teacher = User::factory()->create();
        Sanctum::actingAs($teacher);

        return $teacher;
    }

    // Lazily created once per test and reused by every mapping fixture
    // built in it — see MyPendingCourseControllerTest's own copy of this
    // helper for why.
    private ?ExamType $defaultExamType = null;

    private function defaultExamTypeId(): int
    {
        return ($this->defaultExamType ??= ExamType::factory()->create())->id;
    }

    public function test_subjects_only_counts_this_teachers_own_completed_sheets(): void
    {
        $teacher = $this->actingTeacher();
        $otherTeacher = User::factory()->create();

        $english = Course::factory()->create(['name' => 'Business Communication', 'code' => 'COM401']);
        $englishPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $englishMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $english->id, 'question_paper_id' => $englishPaper->id]);

        AnswerSheet::factory()->count(2)->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $englishMapping->id, 'marks' => 15]);
        // Still pending — must not count as completed.
        AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $englishMapping->id, 'marks' => null]);
        // Someone else's completed sheet — must not leak into this teacher's list.
        AnswerSheet::factory()->create(['teacher_id' => $otherTeacher->id, 'question_answer_sheet_mapping_id' => $englishMapping->id, 'marks' => 10]);

        $response = $this->withApiKey()->getJson('/api/v1/my-completed-courses');

        $response->assertOk();
        $courses = collect($response->json('data'))->keyBy('course_id');
        $this->assertSame(2, $courses[$english->id]['completed_count']);
    }

    public function test_papers_lists_only_this_teachers_completed_sheets_for_the_given_course(): void
    {
        $teacher = $this->actingTeacher();
        $course = Course::factory()->create();
        $paper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $mapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $course->id, 'question_paper_id' => $paper->id]);

        $completed = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => 12]);
        AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null]);

        $response = $this->withApiKey()->getJson('/api/v1/my-completed-courses/papers?course_id='.$course->id);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$completed->id], $ids);
        $this->assertSame('12.00', collect($response->json('data'))->first()['marks']);
    }

    public function test_papers_only_shows_the_current_exam_year_for_a_non_super_admin_by_default(): void
    {
        $teacher = $this->actingTeacher();
        $course = Course::factory()->create();

        $thisYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $lastYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year - 1]);
        $thisYearMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $course->id, 'question_paper_id' => $thisYearPaper->id]);
        $lastYearMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $course->id, 'question_paper_id' => $lastYearPaper->id]);

        $thisYearSheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $thisYearMapping->id, 'marks' => 5]);
        AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $lastYearMapping->id, 'marks' => 5]);

        $response = $this->withApiKey()->getJson('/api/v1/my-completed-courses/papers?course_id='.$course->id);

        $response->assertOk();
        $this->assertSame([$thisYearSheet->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_subjects_shows_every_exam_year_to_a_super_admin(): void
    {
        Role::forceCreate(['id' => ((array) config('roles.super_admin_id'))[0], 'name' => 'Super Admin', 'guard_name' => config('auth.defaults.guard')]);
        $admin = $this->actingTeacher();
        $admin->assignRole((int) ((array) config('roles.super_admin_id'))[0]);

        $course = Course::factory()->create();
        $thisYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $lastYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year - 1]);
        $thisYearMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $course->id, 'question_paper_id' => $thisYearPaper->id]);
        $lastYearMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $course->id, 'question_paper_id' => $lastYearPaper->id]);

        AnswerSheet::factory()->create(['teacher_id' => $admin->id, 'question_answer_sheet_mapping_id' => $thisYearMapping->id, 'marks' => 5]);
        AnswerSheet::factory()->create(['teacher_id' => $admin->id, 'question_answer_sheet_mapping_id' => $lastYearMapping->id, 'marks' => 5]);

        $response = $this->withApiKey()->getJson('/api/v1/my-completed-courses');

        $response->assertOk();
        $this->assertSame(2, collect($response->json('data'))->firstWhere('course_id', $course->id)['completed_count']);
    }
}
