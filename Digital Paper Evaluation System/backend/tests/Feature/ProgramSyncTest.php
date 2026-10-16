<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\GeneralSetting;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgramSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://college.test/api/V1/DataApi/program';

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

    private function fakeApi(array $data): void
    {
        Http::fake([self::URL => Http::response(['status' => true, 'message' => 'ok', 'count' => count($data), 'data' => $data])]);
    }

    public function test_sync_maps_department_and_courses_by_their_inserted_id(): void
    {
        $cse = Department::create(['inserted_id' => 60, 'name' => 'Computer Science', 'status' => true]);
        $c1 = Course::create(['inserted_id' => 2659, 'name' => 'Maths', 'code' => 'M1', 'type' => 'Lecture', 'status' => true]);
        $c2 = Course::create(['inserted_id' => 2718, 'name' => 'Physics', 'code' => 'P1', 'type' => 'Lecture', 'status' => true]);

        $this->fakeApi([
            ['inserted_id' => '73', 'name' => 'B. Tech CSE', 'label' => 'UG', 'department_id' => '60', 'course_ids' => '2659, 2718, 9999', 'delete_status' => '0'],
            ['inserted_id' => '74', 'name' => 'Unknown Dept', 'label' => null, 'department_id' => '12345', 'course_ids' => null, 'delete_status' => '1'],
            ['inserted_id' => '49', 'name' => '', 'label' => null, 'department_id' => null, 'course_ids' => null, 'delete_status' => '1'],
        ]);

        $this->withApiKey()->postJson('/api/v1/programs/sync')
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 1);

        $program = Program::where('inserted_id', 73)->firstOrFail();
        $this->assertSame('B. Tech CSE', $program->name);
        $this->assertSame('UG', $program->label);
        $this->assertSame($cse->id, $program->department_id);
        $this->assertSame('Computer Science', $program->department);
        $this->assertSame('', $program->code);
        $this->assertTrue($program->deleted_from_main_db);
        $this->assertEqualsCanonicalizing([$c1->id, $c2->id], $program->courses()->pluck('courses.id')->all());

        $this->assertDatabaseHas('programs', ['inserted_id' => 74, 'department_id' => null, 'department' => '', 'label' => null]);
        $this->assertDatabaseMissing('programs', ['inserted_id' => 49]);
    }

    public function test_resync_updates_courses_keeps_local_code_and_soft_deletes_missing(): void
    {
        $c1 = Course::create(['inserted_id' => 1, 'name' => 'A', 'code' => 'A', 'type' => 'T', 'status' => true]);
        $c2 = Course::create(['inserted_id' => 2, 'name' => 'B', 'code' => 'B', 'type' => 'T', 'status' => true]);
        $c3 = Course::create(['inserted_id' => 3, 'name' => 'C', 'code' => 'C', 'type' => 'T', 'status' => true]);
        $program = Program::create(['inserted_id' => 10, 'name' => 'MBA', 'label' => 'PG', 'department' => '', 'code' => 'MBA-LOCAL', 'status' => true]);
        $program->courses()->sync([$c1->id, $c2->id]);
        $noCourses = Program::create(['inserted_id' => 11, 'name' => 'BBA', 'label' => 'UG', 'department' => '', 'code' => 'BBA', 'status' => true]);
        $noCourses->courses()->sync([$c3->id]);
        $gone = Program::create(['inserted_id' => 12, 'name' => 'Old', 'label' => 'UG', 'department' => '', 'code' => 'OLD', 'status' => true]);
        $manual = Program::create(['name' => 'Manual', 'label' => 'UG', 'department' => '', 'code' => 'MAN', 'status' => true]);

        $this->fakeApi([
            // Same fields, but the course list changed → counts as updated.
            ['inserted_id' => '10', 'name' => 'MBA', 'label' => 'PG', 'department_id' => null, 'course_ids' => '2,3', 'delete_status' => '1'],
            // No course_ids → its mapping here is left alone.
            ['inserted_id' => '11', 'name' => 'BBA', 'label' => 'UG', 'department_id' => null, 'course_ids' => null, 'delete_status' => '1'],
        ]);

        $this->withApiKey()->postJson('/api/v1/programs/sync')
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.unchanged', 1)
            ->assertJsonPath('data.deleted', 1);

        $this->assertEqualsCanonicalizing([$c2->id, $c3->id], $program->courses()->pluck('courses.id')->all());
        $this->assertSame('MBA-LOCAL', $program->fresh()->code);
        $this->assertSame([$c3->id], $noCourses->courses()->pluck('courses.id')->all());
        $this->assertSoftDeleted($gone);
        $this->assertNotSoftDeleted($manual);
        $this->assertDatabaseHas('main_db_syncs', ['entity' => 'programs']);

        $this->withApiKey()->getJson('/api/v1/programs?sort_by_field=id&sort_by=asc')
            ->assertOk()
            ->assertJsonPath('data.items.0.inserted_id', 10)
            ->assertJsonPath('data.items.0.deleted_from_main_db', false);
    }

    public function test_status_returns_the_program_default_path(): void
    {
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=programs')
            ->assertOk()->assertJsonPath('data.config.path', 'DataApi/program');
    }
}
