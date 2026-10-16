<?php

namespace Tests\Feature;

use App\Models\ExamSubType;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\GeneralSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamSubTypeSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://college.test/api/V1/DataApi/examSubType';

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
            'inserted_id' => '8', 'name' => 'ROOM-TEST-EXAM', 'type_id' => '28', 'type_name' => 'TEST-EXAM-ROOM',
            'term_id' => '32', 'term_name' => 'EXAM_TERM', 'assessment_type_id' => '2',
            'assessment_type' => 'End Semester Exam', 'total_count' => '5', 'delete_status' => '1',
        ], $overrides);
    }

    public function test_sync_maps_type_and_term_ref_ids_to_our_ids(): void
    {
        $type = ExamType::create(['inserted_id' => 28, 'name' => 'TEST-EXAM-ROOM', 'status' => true]);
        $term = ExamTerm::create(['inserted_id' => 32, 'name' => 'EXAM_TERM', 'status' => true]);
        $this->apiRows = [
            $this->row(),
            $this->row(['inserted_id' => '9', 'name' => 'Not Synced Yet', 'type_id' => '999', 'term_id' => '', 'delete_status' => '0']),
            $this->row(['inserted_id' => '10', 'name' => '']),
        ];

        $this->withApiKey()->postJson('/api/v1/exam-sub-types/sync')
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 1);

        $this->assertDatabaseHas('exam_sub_types', ['inserted_id' => 8, 'name' => 'ROOM-TEST-EXAM', 'exam_type_id' => $type->id, 'exam_term_id' => $term->id, 'deleted_from_main_db' => 0]);
        $this->assertDatabaseHas('exam_sub_types', ['inserted_id' => 9, 'exam_type_id' => null, 'exam_term_id' => null, 'deleted_from_main_db' => 1]);
        $this->assertDatabaseHas('main_db_syncs', ['entity' => 'exam-sub-types']);
        $this->assertDatabaseHas('audits', ['event' => 'exam-sub-types-synced']);

        $this->withApiKey()->getJson('/api/v1/exam-sub-types?sort_by_field=inserted_id&sort_by=asc')
            ->assertOk()
            ->assertJsonPath('data.items.0.exam_type', 'TEST-EXAM-ROOM')
            ->assertJsonPath('data.items.0.exam_term', 'EXAM_TERM');
    }

    public function test_resync_updates_and_soft_deletes_but_keeps_hand_added(): void
    {
        $manual = ExamSubType::factory()->create();
        $this->apiRows = [$this->row(), $this->row(['inserted_id' => '9', 'name' => 'Leaves Later'])];
        $this->withApiKey()->postJson('/api/v1/exam-sub-types/sync')->assertOk()->assertJsonPath('data.created', 2);

        $this->apiRows = [$this->row(['name' => 'Renamed'])];
        $this->withApiKey()->postJson('/api/v1/exam-sub-types/sync')
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseHas('exam_sub_types', ['inserted_id' => 8, 'name' => 'Renamed']);
        $this->assertSoftDeleted('exam_sub_types', ['inserted_id' => 9]);
        $this->assertNotSoftDeleted($manual);
    }

    public function test_default_request_sends_delete_status_1(): void
    {
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=exam-sub-types')
            ->assertOk()
            ->assertJsonPath('data.config.path', 'DataApi/examSubType')
            ->assertJsonPath('data.config.params', [['key' => 'delete_status', 'value' => '1', 'enabled' => true]]);

        $this->withApiKey()->postJson('/api/v1/exam-sub-types/sync')->assertOk();
        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'?delete_status=1');
    }
}
