<?php

namespace Tests\Feature;

use App\Models\ExamTerm;
use App\Models\GeneralSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamTermSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://college.test/api/V1/DataApi/examTerm';

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
        $manual = ExamTerm::create(['name' => 'Manual Term', 'status' => true]);
        $renamed = ExamTerm::create(['inserted_id' => 30, 'name' => 'Old Name', 'status' => true]);
        $gone = ExamTerm::create(['inserted_id' => 20, 'name' => 'Gone', 'status' => true]);

        $this->fakeApi([
            ['inserted_id' => '32', 'name' => 'EXAM_TERM', 'delete_status' => '0'],
            ['inserted_id' => '31', 'name' => ' Odd Term 2025-26 ', 'delete_status' => '1'],
            ['inserted_id' => '30', 'name' => 'diploma yearly term 2025-2026', 'delete_status' => '1'],
            ['inserted_id' => '29', 'name' => '', 'delete_status' => '1'],
        ]);

        $this->withApiKey()->postJson('/api/v1/exam-terms/sync')
            ->assertOk()
            ->assertJsonPath('data.fetched', 4)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertDatabaseHas('exam_terms', ['inserted_id' => 32, 'name' => 'EXAM_TERM', 'deleted_from_main_db' => 1, 'status' => 1]);
        $this->assertDatabaseHas('exam_terms', ['inserted_id' => 31, 'name' => 'Odd Term 2025-26', 'deleted_from_main_db' => 0]);
        $this->assertSame('diploma yearly term 2025-2026', $renamed->fresh()->name);
        $this->assertSoftDeleted($gone);
        $this->assertNotSoftDeleted($manual);
        $this->assertDatabaseHas('main_db_syncs', ['entity' => 'exam-terms']);
        $this->assertDatabaseHas('audits', ['event' => 'exam-terms-synced']);

        $this->withApiKey()->getJson('/api/v1/exam-terms?sort_by_field=id&sort_by=asc')
            ->assertOk()
            ->assertJsonPath('data.items.1.inserted_id', 30)
            ->assertJsonPath('data.items.1.deleted_from_main_db', false);
    }

    public function test_status_returns_the_exam_term_default_path(): void
    {
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=exam-terms')
            ->assertOk()->assertJsonPath('data.config.path', 'DataApi/examTerm');
    }
}
