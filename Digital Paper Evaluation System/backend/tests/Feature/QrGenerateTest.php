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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QrGenerateTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://college.test/api/V1/DataApi/examStudent';

    /** What the fake API returns — changeable within a test. */
    private array $apiRows = [];

    private ExamTerm $term;

    private ExamType $type;

    private ExamSubType $subType;

    private Program $program;

    private Course $course;

    private Department $department;

    private QuestionPaper $paper;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qr.start_point' => 10000000]);
        Http::fake([self::URL.'*' => fn () => Http::response(['status' => true, 'data' => $this->apiRows])]);
        Sanctum::actingAs(User::factory()->create());
        foreach ([
            'sync_base_url' => 'https://college.test/api/V1',
            'sync_base_url_security_code' => 'secret-code',
            'is_need_api_from_main_db' => 'yes',
        ] as $field => $value) {
            GeneralSetting::updateOrCreate(['field_name' => $field], GeneralSetting::factory()->make(['field_name' => $field, 'value' => $value, 'status' => true])->toArray());
        }

        // Our ids deliberately differ from the Ref Ids.
        ExamTerm::factory()->count(3)->create();
        $this->term = ExamTerm::create(['inserted_id' => 23, 'name' => 'Odd 2025', 'status' => true]);
        $this->type = ExamType::create(['inserted_id' => 14, 'name' => 'End Sem', 'status' => true]);
        $this->subType = ExamSubType::create(['inserted_id' => 1, 'name' => 'Spring 2026', 'exam_type_id' => $this->type->id, 'exam_term_id' => $this->term->id, 'status' => true]);
        $this->program = Program::create(['inserted_id' => 77, 'name' => 'BCA', 'label' => 'UG', 'department' => '', 'code' => '', 'status' => true]);
        $this->course = Course::create(['inserted_id' => 2670, 'name' => 'AI', 'code' => 'TIU-AI', 'type' => 'Theory', 'status' => true]);
        $this->program->courses()->attach($this->course);
        $this->department = Department::create(['inserted_id' => 64, 'name' => 'Computer Applications', 'status' => true]);
        $this->paper = QuestionPaper::factory()->create(['course_id' => $this->course->id, 'semester' => 4, 'exam_term_id' => $this->term->id, 'exam_year' => 2026, 'status' => 'ready']);
        $this->paper->departments()->attach($this->department);

        $this->apiRows = [$this->row('241005004004', 'TANOY PURKAIT'), $this->row('241005004237', 'Aakriti Thakur')];
    }

    private function row(string $registration, string $name, array $overrides = []): array
    {
        return array_merge([
            'student_id' => '1', 'regisration_id' => $registration, 'name' => $name, 'program_id' => '77', 'course_id' => '2670',
            'course_name' => 'AI', 'course_code' => 'TIU-AI', 'exam_type_id' => '14', 'exam_term_id' => '23', 'enroll_session_id' => '1',
            'exam_year' => '2025-2026', 'department_id' => '64', 'batch' => '2024', 'semester' => '4', 'delete_status' => '1',
        ], $overrides);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'exam_term_id' => 23, 'exam_type_id' => 14, 'enroll_session_id' => 1, 'program_id' => 77, 'course_id' => 2670, 'semester' => 4,
            'department_id' => 64, 'exam_year' => '2025-2026', 'batch' => '2024', 'question_paper_id' => $this->paper->id, 'packet_code' => 'PKT-1',
        ], $overrides);
    }

    public function test_modal_fields_are_required(): void
    {
        $this->withApiKey()->postJson('/api/v1/qr/generate', $this->payload(['department_id' => null, 'exam_year' => '', 'batch' => '', 'question_paper_id' => null, 'packet_code' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['department_id', 'exam_year', 'batch', 'question_paper_id', 'packet_code']);
        $this->assertDatabaseCount('question_answer_sheet_mappings', 0);
    }

    public function test_question_paper_must_match_course_semester_and_department(): void
    {
        $other = QuestionPaper::factory()->create(['semester' => 4, 'exam_term_id' => $this->term->id, 'status' => 'ready']);
        $other->departments()->attach($this->department);

        $this->withApiKey()->getJson('/api/v1/qr/question-papers?course_id=2670&semester=4&exam_term_id=23&department_id=64')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->paper->id);

        $this->withApiKey()->postJson('/api/v1/qr/generate', $this->payload(['question_paper_id' => $other->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_paper_id');
    }

    public function test_generate_stores_our_ids_and_numbers_qr_codes_from_the_start_point(): void
    {
        $this->withApiKey()->postJson('/api/v1/qr/generate', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.first_qr', '10000000')
            ->assertJsonPath('data.last_qr', '10000001');

        // Our own ids — not the Ref Ids.
        $this->assertDatabaseHas('question_answer_sheet_mappings', [
            'question_paper_id' => $this->paper->id, 'course_id' => $this->course->id, 'semester' => 4,
            'exam_term_id' => $this->term->id, 'exam_type_id' => $this->type->id, 'exam_sub_type_id' => $this->subType->id,
            'program_name' => 'BCA', 'department_id' => $this->department->id, 'department_name' => 'Computer Applications',
            'exam_year' => '2025-2026', 'batch' => '2024', 'packet_code' => 'PKT-1', 'is_scan_complete' => 0,
        ]);
        $mapping = QuestionAnswerSheetMapping::firstOrFail();
        $this->assertDatabaseHas('answer_sheets', ['question_answer_sheet_mapping_id' => $mapping->id, 'subject_barcode' => '10000000', 'registration_no' => '241005004004', 'roll_no' => '241005004004', 'name' => 'TANOY PURKAIT', 'subject_code' => 'TIU-AI', 'semester' => 4]);
        $this->assertDatabaseHas('answer_sheets', ['subject_barcode' => '10000001', 'registration_no' => '241005004237']);
    }

    public function test_generating_again_adds_only_new_students_to_the_same_packet(): void
    {
        $this->withApiKey()->postJson('/api/v1/qr/generate', $this->payload())->assertStatus(201);

        $this->apiRows[] = $this->row('241005004089', 'Abdul Kabir');
        $this->withApiKey()->postJson('/api/v1/qr/generate', $this->payload(['packet_code' => 'PKT-2']))
            ->assertStatus(201)
            ->assertJsonPath('data.existing_packet', true)
            ->assertJsonPath('data.packet_code', 'PKT-1')
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.already_generated', 2)
            ->assertJsonPath('data.first_qr', '10000002');

        $this->assertDatabaseCount('question_answer_sheet_mappings', 1);
        $this->assertSame(3, AnswerSheet::count());

        // Nothing new → nothing inserted.
        $this->withApiKey()->postJson('/api/v1/qr/generate', $this->payload())
            ->assertOk()->assertJsonPath('data.created', 0);
        $this->assertSame(3, AnswerSheet::count());
    }

    public function test_numbers_continue_across_packets_and_skip_codes_already_used(): void
    {
        $this->withApiKey()->postJson('/api/v1/qr/generate', $this->payload())->assertStatus(201);

        // An uploaded sheet already holds the next number → skipped.
        AnswerSheet::factory()->create(['subject_barcode' => '10000002']);

        $this->apiRows = [$this->row('9001', 'Other Batch', ['batch' => '2025'])];
        $this->withApiKey()->postJson('/api/v1/qr/generate', $this->payload(['batch' => '2025', 'packet_code' => 'PKT-B']))
            ->assertStatus(201)
            ->assertJsonPath('data.existing_packet', false)
            ->assertJsonPath('data.first_qr', '10000003');

        $this->assertSame(10000003, (int) DB::table('qr_code_counters')->value('last_value'));
    }

    public function test_a_generated_packet_is_on_the_scan_papers_list_not_the_answer_sheets_list(): void
    {
        $uploaded = QuestionAnswerSheetMapping::factory()->create();
        $this->withApiKey()->postJson('/api/v1/qr/generate', $this->payload())->assertStatus(201);
        $generated = QuestionAnswerSheetMapping::where('packet_code', 'PKT-1')->firstOrFail();

        // Answer Sheets (default): scanned only.
        $this->withApiKey()->getJson('/api/v1/answer-sheet-mappings')
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $uploaded->id);

        // Scan Papers: not scanned yet.
        $this->withApiKey()->getJson('/api/v1/answer-sheet-mappings?is_scan_complete=0')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $generated->id)
            ->assertJsonPath('data.items.0.is_scan_complete', false)
            ->assertJsonPath('data.items.0.batch', '2024')
            ->assertJsonPath('data.items.0.packet_exam_year', '2025-2026')
            ->assertJsonPath('data.items.0.exam_sub_type_name', 'Spring 2026')
            ->assertJsonPath('data.items.0.answer_sheet_count', 2)
            ->assertJsonPath('data.items.0.pending_answer_sheet_count', 0);
    }

    public function test_print_returns_the_packets_qr_numbers_and_students_in_order(): void
    {
        $this->withApiKey()->postJson('/api/v1/qr/generate', $this->payload())->assertStatus(201);
        $mapping = QuestionAnswerSheetMapping::firstOrFail();

        $this->withApiKey()->getJson("/api/v1/qr/packets/{$mapping->id}/codes")
            ->assertOk()
            ->assertJsonPath('data.packet.packet_code', 'PKT-1')
            ->assertJsonPath('data.packet.batch', '2024')
            ->assertJsonPath('data.packet.exam_sub_type_name', 'Spring 2026')
            ->assertJsonPath('data.codes', [
                ['qr_code' => '10000000', 'registration_no' => '241005004004', 'name' => 'TANOY PURKAIT'],
                ['qr_code' => '10000001', 'registration_no' => '241005004237', 'name' => 'Aakriti Thakur'],
            ]);
    }

    public function test_only_students_of_the_chosen_department_and_batch_are_added(): void
    {
        $this->apiRows[] = $this->row('5555', 'Other Department', ['department_id' => '65']);
        $this->apiRows[] = $this->row('6666', 'Other Batch', ['batch' => '2023']);

        $this->withApiKey()->postJson('/api/v1/qr/generate', $this->payload())
            ->assertStatus(201)->assertJsonPath('data.created', 2);
        $this->assertDatabaseMissing('answer_sheets', ['registration_no' => '5555']);
        $this->assertDatabaseMissing('answer_sheets', ['registration_no' => '6666']);
    }
}
