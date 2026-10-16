<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\ExamSubType;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\GeneralSetting;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QrExamStudentTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://college.test/api/V1/DataApi/examStudent';

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
        foreach ([
            'sync_base_url' => 'https://college.test/api/V1',
            'sync_base_url_security_code' => 'secret-code',
            'is_need_api_from_main_db' => 'yes',
        ] as $field => $value) {
            GeneralSetting::updateOrCreate(['field_name' => $field], GeneralSetting::factory()->make(['field_name' => $field, 'value' => $value, 'status' => true])->toArray());
        }
    }

    public function test_term_type_sub_type_program_course_and_semester_are_required(): void
    {
        Http::fake();
        $this->withApiKey()->getJson('/api/v1/qr/exam-students')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['exam_term_id', 'exam_type_id', 'enroll_session_id', 'program_id', 'course_id', 'semester']);
        Http::assertNothingSent();
    }

    public function test_search_sends_the_main_database_ids_and_maps_the_rows(): void
    {
        ExamTerm::create(['inserted_id' => 23, 'name' => 'Odd 2025', 'status' => true]);
        ExamType::create(['inserted_id' => 14, 'name' => 'End Sem', 'status' => true]);
        ExamSubType::create(['inserted_id' => 8, 'name' => 'ROOM-TEST-EXAM', 'status' => true]);
        Department::create(['inserted_id' => 64, 'name' => 'Computer Applications', 'status' => true]);
        $program = Program::create(['inserted_id' => 77, 'name' => 'BCA', 'label' => 'UG', 'department' => '', 'code' => '', 'status' => true]);
        $program->courses()->attach(Course::create(['inserted_id' => 2670, 'name' => 'Big Data Analytics', 'code' => 'TIU-PCA-T140', 'type' => 'Theory', 'status' => true]));

        Http::fake([self::URL.'*' => Http::response(['status' => true, 'data' => [
            ['student_id' => '6095', 'regisration_id' => '251005004160', 'name' => "\u{00A0}AAKIRITI SHARMA .", 'program_id' => '77', 'course_id' => '2670',
                'course_name' => 'Big Data Analytics', 'course_code' => 'TIU-PCA-T140', 'exam_type_id' => '14', 'exam_term_id' => '23',
                'exam_year' => '2025-2026', 'department_id' => '64', 'batch' => '2025', 'semester' => '2', 'delete_status' => '1'],
            ['student_id' => '6095', 'regisration_id' => '251005004160', 'name' => 'AAKIRITI SHARMA .', 'program_id' => '77', 'course_id' => '2721',
                'course_name' => 'Networks Lab', 'course_code' => 'L22102', 'exam_type_id' => '14', 'exam_term_id' => '23',
                'exam_year' => '2025-2026', 'department_id' => '64', 'batch' => '2025', 'semester' => '2', 'delete_status' => '1'],
        ]])]);

        // Ref Ids (inserted_id), not our own ids.
        $this->withApiKey()->getJson('/api/v1/qr/exam-students?exam_term_id=23&exam_type_id=14&enroll_session_id=8&program_id=77&course_id=2670&department_id=64&semester=2&exam_year=2025-2026&batch=2025')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.student_count', 1)
            ->assertJsonPath('data.items.0.name', 'AAKIRITI SHARMA .')
            ->assertJsonPath('data.items.0.registration_no', '251005004160')
            ->assertJsonPath('data.items.0.program_name', 'BCA')
            ->assertJsonPath('data.items.0.department_name', 'Computer Applications')
            ->assertJsonPath('data.items.0.department_id', '64')
            ->assertJsonPath('data.items.0.course_code', 'TIU-PCA-T140');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_starts_with($r->url(), self::URL.'?')
            && str_contains($r->url(), 'exam_term_id=23') && str_contains($r->url(), 'exam_type_id=14')
            && str_contains($r->url(), 'department_id=64') && str_contains($r->url(), 'semester=2')
            && str_contains($r->url(), 'enroll_session_id=8') && str_contains($r->url(), 'program_id=77') && str_contains($r->url(), 'course_id=2670') && str_contains($r->url(), 'exam_year=2025-2026')
            && str_contains($r->url(), 'batch=2025')
            && $r->hasHeader('Security-Code', 'secret-code'));
    }

    public function test_our_own_id_is_not_accepted_only_the_ref_id(): void
    {
        Http::fake();
        $term = ExamTerm::create(['inserted_id' => 23, 'name' => 'Odd 2025', 'status' => true]);
        ExamType::create(['inserted_id' => 14, 'name' => 'End Sem', 'status' => true]);
        $subType = ExamSubType::create(['inserted_id' => 8, 'name' => 'ROOM-TEST-EXAM', 'status' => true]);
        $program = Program::create(['inserted_id' => 77, 'name' => 'BCA', 'label' => 'UG', 'department' => '', 'code' => '', 'status' => true]);
        $program->courses()->attach(Course::create(['inserted_id' => 2670, 'name' => 'Big Data', 'code' => 'BD1', 'type' => 'Theory', 'status' => true]));
        $rest = '&program_id=77&course_id=2670&semester=2';
        $this->assertNotSame(23, $term->id);
        $this->assertNotSame(8, $subType->id);

        // Deleted in the main database (deleted_from_main_db = 1) → rejected.
        ExamType::create(['inserted_id' => 15, 'name' => 'Gone There', 'deleted_from_main_db' => true, 'status' => true]);
        $this->withApiKey()->getJson('/api/v1/qr/exam-students?exam_term_id=23&exam_type_id=15&enroll_session_id=8'.$rest)
            ->assertStatus(422)
            ->assertJsonValidationErrors('exam_type_id');

        // Our auto-increment id for the term → rejected.
        $this->withApiKey()->getJson("/api/v1/qr/exam-students?exam_term_id={$term->id}&exam_type_id=14&enroll_session_id={$subType->id}{$rest}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['exam_term_id', 'enroll_session_id']);
        Http::assertNothingSent();
    }

    public function test_course_must_be_mapped_to_the_program_and_the_dropdown_lists_only_those(): void
    {
        Http::fake();
        ExamTerm::create(['inserted_id' => 23, 'name' => 'Odd 2025', 'status' => true]);
        ExamType::create(['inserted_id' => 14, 'name' => 'End Sem', 'status' => true]);
        ExamSubType::create(['inserted_id' => 8, 'name' => 'ROOM-TEST-EXAM', 'status' => true]);
        $bca = Program::create(['inserted_id' => 77, 'name' => 'BCA', 'label' => 'UG', 'department' => '', 'code' => '', 'status' => true]);
        $mca = Program::create(['inserted_id' => 78, 'name' => 'MCA', 'label' => 'PG', 'department' => '', 'code' => '', 'status' => true]);
        $bca->courses()->attach([
            Course::create(['inserted_id' => 2670, 'name' => 'Big Data', 'code' => 'BD1', 'type' => 'Theory', 'status' => true])->id,
            Course::create(['inserted_id' => 2671, 'name' => 'Gone There', 'code' => 'G1', 'type' => 'Theory', 'deleted_from_main_db' => true, 'status' => true])->id,
        ]);
        $mca->courses()->attach(Course::create(['inserted_id' => 2800, 'name' => 'Cloud', 'code' => 'CL1', 'type' => 'Theory', 'status' => true]));

        // Only BCA's own courses, by Ref Id — not the deleted-there one.
        $this->withApiKey()->getJson('/api/v1/qr/program-courses?program_id=77')
            ->assertOk()
            ->assertJsonPath('data', [['id' => 2670, 'name' => 'Big Data (BD1)']]);

        // MCA's course with BCA → rejected.
        $this->withApiKey()->getJson('/api/v1/qr/exam-students?exam_term_id=23&exam_type_id=14&enroll_session_id=8&program_id=77&course_id=2800&semester=2')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['course_id' => 'This course is not mapped to the selected program.']);
        Http::assertNothingSent();
    }
}
