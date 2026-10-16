<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\GeneralSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepartmentSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://college.test/api/V1/DataApi/department';

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
        $this->settings('yes');
    }

    private function settings(string $enabled): void
    {
        foreach ([
            'sync_base_url' => 'https://college.test/api/V1/',
            'sync_base_url_security_code' => 'secret-code',
            'is_need_api_from_main_db' => $enabled,
        ] as $field => $value) {
            GeneralSetting::updateOrCreate(['field_name' => $field], GeneralSetting::factory()->make(['field_name' => $field, 'value' => $value, 'status' => true])->toArray());
        }
    }

    private function fakeApi(array $data): void
    {
        Http::fake([self::URL => Http::response(['status' => true, 'message' => 'ok', 'count' => count($data), 'data' => $data])]);
    }

    public function test_status_reports_whether_sync_is_enabled(): void
    {
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status')->assertOk()->assertJsonPath('data.enabled', true);

        $this->settings('no');
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status')->assertOk()->assertJsonPath('data.enabled', false);
    }

    public function test_first_sync_creates_departments_with_inserted_id(): void
    {
        $this->fakeApi([
            ['inserted_id' => '112', 'name' => ' afs ', 'code' => ''],
            ['inserted_id' => '113', 'name' => 'Civil', 'code' => 'CE', 'delete_status' => '0'],
            ['inserted_id' => '', 'name' => 'No id', 'code' => 'X'],
        ]);

        $this->withApiKey()->postJson('/api/v1/departments/sync')
            ->assertOk()
            ->assertJsonPath('data.fetched', 3)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 1);

        $this->assertDatabaseHas('departments', ['inserted_id' => 112, 'name' => 'afs', 'code' => null, 'deleted_from_main_db' => 0, 'status' => 1]);
        // Deleted in the main database: kept here, only flagged.
        $this->assertDatabaseHas('departments', ['inserted_id' => 113, 'name' => 'Civil', 'code' => 'CE', 'deleted_from_main_db' => 1, 'deleted_at' => null]);
        Http::assertSent(fn (Request $r) => $r->url() === self::URL && $r->method() === 'POST' && $r->hasHeader('Security-Code', 'secret-code'));
    }

    public function test_resync_updates_restores_deletes_and_leaves_manual_departments_alone(): void
    {
        $manual = Department::create(['name' => 'Manual Dept', 'status' => true]);
        $same = Department::create(['inserted_id' => 1, 'name' => 'Same', 'code' => 'S', 'status' => true]);
        $renamed = Department::create(['inserted_id' => 2, 'name' => 'Old Name', 'code' => 'O', 'status' => true]);
        $removedEarlier = Department::create(['inserted_id' => 3, 'name' => 'Back Again', 'status' => true]);
        $removedEarlier->delete();
        $gone = Department::create(['inserted_id' => 4, 'name' => 'Gone', 'status' => true]);

        $this->fakeApi([
            ['inserted_id' => '1', 'name' => 'Same', 'code' => 'S', 'delete_status' => '1'],
            ['inserted_id' => '2', 'name' => 'New Name', 'code' => 'N'],
            ['inserted_id' => '3', 'name' => 'Back Again', 'code' => ''],
            ['inserted_id' => '5', 'name' => 'Brand New', 'code' => 'BN'],
        ]);

        $this->withApiKey()->postJson('/api/v1/departments/sync')
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.restored', 1)
            ->assertJsonPath('data.unchanged', 1)
            ->assertJsonPath('data.deleted', 1);

        $this->assertNotSoftDeleted($manual);
        $this->assertNotSoftDeleted($same);
        $this->assertDatabaseHas('departments', ['id' => $renamed->id, 'name' => 'New Name', 'code' => 'N']);
        $this->assertNotSoftDeleted($removedEarlier);
        $this->assertSoftDeleted($gone);
        $this->assertDatabaseHas('departments', ['inserted_id' => 5, 'name' => 'Brand New']);
        $this->assertDatabaseHas('audits', ['event' => 'departments-synced']);
    }

    public function test_sync_is_refused_when_the_setting_is_off(): void
    {
        $this->settings('no');
        Http::fake();

        $this->withApiKey()->postJson('/api/v1/departments/sync')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_api_failure_changes_nothing(): void
    {
        $existing = Department::create(['inserted_id' => 1, 'name' => 'Keep Me', 'status' => true]);
        Http::fake([self::URL => Http::response(['status' => false, 'message' => 'Invalid security code'], 200)]);

        $this->withApiKey()->postJson('/api/v1/departments/sync')->assertStatus(422);
        $this->assertNotSoftDeleted($existing);

        Http::fake([self::URL => Http::response('Server error', 500)]);
        $this->withApiKey()->postJson('/api/v1/departments/sync')->assertStatus(422);
        $this->assertNotSoftDeleted($existing);
    }

    public function test_delete_status_change_counts_as_an_update_and_shows_in_the_list(): void
    {
        $department = Department::create(['inserted_id' => 7, 'name' => 'Physics', 'code' => 'PHY', 'status' => true]);
        $this->fakeApi([['inserted_id' => '7', 'name' => 'Physics', 'code' => 'PHY', 'delete_status' => '0']]);

        $this->withApiKey()->postJson('/api/v1/departments/sync')
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertTrue($department->fresh()->deleted_from_main_db);
        $this->withApiKey()->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonPath('data.items.0.inserted_id', 7)
            ->assertJsonPath('data.items.0.deleted_from_main_db', true);
    }

    public function test_status_returns_the_last_sync_for_the_entity(): void
    {
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=departments')
            ->assertOk()->assertJsonPath('data.last_sync', null);

        $this->fakeApi([['inserted_id' => '1', 'name' => 'Physics', 'code' => 'PHY', 'delete_status' => '1']]);
        $this->withApiKey()->postJson('/api/v1/departments/sync')->assertOk();

        $this->assertDatabaseHas('main_db_syncs', ['entity' => 'departments']);
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=departments')
            ->assertOk()
            ->assertJsonPath('data.last_sync.synced_by', auth()->user()->name)
            ->assertJsonPath('data.last_sync.summary.created', 1);
        $this->assertNotNull($this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=departments')->json('data.last_sync.synced_at'));
        // Another entity's last sync is separate.
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=courses')
            ->assertOk()->assertJsonPath('data.last_sync', null);
    }

    public function test_a_failed_sync_is_not_recorded_as_the_last_sync(): void
    {
        Http::fake([self::URL => Http::response('Server error', 500)]);
        $this->withApiKey()->postJson('/api/v1/departments/sync')->assertStatus(422);

        $this->assertDatabaseCount('main_db_syncs', 0);
    }

    public function test_status_returns_the_default_request_settings(): void
    {
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=courses')
            ->assertOk()
            ->assertJsonPath('data.config.method', 'POST')
            ->assertJsonPath('data.config.path', 'DataApi/course')
            ->assertJsonPath('data.config.params', []);
    }

    public function test_request_settings_are_saved_and_used_by_the_sync(): void
    {
        $this->withApiKey()->putJson('/api/v1/main-db-sync/config', [
            'entity' => 'departments',
            'method' => 'get',
            'path' => '/DataApi/department-list/',
            'params' => [
                ['key' => 'delete_status', 'value' => '0', 'enabled' => true],
                ['key' => 'unused', 'value' => 'x', 'enabled' => false],
                ['key' => '', 'value' => '', 'enabled' => true],
            ],
        ])->assertOk()
            ->assertJsonPath('data.method', 'GET')
            ->assertJsonPath('data.path', 'DataApi/department-list');

        $this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=departments')
            ->assertJsonPath('data.config.method', 'GET')
            ->assertJsonPath('data.config.params.0.key', 'delete_status')
            ->assertJsonPath('data.config.params.1.enabled', false);

        Http::fake(['https://college.test/api/V1/DataApi/department-list*' => Http::response(['status' => true, 'data' => [
            ['inserted_id' => '1', 'name' => 'Physics', 'code' => 'PHY', 'delete_status' => '1'],
        ]])]);
        $this->withApiKey()->postJson('/api/v1/departments/sync')->assertOk()->assertJsonPath('data.created', 1);

        // Only the ticked param with a key, in the query string, as a GET.
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://college.test/api/V1/DataApi/department-list?delete_status=0'
            && $r->hasHeader('Security-Code', 'secret-code'));
    }

    public function test_post_sends_params_in_the_query_string(): void
    {
        $this->withApiKey()->putJson('/api/v1/main-db-sync/config', [
            'entity' => 'departments', 'method' => 'POST', 'path' => 'DataApi/department',
            'params' => [['key' => 'delete_status', 'value' => '1', 'enabled' => true]],
        ])->assertOk();
        Http::fake([self::URL.'*' => Http::response(['status' => true, 'data' => []])]);

        $this->withApiKey()->postJson('/api/v1/departments/sync')->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::URL.'?delete_status=1');
    }

    public function test_request_settings_are_validated(): void
    {
        $base = ['entity' => 'departments', 'method' => 'POST', 'path' => 'DataApi/department', 'params' => []];

        foreach (['../secret', 'https://evil.test/x', 'DataApi/x?y=1', '@evil.test', ''] as $badPath) {
            $this->withApiKey()->putJson('/api/v1/main-db-sync/config', ['path' => $badPath] + $base)
                ->assertStatus(422)->assertJsonValidationErrors('path');
        }
        $this->withApiKey()->putJson('/api/v1/main-db-sync/config', ['method' => 'DELETE'] + $base)
            ->assertStatus(422)->assertJsonValidationErrors('method');
        $this->withApiKey()->putJson('/api/v1/main-db-sync/config', ['entity' => 'users'] + $base)
            ->assertStatus(422)->assertJsonValidationErrors('entity');
        $this->assertDatabaseCount('main_db_sync_configs', 0);
    }

    public function test_delete_status_zero_means_deleted_and_one_means_not_deleted(): void
    {
        $this->fakeApi([
            ['inserted_id' => '1', 'name' => 'Deleted There', 'code' => 'D', 'delete_status' => '0'],
            ['inserted_id' => '2', 'name' => 'Active There', 'code' => 'A', 'delete_status' => '1'],
            ['inserted_id' => '3', 'name' => 'No Flag', 'code' => 'N'],
        ]);

        $this->withApiKey()->postJson('/api/v1/departments/sync')->assertOk();

        $this->assertDatabaseHas('departments', ['inserted_id' => 1, 'deleted_from_main_db' => 1, 'deleted_at' => null]);
        $this->assertDatabaseHas('departments', ['inserted_id' => 2, 'deleted_from_main_db' => 0]);
        $this->assertDatabaseHas('departments', ['inserted_id' => 3, 'deleted_from_main_db' => 0]);
    }
}
