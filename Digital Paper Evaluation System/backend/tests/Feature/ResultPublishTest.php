<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\Department;
use App\Models\ExamSubType;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\GeneralSetting;
use App\Models\Program;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /result-publish — public, guarded only by the General Settings
 * "API Security Code" sent as the Security-Code header.
 */
class ResultPublishTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'test-security-code-123';

    private ExamTerm $term;

    private ExamType $type;

    private ExamSubType $subType;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        GeneralSetting::updateOrCreate(
            ['field_name' => 'sync_base_url_security_code'],
            GeneralSetting::factory()->make(['field_name' => 'sync_base_url_security_code', 'value' => self::CODE, 'status' => true])->toArray(),
        );
        $this->term = ExamTerm::factory()->create(['inserted_id' => 32]);
        $this->type = ExamType::factory()->create(['inserted_id' => 28]);
        $this->subType = ExamSubType::factory()->create(['inserted_id' => 8]);
        $this->program = Program::factory()->create(['inserted_id' => 501, 'name' => 'B.C.A.']);
    }

    private const URL = '/api/v1/result-publish';

    private function body(array $overrides = []): array
    {
        return array_merge([
            'exam_term_id' => 32, 'exam_type_id' => 28, 'exam_sub_type_id' => 8, 'program_id' => 501, 'semester' => 3,
        ], $overrides);
    }

    private function packet(Course $course, int $fullMarks = 50, array $overrides = []): QuestionAnswerSheetMapping
    {
        return QuestionAnswerSheetMapping::factory()->create(array_merge([
            'question_paper_id' => QuestionPaper::factory()->create(['course_id' => $course->id, 'full_marks' => $fullMarks])->id,
            'course_id' => $course->id,
            'exam_term_id' => $this->term->id,
            'exam_type_id' => $this->type->id,
            'exam_sub_type_id' => $this->subType->id,
            'program_name' => 'B.C.A.',
            'semester' => 3,
        ], $overrides));
    }

    private function sheet(QuestionAnswerSheetMapping $packet, string $regNo, string $name, array $values): void
    {
        AnswerSheet::factory()->create(array_merge([
            'question_answer_sheet_mapping_id' => $packet->id,
            'registration_no' => $regNo,
            'roll_no' => $regNo,
            'name' => $name,
            'absent' => false,
            'marks' => null,
            'issue_status' => null,
        ], $values));
    }

    public function test_it_is_post_only(): void
    {
        // The app's exception handler answers a wrong method as 404 (no route), not 405.
        $status = $this->withHeaders(['Security-Code' => self::CODE])->getJson(self::URL.'?'.http_build_query($this->body()))->status();
        $this->assertContains($status, [404, 405]);
    }

    public function test_it_needs_the_security_code_and_nothing_else(): void
    {
        // No login, no X-API-KEY — only the security code.
        $this->postJson(self::URL, $this->body())->assertUnauthorized();
        $this->withHeaders(['Security-Code' => 'wrong'])->postJson(self::URL, $this->body())->assertUnauthorized();
        $this->withHeaders(['Security-Code' => self::CODE])->postJson(self::URL, $this->body())->assertOk();
    }

    public function test_it_is_refused_when_no_security_code_is_set(): void
    {
        GeneralSetting::where('field_name', 'sync_base_url_security_code')->update(['value' => '']);

        $this->withHeaders(['Security-Code' => ''])->postJson(self::URL, $this->body())->assertUnauthorized();
    }

    public function test_it_validates_the_ref_ids(): void
    {
        $this->withHeaders(['Security-Code' => self::CODE])
            ->postJson(self::URL, $this->body(['exam_term_id' => 999, 'program_id' => null, 'semester' => 'x']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exam_term_id', 'program_id', 'semester']);
    }

    public function test_it_returns_each_students_result_in_every_course(): void
    {
        $dsa = Course::factory()->create(['inserted_id' => 701, 'name' => 'Data Structures', 'code' => 'BCA301', 'type' => 'T']);
        $dbms = Course::factory()->create(['inserted_id' => 702, 'name' => 'DBMS', 'code' => 'BCA302', 'type' => 'T']);
        $dsaPacket = $this->packet($dsa, 50);
        $dbmsPacket = $this->packet($dbms, 70);
        Student::factory()->create(['inserted_id' => 9001, 'registration_no' => 'REG-1', 'name' => 'Asha Roy']);

        $this->sheet($dsaPacket, 'REG-1', 'Asha Roy', ['marks' => 42.5]);
        $this->sheet($dbmsPacket, 'REG-1', 'Asha Roy', ['marks' => 61]);
        $this->sheet($dsaPacket, 'REG-2', 'Bikash Das', ['absent' => true]);
        $this->sheet($dbmsPacket, 'REG-2', 'Bikash Das', ['issue_master_id' => 1, 'issue_status' => 'open']);
        // Other semester / other sub type — never included.
        $this->sheet($this->packet($dsa, 50, ['semester' => 5]), 'REG-9', 'Other Sem', ['marks' => 10]);
        $this->sheet($this->packet($dsa, 50, ['exam_sub_type_id' => ExamSubType::factory()->create(['inserted_id' => 99])->id]), 'REG-8', 'Other Session', ['marks' => 10]);

        $data = $this->withHeaders(['Security-Code' => self::CODE])->postJson(self::URL, $this->body())->assertOk()->json('data');

        $this->assertSame(['ref_id' => 501, 'name' => 'B.C.A.'], $data['program']);
        $this->assertSame(3, $data['semester']);
        $this->assertFalse($data['is_complete']);
        $this->assertSame(['students' => 2, 'courses' => 2, 'evaluated' => 2, 'absent' => 1, 'pending' => 0, 'problem' => 1], $data['summary']);
        $this->assertSame([701, 702], array_column($data['courses'], 'course_ref_id'));
        $this->assertSame([50, 70], array_column($data['courses'], 'full_marks'));

        [$asha, $bikash] = $data['students'];
        $this->assertSame(['student_ref_id' => 9001, 'name' => 'Asha Roy', 'registration_no' => 'REG-1', 'roll_no' => 'REG-1'], array_diff_key($asha, ['results' => 1]));
        $this->assertEquals([42.5, 61], array_column($asha['results'], 'marks'));
        $this->assertSame(['evaluated', 'evaluated'], array_column($asha['results'], 'status'));
        $this->assertSame('BCA301', $asha['results'][0]['course_code']);
        $this->assertNull($bikash['student_ref_id']);
        // Not evaluated → 0 marks, never null; the status says why.
        $this->assertSame([0, 0], array_column($bikash['results'], 'marks'));
        $this->assertSame(['absent', 'problem'], array_column($bikash['results'], 'status'));
    }

    public function test_it_can_be_narrowed_to_one_course_and_or_one_student(): void
    {
        $dsa = Course::factory()->create(['inserted_id' => 701, 'name' => 'Data Structures']);
        $dbms = Course::factory()->create(['inserted_id' => 702, 'name' => 'DBMS']);
        $dsaPacket = $this->packet($dsa);
        $dbmsPacket = $this->packet($dbms);
        foreach (['REG-1' => 'Asha Roy', 'REG-2' => 'Bikash Das'] as $regNo => $name) {
            $this->sheet($dsaPacket, $regNo, $name, ['marks' => 30]);
            $this->sheet($dbmsPacket, $regNo, $name, ['marks' => 40]);
        }
        $post = fn (array $filters) => $this->withHeaders(['Security-Code' => self::CODE])->postJson(self::URL, $this->body($filters))->assertOk()->json('data');

        // One course: every student, only that course.
        $data = $post(['course_id' => 702]);
        $this->assertSame(['ref_id' => 702, 'name' => 'DBMS'], $data['course']);
        $this->assertSame(['REG-1', 'REG-2'], array_column($data['students'], 'registration_no'));
        $this->assertSame([[702], [702]], array_map(fn ($s) => array_column($s['results'], 'course_ref_id'), $data['students']));

        // One student: every course of theirs.
        $data = $post(['registration_no' => 'REG-2']);
        $this->assertNull($data['course']);
        $this->assertSame('REG-2', $data['registration_no']);
        $this->assertCount(1, $data['students']);
        $this->assertSame([701, 702], array_column($data['students'][0]['results'], 'course_ref_id'));

        // Both.
        $data = $post(['course_id' => 701, 'registration_no' => 'REG-1']);
        $this->assertSame(['students' => 1, 'courses' => 1], array_intersect_key($data['summary'], ['students' => 1, 'courses' => 1]));

        // A course with no sheets in this search is just an empty answer; an unknown Ref Id is a 422.
        Course::factory()->create(['inserted_id' => 799]);
        $this->assertSame([], $post(['course_id' => 799])['students']);
        $this->withHeaders(['Security-Code' => self::CODE])->postJson(self::URL, $this->body(['course_id' => 123456]))
            ->assertUnprocessable()->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_can_be_narrowed_to_one_department(): void
    {
        $course = Course::factory()->create(['inserted_id' => 701]);
        $cse = Department::factory()->create(['inserted_id' => 41, 'name' => 'Computer Applications']);
        $other = Department::factory()->create(['inserted_id' => 42]);
        $this->sheet($this->packet($course, 50, ['department_id' => $cse->id]), 'REG-1', 'Asha Roy', ['marks' => 30]);
        $this->sheet($this->packet($course, 50, ['department_id' => $other->id]), 'REG-2', 'Bikash Das', ['marks' => 40]);
        $post = fn (array $filters) => $this->withHeaders(['Security-Code' => self::CODE])->postJson(self::URL, $this->body($filters));

        $data = $post(['department_id' => 41])->assertOk()->json('data');
        $this->assertSame(['ref_id' => 41, 'name' => 'Computer Applications'], $data['department']);
        $this->assertSame(['REG-1'], array_column($data['students'], 'registration_no'));

        // Not sent: every department.
        $data = $post([])->assertOk()->json('data');
        $this->assertNull($data['department']);
        $this->assertCount(2, $data['students']);

        $post(['department_id' => 999])->assertUnprocessable()->assertJsonValidationErrors(['department_id']);
    }

    public function test_is_complete_once_nothing_is_left_to_evaluate(): void
    {
        $course = Course::factory()->create(['inserted_id' => 701]);
        $packet = $this->packet($course);
        $this->sheet($packet, 'REG-1', 'Asha Roy', ['marks' => 40]);
        $this->sheet($packet, 'REG-2', 'Bikash Das', ['absent' => true]);

        $this->withHeaders(['Security-Code' => self::CODE])->postJson(self::URL, $this->body())
            ->assertOk()->assertJsonPath('data.is_complete', true);
    }

    public function test_no_results_is_an_empty_answer_not_an_error(): void
    {
        $this->withHeaders(['Security-Code' => self::CODE])->postJson(self::URL, $this->body())
            ->assertOk()
            ->assertJsonPath('data.students', [])
            ->assertJsonPath('data.is_complete', false)
            ->assertJsonPath('message', 'No results found for this search.');
    }
}
