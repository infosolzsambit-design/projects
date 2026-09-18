<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\Department;
use App\Models\ExamType;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\TeacherDetail;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        // TeacherController::store() always assigns the Teacher role (looked
        // up by name), so that role has to actually exist — RefreshDatabase
        // only migrates the schema, it doesn't seed it.
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create();
        // Super Admin (already seeded above, at its well-known id) so the
        // ?has_assignments=yes tests below aren't exam-year-scoped (see
        // HasExamYearScope) regardless of what year their fixtures happen
        // to land on.
        $admin->assignRole((int) ((array) config('roles.super_admin_id'))[0]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_a_teacher(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create(['name' => 'Computer Science']);

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Rahul Kumar',
            'email' => 'rahul.kumar@example.com',
            'phone_no' => '9876500001',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0001',
            'department_id' => $department->id,
            'designation' => 'Assistant Professor',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Rahul Kumar')
            ->assertJsonPath('data.email', 'rahul.kumar@example.com')
            ->assertJsonPath('data.emp_code', 'EMP-0001')
            ->assertJsonPath('data.department', 'Computer Science')
            ->assertJsonPath('data.department_id', $department->id)
            ->assertJsonPath('data.designation', 'Assistant Professor')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('users', ['email' => 'rahul.kumar@example.com']);
        $this->assertDatabaseHas('teacher_details', [
            'emp_code' => 'EMP-0001',
            'department' => 'Computer Science',
            'department_id' => $department->id,
            'designation' => 'Assistant Professor',
        ]);
    }

    public function test_creating_a_teacher_auto_assigns_the_teacher_role(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Priya Singh',
            'email' => 'priya.singh@example.com',
            'phone_no' => '9876500002',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0002',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'priya.singh@example.com')->first();
        $this->assertTrue($user->hasRole('Teacher'));
    }

    public function test_creating_a_teacher_generates_a_unique_username_from_the_name(): void
    {
        $this->actingAdmin();
        User::factory()->create(['username' => 'amit_verma']);
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Amit Verma',
            'email' => 'amit.verma@example.com',
            'phone_no' => '9876500003',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0003',
            'department_id' => $department->id,
            'designation' => 'Professor',
        ]);

        $response->assertStatus(201);
        $user = User::where('email', 'amit.verma@example.com')->first();
        $this->assertSame('amit_verma_2', $user->username);
    }

    public function test_emp_code_is_required(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'No Emp Code',
            'email' => 'no.empcode@example.com',
            'phone_no' => '9876500024',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('emp_code');
    }

    public function test_emp_code_must_be_unique(): void
    {
        $this->actingAdmin();
        TeacherDetail::factory()->create(['emp_code' => 'EMP-DUPE']);
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Duplicate Emp Code',
            'email' => 'dup.empcode@example.com',
            'phone_no' => '9876500025',
            'emp_code' => 'EMP-DUPE',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('emp_code');
    }

    public function test_emp_code_matching_a_soft_deleted_teachers_is_allowed(): void
    {
        $this->actingAdmin();
        $old = TeacherDetail::factory()->create(['emp_code' => 'EMP-REUSE']);
        $old->user->delete();
        $old->delete();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Reused Emp Code',
            'email' => 'reused.empcode@example.com',
            'phone_no' => '9876500026',
            'emp_code' => 'EMP-REUSE',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(201);
    }

    public function test_department_id_is_required(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'No Department',
            'email' => 'no.department@example.com',
            'phone_no' => '9876500027',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0004',
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_department_id_must_reference_an_existing_non_deleted_department(): void
    {
        $this->actingAdmin();
        $trashedDepartment = Department::factory()->create();
        $trashedDepartment->delete();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Bad Department',
            'email' => 'bad.department@example.com',
            'phone_no' => '9876500023',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0005',
            'department_id' => $trashedDepartment->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_designation_is_required(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'No Designation',
            'email' => 'no.designation@example.com',
            'phone_no' => '9876500028',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0006',
            'department_id' => $department->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('designation');
    }

    public function test_email_must_be_unique(): void
    {
        $this->actingAdmin();
        User::factory()->create(['email' => 'dup@example.com']);
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Duplicate Email',
            'email' => 'dup@example.com',
            'phone_no' => '9876500029',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0007',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_teacher_can_be_created_with_no_password(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'No Password',
            'email' => 'no.password@example.com',
            'phone_no' => '9876500020',
            'emp_code' => 'EMP-0008',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(201);
        $user = User::where('email', 'no.password@example.com')->first();
        $this->assertNull($user->password);
    }

    public function test_a_password_given_at_creation_must_still_meet_the_policy(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Weak Password',
            'email' => 'weak.password@example.com',
            'phone_no' => '9876500021',
            'password' => 'weak',
            'password_confirmation' => 'weak',
            'emp_code' => 'EMP-0009',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_teacher_with_no_password_cannot_log_in(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();
        $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'No Password Login',
            'email' => 'no.password.login@example.com',
            'phone_no' => '9876500022',
            'emp_code' => 'EMP-0010',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => 'no.password.login@example.com',
            'password' => 'AnythingAtAll!23',
        ]);

        // Same generic message as any other failed login — Hash::check()
        // against a null hash just returns false, it doesn't error.
        $response->assertStatus(401);
    }

    public function test_phone_no_is_required(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'No Phone',
            'email' => 'no.phone@example.com',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0011',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('phone_no');
    }

    public function test_phone_no_must_be_exactly_10_digits(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Short Phone',
            'email' => 'short.phone@example.com',
            'phone_no' => '98765',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0012',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('phone_no');
    }

    /**
     * digits:10 rejects a decimal point (or any other non-digit character)
     * the same way it rejects letters — it's not just a length check.
     */
    public function test_phone_no_rejects_a_decimal_point(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Dotted Phone',
            'email' => 'dotted.phone@example.com',
            'phone_no' => '98765.0001',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0013',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('phone_no');
    }

    public function test_phone_no_must_be_unique(): void
    {
        $this->actingAdmin();
        User::factory()->create(['phone_no' => '9876500009']);
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Duplicate Phone',
            'email' => 'duplicate.phone@example.com',
            'phone_no' => '9876500009',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0014',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('phone_no');
    }

    public function test_phone_no_matching_a_soft_deleted_users_is_allowed(): void
    {
        $this->actingAdmin();
        $old = User::factory()->create(['phone_no' => '9876500010']);
        $old->delete();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Reused Phone',
            'email' => 'reused.phone@example.com',
            'phone_no' => '9876500010',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0015',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(201);
    }

    public function test_email_matching_a_soft_deleted_users_is_allowed(): void
    {
        $this->actingAdmin();
        $old = User::factory()->create(['email' => 'reused.email@example.com']);
        $old->delete();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers', [
            'name' => 'Reused Email',
            'email' => 'reused.email@example.com',
            'phone_no' => '9876500011',
            'password' => 'Password!23',
            'password_confirmation' => 'Password!23',
            'emp_code' => 'EMP-0016',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(201);
    }

    public function test_admin_can_update_a_teacher(): void
    {
        $this->actingAdmin();
        $physics = Department::factory()->create(['name' => 'Physics']);
        $chemistry = Department::factory()->create(['name' => 'Chemistry']);
        $detail = TeacherDetail::factory()->create(['department' => 'Physics', 'department_id' => $physics->id]);

        $response = $this->withApiKey()->putJson("/api/v1/teachers/{$detail->user_id}", [
            'department_id' => $chemistry->id,
            'designation' => 'Senior Lecturer',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.department', 'Chemistry')
            ->assertJsonPath('data.department_id', $chemistry->id)
            ->assertJsonPath('data.designation', 'Senior Lecturer');
    }

    public function test_admin_can_update_a_teachers_emp_code(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create(['emp_code' => 'EMP-OLD']);

        $response = $this->withApiKey()->putJson("/api/v1/teachers/{$detail->user_id}", [
            'emp_code' => 'EMP-NEW',
        ]);

        $response->assertOk()->assertJsonPath('data.emp_code', 'EMP-NEW');
        $this->assertDatabaseHas('teacher_details', ['id' => $detail->id, 'emp_code' => 'EMP-NEW']);
    }

    public function test_updating_a_teachers_emp_code_to_one_already_taken_is_rejected(): void
    {
        $this->actingAdmin();
        TeacherDetail::factory()->create(['emp_code' => 'EMP-TAKEN']);
        $detail = TeacherDetail::factory()->create(['emp_code' => 'EMP-MINE']);

        $response = $this->withApiKey()->putJson("/api/v1/teachers/{$detail->user_id}", [
            'emp_code' => 'EMP-TAKEN',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('emp_code');
    }

    public function test_admin_can_toggle_a_teachers_active_status(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();
        $detail->user->update(['is_active' => true]);

        $response = $this->withApiKey()->putJson("/api/v1/teachers/{$detail->user_id}", [
            'is_active' => false,
        ]);

        $response->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('users', ['id' => $detail->user_id, 'is_active' => false]);
    }

    public function test_a_new_teachers_face_scan_applicable_defaults_to_true(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/teachers?id={$detail->user_id}");

        $response->assertOk()->assertJsonPath('data.face_scan_applicable', true);
    }

    public function test_admin_can_toggle_a_teachers_face_scan_applicable_flag(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $response = $this->withApiKey()->putJson("/api/v1/teachers/{$detail->user_id}", [
            'face_scan_applicable' => false,
        ]);

        $response->assertOk()->assertJsonPath('data.face_scan_applicable', false);
        $this->assertDatabaseHas('teacher_details', ['id' => $detail->id, 'face_scan_applicable' => false]);

        $response = $this->withApiKey()->putJson("/api/v1/teachers/{$detail->user_id}", [
            'face_scan_applicable' => true,
        ]);

        $response->assertOk()->assertJsonPath('data.face_scan_applicable', true);
        $this->assertDatabaseHas('teacher_details', ['id' => $detail->id, 'face_scan_applicable' => true]);
    }

    public function test_admin_can_soft_delete_a_teacher(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/teachers/{$detail->user_id}");

        $response->assertOk();
        $this->assertSoftDeleted('users', ['id' => $detail->user_id]);
        $this->assertSoftDeleted('teacher_details', ['id' => $detail->id]);
    }

    public function test_soft_deleted_teacher_is_excluded_from_default_listing(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();
        $detail->user->delete();
        $detail->delete();

        $response = $this->withApiKey()->getJson('/api/v1/teachers?per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($detail->user_id, $ids);
    }

    public function test_index_filters_by_department_id(): void
    {
        $this->actingAdmin();
        $cs = Department::factory()->create();
        $math = Department::factory()->create();
        $csTeacher = TeacherDetail::factory()->create(['department_id' => $cs->id]);
        TeacherDetail::factory()->create(['department_id' => $math->id]);

        $response = $this->withApiKey()->getJson("/api/v1/teachers?department_id={$cs->id}");

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertEquals([$csTeacher->user_id], $ids);
    }

    /**
     * Backs AssignTeacherView.vue's teacher picker / AssignedTeachersView
     * .vue — a non-super-admin caller only ever sees teachers in their
     * OWN department (resolved from their own teacher_details row) when
     * ?department_scope=self is sent, regardless of ?department_id=.
     */
    public function test_index_department_scope_self_restricts_a_non_super_admin_to_their_own_department(): void
    {
        $cs = Department::factory()->create();
        $math = Department::factory()->create();

        $callerDetail = TeacherDetail::factory()->create(['department_id' => $cs->id]);
        Sanctum::actingAs($callerDetail->user);

        $sameDeptTeacher = TeacherDetail::factory()->create(['department_id' => $cs->id]);
        TeacherDetail::factory()->create(['department_id' => $math->id]); // must not appear

        $response = $this->withApiKey()->getJson('/api/v1/teachers?department_scope=self');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$callerDetail->user_id, $sameDeptTeacher->user_id], $ids);
    }

    public function test_index_department_scope_self_is_a_no_op_for_a_super_admin(): void
    {
        $this->actingAdmin();
        Department::factory()->create();
        TeacherDetail::factory()->count(2)->create();

        $response = $this->withApiKey()->getJson('/api/v1/teachers?department_scope=self');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_index_department_scope_self_shows_nothing_for_a_non_super_admin_with_no_department(): void
    {
        // No teacher_details row at all for the acting user — nothing to
        // scope by, so this must match nothing, never silently everyone.
        Sanctum::actingAs(User::factory()->create());
        TeacherDetail::factory()->count(2)->create();

        $response = $this->withApiKey()->getJson('/api/v1/teachers?department_scope=self');

        $response->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_index_ignores_department_scope_when_not_sent(): void
    {
        Department::factory()->create();
        $callerDetail = TeacherDetail::factory()->create();
        Sanctum::actingAs($callerDetail->user);

        TeacherDetail::factory()->count(2)->create(); // a different department each

        $response = $this->withApiKey()->getJson('/api/v1/teachers');

        $response->assertOk()->assertJsonCount(3, 'data.items');
    }

    public function test_index_filters_by_has_assignments(): void
    {
        $this->actingAdmin();
        $assigned = TeacherDetail::factory()->create();
        AnswerSheet::factory()->create(['teacher_id' => $assigned->user_id]);
        TeacherDetail::factory()->create(); // no assignments

        $response = $this->withApiKey()->getJson('/api/v1/teachers?has_assignments=yes');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertEquals([$assigned->user_id], $ids);
    }

    public function test_index_scopes_has_assignments_to_the_current_exam_year_for_a_non_super_admin(): void
    {
        $this->seed(RoleSeeder::class);
        $plainUser = User::factory()->create();
        Sanctum::actingAs($plainUser);

        // Shared across both mappings — this test only exercises the
        // exam-year scope, so both need to land inside whichever exam type
        // a non-super-admin's own default HasExamTypeScope resolves to
        // (the only/most-recently-created one here), or that scope would
        // silently filter one of them out for an unrelated reason.
        $examType = ExamType::factory()->create();
        $thisYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $lastYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year - 1]);
        $thisYearMapping = QuestionAnswerSheetMapping::factory()->create(['question_paper_id' => $thisYearPaper->id, 'exam_type_id' => $examType->id]);
        $lastYearMapping = QuestionAnswerSheetMapping::factory()->create(['question_paper_id' => $lastYearPaper->id, 'exam_type_id' => $examType->id]);

        $thisYearTeacher = TeacherDetail::factory()->create();
        AnswerSheet::factory()->create(['teacher_id' => $thisYearTeacher->user_id, 'question_answer_sheet_mapping_id' => $thisYearMapping->id]);

        $lastYearOnlyTeacher = TeacherDetail::factory()->create();
        AnswerSheet::factory()->create(['teacher_id' => $lastYearOnlyTeacher->user_id, 'question_answer_sheet_mapping_id' => $lastYearMapping->id]);

        $response = $this->withApiKey()->getJson('/api/v1/teachers?has_assignments=yes');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertEquals([$thisYearTeacher->user_id], $ids);
    }

    public function test_index_allocated_answer_sheet_count_is_scoped_to_the_current_exam_year_for_a_non_super_admin(): void
    {
        $this->seed(RoleSeeder::class);
        $plainUser = User::factory()->create();
        Sanctum::actingAs($plainUser);

        // Same reasoning as the test above — both mappings share one exam
        // type so only the exam-year scope is actually being exercised here.
        $examType = ExamType::factory()->create();
        $thisYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $lastYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year - 1]);
        $thisYearMapping = QuestionAnswerSheetMapping::factory()->create(['question_paper_id' => $thisYearPaper->id, 'exam_type_id' => $examType->id]);
        $lastYearMapping = QuestionAnswerSheetMapping::factory()->create(['question_paper_id' => $lastYearPaper->id, 'exam_type_id' => $examType->id]);

        $teacher = TeacherDetail::factory()->create();
        AnswerSheet::factory()->count(2)->create(['teacher_id' => $teacher->user_id, 'question_answer_sheet_mapping_id' => $thisYearMapping->id]);
        AnswerSheet::factory()->count(5)->create(['teacher_id' => $teacher->user_id, 'question_answer_sheet_mapping_id' => $lastYearMapping->id]);

        $response = $this->withApiKey()->getJson('/api/v1/teachers?has_assignments=yes');

        $response->assertOk();
        $row = collect($response->json('data.items'))->firstWhere('id', $teacher->user_id);
        $this->assertSame(2, $row['allocated_answer_sheet_count']);
    }

    /**
     * "Allocated Answer Sheets" is every sheet ever handed to this
     * teacher, completed or not; "completed_answer_sheet_count" is just
     * the subset of those with marks already recorded — shown as its own
     * column on the Assigned Teacher List.
     */
    public function test_index_includes_each_teachers_completed_answer_sheet_count_alongside_the_total(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();
        AnswerSheet::factory()->count(2)->create(['teacher_id' => $detail->user_id, 'marks' => null]);
        AnswerSheet::factory()->count(3)->create(['teacher_id' => $detail->user_id, 'marks' => 12]);

        $response = $this->withApiKey()->getJson("/api/v1/teachers?id={$detail->user_id}");

        $response->assertOk();
        $response->assertJsonPath('data.allocated_answer_sheet_count', 5);
        $response->assertJsonPath('data.completed_answer_sheet_count', 3);
    }

    public function test_admin_can_restore_a_soft_deleted_teacher(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();
        $detail->user->delete();
        $detail->delete();

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$detail->user_id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $detail->user_id, 'deleted_at' => null]);
        $this->assertDatabaseHas('teacher_details', ['id' => $detail->id, 'deleted_at' => null]);
    }

    public function test_a_plain_user_with_no_teacher_detail_is_not_returned_as_a_teacher(): void
    {
        $this->actingAdmin();
        $plainUser = User::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/teachers?id={$plainUser->id}");

        $response->assertStatus(404);
    }

    public function test_index_includes_each_teachers_allocated_answer_sheet_count(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();
        AnswerSheet::factory()->count(3)->create(['teacher_id' => $detail->user_id]);

        $response = $this->withApiKey()->getJson("/api/v1/teachers?id={$detail->user_id}");

        $response->assertOk();
        $response->assertJsonPath('data.allocated_answer_sheet_count', 3);
    }

    public function test_assignments_breaks_a_teachers_allocation_down_by_packet(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $mappingA = QuestionAnswerSheetMapping::factory()->create();
        $mappingB = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->count(2)->create([
            'teacher_id' => $detail->user_id,
            'question_answer_sheet_mapping_id' => $mappingA->id,
        ]);
        AnswerSheet::factory()->count(5)->create([
            'teacher_id' => $detail->user_id,
            'question_answer_sheet_mapping_id' => $mappingB->id,
        ]);
        // Belongs to a different teacher — must not leak into this one's total.
        AnswerSheet::factory()->create([
            'teacher_id' => TeacherDetail::factory()->create()->user_id,
            'question_answer_sheet_mapping_id' => $mappingA->id,
        ]);

        $response = $this->withApiKey()->getJson("/api/v1/teachers/{$detail->user_id}/assignments");

        $response->assertOk();
        $response->assertJsonPath('data.total', 7);
        $this->assertCount(2, $response->json('data.breakdown'));
        $this->assertEqualsCanonicalizing(
            [2, 5],
            collect($response->json('data.breakdown'))->pluck('sheet_count')->all(),
        );
    }

    public function test_assignments_breaks_each_packets_count_down_by_completed_draft_and_untouched(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();
        $mapping = QuestionAnswerSheetMapping::factory()->create();

        AnswerSheet::factory()->create(['teacher_id' => $detail->user_id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => 15]);
        AnswerSheet::factory()->create(['teacher_id' => $detail->user_id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null, 'draft_marks' => 0]);
        AnswerSheet::factory()->count(3)->create(['teacher_id' => $detail->user_id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null, 'draft_marks' => null]);

        $response = $this->withApiKey()->getJson("/api/v1/teachers/{$detail->user_id}/assignments");

        $response->assertOk();
        $row = $response->json('data.breakdown.0');
        $this->assertSame(5, $row['sheet_count']);
        $this->assertSame(1, $row['completed_count']);
        $this->assertSame(1, $row['draft_count']);
    }

    public function test_assignments_returns_not_found_for_a_non_teacher(): void
    {
        $this->actingAdmin();
        $plainUser = User::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/teachers/{$plainUser->id}/assignments");

        $response->assertStatus(404);
    }

    public function test_index_includes_each_teachers_allocated_course_count(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();
        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();
        // Two packets for the same course must still count as one course.
        AnswerSheet::factory()->count(2)->create([
            'teacher_id' => $detail->user_id,
            'question_answer_sheet_mapping_id' => QuestionAnswerSheetMapping::factory()->create(['course_id' => $courseA->id])->id,
        ]);
        AnswerSheet::factory()->create([
            'teacher_id' => $detail->user_id,
            'question_answer_sheet_mapping_id' => QuestionAnswerSheetMapping::factory()->create(['course_id' => $courseA->id])->id,
        ]);
        AnswerSheet::factory()->create([
            'teacher_id' => $detail->user_id,
            'question_answer_sheet_mapping_id' => QuestionAnswerSheetMapping::factory()->create(['course_id' => $courseB->id])->id,
        ]);

        $response = $this->withApiKey()->getJson("/api/v1/teachers?id={$detail->user_id}");

        $response->assertOk();
        $response->assertJsonPath('data.allocated_course_count', 2);
    }

    public function test_courses_lists_the_distinct_courses_a_teacher_has_sheets_in(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();
        $courseA = Course::factory()->create(['name' => 'Data Structures']);
        $courseB = Course::factory()->create(['name' => 'Operating Systems']);
        $regular = ExamType::factory()->create(['name' => 'Regular']);
        $backlog = ExamType::factory()->create(['name' => 'Backlog']);
        // Course A spans two packets with two different exam types — the
        // "Courses" list still shows it as one row, so exam_type_names
        // needs to combine both rather than only reflecting whichever
        // packet happened to be grouped first.
        $mappingA1 = QuestionAnswerSheetMapping::factory()->create(['course_id' => $courseA->id, 'exam_type_id' => $regular->id]);
        $mappingA2 = QuestionAnswerSheetMapping::factory()->create(['course_id' => $courseA->id, 'exam_type_id' => $backlog->id]);
        $mappingB = QuestionAnswerSheetMapping::factory()->create(['course_id' => $courseB->id, 'exam_type_id' => $regular->id]);

        AnswerSheet::factory()->create(['teacher_id' => $detail->user_id, 'question_answer_sheet_mapping_id' => $mappingA1->id, 'marks' => 10]);
        AnswerSheet::factory()->create(['teacher_id' => $detail->user_id, 'question_answer_sheet_mapping_id' => $mappingA2->id, 'marks' => null]);
        AnswerSheet::factory()->create(['teacher_id' => $detail->user_id, 'question_answer_sheet_mapping_id' => $mappingB->id, 'marks' => null]);
        // A different teacher's sheet for course B must not leak in.
        AnswerSheet::factory()->create([
            'teacher_id' => TeacherDetail::factory()->create()->user_id,
            'question_answer_sheet_mapping_id' => $mappingB->id,
        ]);

        $response = $this->withApiKey()->getJson("/api/v1/teachers/{$detail->user_id}/courses");

        $response->assertOk();
        $courses = collect($response->json('data.courses'))->keyBy('course_id');
        $this->assertCount(2, $courses);
        $this->assertSame(2, $courses[$courseA->id]['sheet_count']);
        $this->assertSame(1, $courses[$courseA->id]['completed_count']);
        $this->assertSame('Backlog, Regular', $courses[$courseA->id]['exam_type_names']);
        $this->assertSame(1, $courses[$courseB->id]['sheet_count']);
        $this->assertSame(0, $courses[$courseB->id]['completed_count']);
        $this->assertSame('Regular', $courses[$courseB->id]['exam_type_names']);
    }

    public function test_courses_returns_not_found_for_a_non_teacher(): void
    {
        $this->actingAdmin();
        $plainUser = User::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/teachers/{$plainUser->id}/courses");

        $response->assertStatus(404);
    }

    public function test_reassign_moves_sheets_from_one_teacher_to_another(): void
    {
        $this->actingAdmin();
        $from = TeacherDetail::factory()->create();
        $to = TeacherDetail::factory()->create();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->count(5)->create([
            'teacher_id' => $from->user_id,
            'question_answer_sheet_mapping_id' => $mapping->id,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$from->user_id}/assignments/reassign", [
            'mapping_id' => $mapping->id,
            'email_subject' => 'Answer Sheets Reassigned',
            'email_body' => 'You have been reassigned answer sheets to evaluate.',
            'reassignments' => [['teacher_id' => $to->user_id, 'quantity' => 3]],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.from_remaining', 2);
        $response->assertJsonPath('data.summary.0.reassigned_count', 3);
        $this->assertSame(2, AnswerSheet::where('teacher_id', $from->user_id)->count());
        $this->assertSame(3, AnswerSheet::where('teacher_id', $to->user_id)->count());
    }

    /**
     * Regression test: a completed evaluation (marks already set) is
     * final and must never be handed to another teacher — only the
     * not-yet-completed sheets in a packet are eligible to move.
     */
    public function test_reassign_never_moves_an_already_completed_sheet(): void
    {
        $this->actingAdmin();
        $from = TeacherDetail::factory()->create();
        $to = TeacherDetail::factory()->create();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        $completed = AnswerSheet::factory()->create([
            'teacher_id' => $from->user_id,
            'question_answer_sheet_mapping_id' => $mapping->id,
            'marks' => 18,
        ]);
        AnswerSheet::factory()->count(2)->create([
            'teacher_id' => $from->user_id,
            'question_answer_sheet_mapping_id' => $mapping->id,
            'marks' => null,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$from->user_id}/assignments/reassign", [
            'mapping_id' => $mapping->id,
            'email_subject' => 'Answer Sheets Reassigned',
            'email_body' => 'You have been reassigned answer sheets to evaluate.',
            'reassignments' => [['teacher_id' => $to->user_id, 'quantity' => 3]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['reassignments']);
        $this->assertSame($from->user_id, $completed->fresh()->teacher_id);
    }

    /**
     * Regression test: a reassigned sheet must start clean for its new
     * teacher — the previous teacher's autosaved draft marks/annotations/
     * consumed time must not carry over.
     */
    public function test_reassign_clears_draft_state_so_the_new_teacher_starts_fresh(): void
    {
        $this->actingAdmin();
        $from = TeacherDetail::factory()->create();
        $to = TeacherDetail::factory()->create();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        $sheet = AnswerSheet::factory()->create([
            'teacher_id' => $from->user_id,
            'question_answer_sheet_mapping_id' => $mapping->id,
            'marks' => null,
            'draft_marks' => 7,
            'draft_marks_breakdown' => ['1' => 4, '2' => 3],
            'draft_annotations' => ['some' => 'annotation'],
            'consumed_time' => 245,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$from->user_id}/assignments/reassign", [
            'mapping_id' => $mapping->id,
            'email_subject' => 'Answer Sheets Reassigned',
            'email_body' => 'You have been reassigned answer sheets to evaluate.',
            'reassignments' => [['teacher_id' => $to->user_id, 'quantity' => 1]],
        ]);

        $response->assertOk();
        $sheet->refresh();
        $this->assertSame($to->user_id, $sheet->teacher_id);
        $this->assertNull($sheet->draft_marks);
        $this->assertNull($sheet->draft_marks_breakdown);
        $this->assertNull($sheet->draft_annotations);
        $this->assertNull($sheet->consumed_time);
    }

    public function test_reassign_can_move_every_sheet_at_once(): void
    {
        $this->actingAdmin();
        $from = TeacherDetail::factory()->create();
        $to = TeacherDetail::factory()->create();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->count(4)->create([
            'teacher_id' => $from->user_id,
            'question_answer_sheet_mapping_id' => $mapping->id,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$from->user_id}/assignments/reassign", [
            'mapping_id' => $mapping->id,
            'email_subject' => 'Answer Sheets Reassigned',
            'email_body' => 'You have been reassigned answer sheets to evaluate.',
            'reassignments' => [['teacher_id' => $to->user_id, 'quantity' => 4]],
        ]);

        $response->assertOk();
        $this->assertSame(0, AnswerSheet::where('teacher_id', $from->user_id)->count());
        $this->assertSame(4, AnswerSheet::where('teacher_id', $to->user_id)->count());
    }

    public function test_reassign_can_split_across_multiple_teachers_at_once(): void
    {
        $this->actingAdmin();
        $from = TeacherDetail::factory()->create();
        $toA = TeacherDetail::factory()->create();
        $toB = TeacherDetail::factory()->create();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->count(7)->create([
            'teacher_id' => $from->user_id,
            'question_answer_sheet_mapping_id' => $mapping->id,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$from->user_id}/assignments/reassign", [
            'mapping_id' => $mapping->id,
            'email_subject' => 'Answer Sheets Reassigned',
            'email_body' => 'You have been reassigned answer sheets to evaluate.',
            'reassignments' => [
                ['teacher_id' => $toA->user_id, 'quantity' => 4],
                ['teacher_id' => $toB->user_id, 'quantity' => 3],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.from_remaining', 0);
        $this->assertSame(0, AnswerSheet::where('teacher_id', $from->user_id)->count());
        $this->assertSame(4, AnswerSheet::where('teacher_id', $toA->user_id)->count());
        $this->assertSame(3, AnswerSheet::where('teacher_id', $toB->user_id)->count());
    }

    public function test_reassign_rejects_more_than_the_teacher_actually_has_in_that_packet(): void
    {
        $this->actingAdmin();
        $from = TeacherDetail::factory()->create();
        $to = TeacherDetail::factory()->create();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->count(2)->create([
            'teacher_id' => $from->user_id,
            'question_answer_sheet_mapping_id' => $mapping->id,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$from->user_id}/assignments/reassign", [
            'mapping_id' => $mapping->id,
            'email_subject' => 'Answer Sheets Reassigned',
            'email_body' => 'You have been reassigned answer sheets to evaluate.',
            'reassignments' => [['teacher_id' => $to->user_id, 'quantity' => 5]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(2, AnswerSheet::where('teacher_id', $from->user_id)->count());
    }

    public function test_reassign_rejects_reassigning_to_the_same_teacher(): void
    {
        $this->actingAdmin();
        $from = TeacherDetail::factory()->create();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->create([
            'teacher_id' => $from->user_id,
            'question_answer_sheet_mapping_id' => $mapping->id,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$from->user_id}/assignments/reassign", [
            'mapping_id' => $mapping->id,
            'email_subject' => 'Answer Sheets Reassigned',
            'email_body' => 'You have been reassigned answer sheets to evaluate.',
            'reassignments' => [['teacher_id' => $from->user_id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reassignments']);
    }

    public function test_reassign_rejects_a_to_teacher_id_that_is_not_a_real_teacher(): void
    {
        $this->actingAdmin();
        $from = TeacherDetail::factory()->create();
        $notATeacher = User::factory()->create();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->create([
            'teacher_id' => $from->user_id,
            'question_answer_sheet_mapping_id' => $mapping->id,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$from->user_id}/assignments/reassign", [
            'mapping_id' => $mapping->id,
            'email_subject' => 'Answer Sheets Reassigned',
            'email_body' => 'You have been reassigned answer sheets to evaluate.',
            'reassignments' => [['teacher_id' => $notATeacher->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reassignments.0.teacher_id']);
    }

    public function test_reassign_requires_email_subject_and_body(): void
    {
        $this->actingAdmin();
        $from = TeacherDetail::factory()->create();
        $to = TeacherDetail::factory()->create();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->create([
            'teacher_id' => $from->user_id,
            'question_answer_sheet_mapping_id' => $mapping->id,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$from->user_id}/assignments/reassign", [
            'mapping_id' => $mapping->id,
            'reassignments' => [['teacher_id' => $to->user_id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email_subject', 'email_body']);
    }

    /**
     * dispatch(...)->afterResponse() still runs within a test (the test
     * kernel's terminate() is called), and MAIL_MAILER=array/
     * QUEUE_CONNECTION=sync in phpunit.xml means this runs synchronously
     * with no real network call — so this can assert the real end-to-end
     * effect (an EmailLog row per newly-reassigned teacher) instead of
     * faking it, same as AssignTeacherTest's own reassign-adjacent test.
     */
    public function test_reassign_logs_and_sends_an_email_to_every_newly_assigned_teacher(): void
    {
        $admin = $this->actingAdmin();
        $from = TeacherDetail::factory()->create();
        $toA = TeacherDetail::factory()->create();
        $toB = TeacherDetail::factory()->create();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->count(7)->create([
            'teacher_id' => $from->user_id,
            'question_answer_sheet_mapping_id' => $mapping->id,
            'evaluation_start_date' => '2026-03-01 09:00:00',
            'evaluation_end_date' => '2026-03-10 18:00:00',
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$from->user_id}/assignments/reassign", [
            'mapping_id' => $mapping->id,
            'email_subject' => 'Answer Sheets Reassigned',
            'email_body' => 'You have been reassigned answer sheets to evaluate.',
            'reassignments' => [
                ['teacher_id' => $toA->user_id, 'quantity' => 4],
                ['teacher_id' => $toB->user_id, 'quantity' => 3],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseCount('email_logs', 2);
        $this->assertDatabaseHas('email_logs', [
            'sender_id' => $admin->id,
            'receiver_id' => $toA->user_id,
            'type' => 'answer_sheet_reassigned',
            'subject' => 'Answer Sheets Reassigned',
            'is_sent' => true,
        ]);
        $this->assertDatabaseHas('email_logs', [
            'sender_id' => $admin->id,
            'receiver_id' => $toB->user_id,
            'type' => 'answer_sheet_reassigned',
            'subject' => 'Answer Sheets Reassigned',
            'is_sent' => true,
        ]);
    }
}
