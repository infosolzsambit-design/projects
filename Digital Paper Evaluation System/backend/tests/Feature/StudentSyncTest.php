<?php

namespace Tests\Feature;

use App\Models\GeneralSetting;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://college.test/api/V1/DataApi/student';

    /** What the fake API returns — changeable between syncs in one test. */
    private array $apiRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([self::URL.'*' => fn () => Http::response(['status' => true, 'message' => 'ok', 'count' => count($this->apiRows), 'data' => $this->apiRows])]);
        Sanctum::actingAs(User::factory()->create());
        foreach ([
            'sync_base_url' => 'https://college.test/api/V1',
            'sync_base_url_security_code' => 'secret-code',
            'is_need_api_from_main_db' => 'yes',
        ] as $field => $value) {
            GeneralSetting::updateOrCreate(['field_name' => $field], GeneralSetting::factory()->make(['field_name' => $field, 'value' => $value, 'status' => true])->toArray());
        }
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'inserted_id' => '9323', 'regisration_id' => '241005007087', 'batch' => '2024',
            'name' => "\u{00A0}AADIPTA BHUNIA", 'program_id' => '113', 'delete_status' => '1',
        ], $overrides);
    }

    public function test_sync_maps_registration_batch_and_program(): void
    {
        Program::create(['inserted_id' => 113, 'name' => 'B.Tech CSE', 'label' => 'UG', 'department' => '', 'code' => '', 'status' => true]);
        $this->apiRows = [
            $this->row(),
            $this->row(['inserted_id' => '9324', 'regisration_id' => '241005007278', 'name' => 'Unknown Program', 'program_id' => '999']),
            $this->row(['inserted_id' => '9325', 'regisration_id' => '', 'name' => 'No Registration']),
        ];

        $this->withApiKey()->postJson('/api/v1/students/sync')
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 1);

        // The leading non-breaking space is gone.
        $this->assertDatabaseHas('students', ['inserted_id' => 9323, 'name' => 'AADIPTA BHUNIA', 'registration_no' => '241005007087', 'batch' => '2024', 'program_name' => 'B.Tech CSE', 'deleted_from_main_db' => 0, 'status' => 1]);
        $this->assertDatabaseHas('students', ['inserted_id' => 9324, 'program_name' => null]);
        $this->assertDatabaseMissing('students', ['inserted_id' => 9325]);
        $this->assertDatabaseHas('main_db_syncs', ['entity' => 'students']);
    }

    public function test_resync_links_hand_added_students_updates_and_soft_deletes(): void
    {
        $handAdded = Student::create(['name' => 'Typed By Hand', 'registration_no' => '241005007087', 'status' => true]);
        $manual = Student::create(['name' => 'Manual Only', 'registration_no' => 'MANUAL-1', 'status' => true]);
        $this->apiRows = [$this->row(), $this->row(['inserted_id' => '9400', 'regisration_id' => '241005009999', 'name' => 'Leaves Later'])];
        $this->withApiKey()->postJson('/api/v1/students/sync')->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1);

        // Linked, not duplicated.
        $this->assertSame(9323, $handAdded->fresh()->inserted_id);
        $this->assertSame('AADIPTA BHUNIA', $handAdded->fresh()->name);
        $this->assertSame(1, Student::where('registration_no', '241005007087')->count());

        $this->apiRows = [$this->row(['batch' => '2025'])];
        $this->withApiKey()->postJson('/api/v1/students/sync')
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.deleted', 1);

        $this->assertSame('2025', $handAdded->fresh()->batch);
        $this->assertSoftDeleted('students', ['inserted_id' => 9400]);
        $this->assertNotSoftDeleted($manual);
    }

    public function test_default_request_sends_delete_status_1(): void
    {
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=students')
            ->assertOk()
            ->assertJsonPath('data.config.path', 'DataApi/student')
            ->assertJsonPath('data.config.params', [['key' => 'delete_status', 'value' => '1', 'enabled' => true]]);

        $this->withApiKey()->postJson('/api/v1/students/sync')->assertOk();
        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'?delete_status=1');
    }
}
