<?php

namespace Tests\Feature;

use App\Models\ExamType;
use App\Models\GeneralSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamTypeSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://college.test/api/V1/DataApi/examType';

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

    public function test_sync_creates_updates_flags_and_soft_deletes(): void
    {
        $manual = ExamType::create(['name' => 'Manual Type', 'status' => true]);
        $renamed = ExamType::create(['inserted_id' => 26, 'name' => 'Old Name', 'status' => true]);
        $gone = ExamType::create(['inserted_id' => 10, 'name' => 'Gone', 'status' => true]);

        $this->fakeApi([
            ['inserted_id' => '28', 'name' => 'TEST-EXAM-ROOM', 'delete_status' => '0'],
            ['inserted_id' => '27', 'name' => ' EXAM DECEMBER ', 'delete_status' => '1'],
            ['inserted_id' => '26', 'name' => 'Even sem 2025', 'delete_status' => '1'],
            ['inserted_id' => '25', 'name' => '', 'delete_status' => '1'],
        ]);

        $this->withApiKey()->postJson('/api/v1/exam-types/sync')
            ->assertOk()
            ->assertJsonPath('data.fetched', 4)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.skipped', 1);

        // delete_status 0 = deleted in the main database (kept here, flagged).
        $this->assertDatabaseHas('exam_types', ['inserted_id' => 28, 'name' => 'TEST-EXAM-ROOM', 'deleted_from_main_db' => 1, 'status' => 1, 'deleted_at' => null]);
        $this->assertDatabaseHas('exam_types', ['inserted_id' => 27, 'name' => 'EXAM DECEMBER', 'deleted_from_main_db' => 0]);
        $this->assertSame('Even sem 2025', $renamed->fresh()->name);
        $this->assertSoftDeleted($gone);
        $this->assertNotSoftDeleted($manual);
        $this->assertDatabaseHas('main_db_syncs', ['entity' => 'exam-types']);
        $this->assertDatabaseHas('audits', ['event' => 'exam-types-synced']);

        $this->withApiKey()->getJson('/api/v1/exam-types?sort_by_field=id&sort_by=asc')
            ->assertOk()
            ->assertJsonPath('data.items.1.inserted_id', 26)
            ->assertJsonPath('data.items.1.deleted_from_main_db', false);
    }

    public function test_status_returns_the_exam_type_default_path(): void
    {
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=exam-types')
            ->assertOk()->assertJsonPath('data.config.path', 'DataApi/examType');
    }
}
