<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\GeneralSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://college.test/api/V1/DataApi/course';

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
        $this->settings('yes');
    }

    private function settings(string $enabled): void
    {
        foreach ([
            'sync_base_url' => 'https://college.test/api/V1',
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

    public function test_first_sync_creates_courses(): void
    {
        $longType = 'Lecture, Practical, Project, Tutorial, Workshop, Studio';
        $this->fakeApi([
            ['inserted_id' => '1990', 'name' => ' Applied Chemistry', 'code' => 'BS105', 'type' => 'Tutorial', 'credit_point' => '', 'delete_status' => '0'],
            ['inserted_id' => '2001', 'name' => 'Studio Art', 'code' => '', 'type' => $longType, 'credit_point' => '4', 'delete_status' => '1'],
            ['inserted_id' => '2880', 'name' => '', 'code' => 'TCM', 'type' => 'Studio', 'credit_point' => '100', 'delete_status' => '1'],
        ]);

        $this->withApiKey()->postJson('/api/v1/courses/sync')
            ->assertOk()
            ->assertJsonPath('data.fetched', 3)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 1);

        $this->assertDatabaseHas('courses', ['inserted_id' => 1990, 'name' => 'Applied Chemistry', 'code' => 'BS105', 'type' => 'Tutorial', 'deleted_from_main_db' => 1, 'status' => 1, 'deleted_at' => null]);
        $this->assertDatabaseHas('courses', ['inserted_id' => 2001, 'code' => '', 'type' => $longType, 'deleted_from_main_db' => 0]);
        $this->assertDatabaseMissing('courses', ['inserted_id' => 2880]);
        Http::assertSent(fn (Request $r) => $r->url() === self::URL && $r->hasHeader('Security-Code', 'secret-code'));
    }

    public function test_resync_updates_restores_deletes_and_leaves_manual_courses_alone(): void
    {
        $manual = Course::create(['name' => 'Manual', 'code' => 'M1', 'type' => 'T', 'status' => true]);
        $same = Course::create(['inserted_id' => 1, 'name' => 'Same', 'code' => 'S', 'type' => 'Lecture', 'status' => true]);
        $changed = Course::create(['inserted_id' => 2, 'name' => 'Old', 'code' => 'O', 'type' => 'Lecture', 'status' => true]);
        $removedEarlier = Course::create(['inserted_id' => 3, 'name' => 'Back', 'code' => 'B', 'type' => 'Lecture', 'status' => true]);
        $removedEarlier->delete();
        $gone = Course::create(['inserted_id' => 4, 'name' => 'Gone', 'code' => 'G', 'type' => 'Lecture', 'status' => true]);
        // Sent without a name: skipped, but not treated as gone.
        $badRow = Course::create(['inserted_id' => 6, 'name' => 'Keep', 'code' => 'K', 'type' => 'Lecture', 'status' => true]);

        $this->fakeApi([
            ['inserted_id' => '1', 'name' => 'Same', 'code' => 'S', 'type' => 'Lecture', 'delete_status' => '1'],
            ['inserted_id' => '2', 'name' => 'New', 'code' => 'N', 'type' => 'Practical', 'delete_status' => '0'],
            ['inserted_id' => '3', 'name' => 'Back', 'code' => 'B', 'type' => 'Lecture', 'delete_status' => '1'],
            ['inserted_id' => '5', 'name' => 'Brand New', 'code' => 'BN', 'type' => 'Project', 'delete_status' => '1'],
            ['inserted_id' => '6', 'name' => '', 'code' => 'K', 'type' => 'Lecture', 'delete_status' => '1'],
        ]);

        $this->withApiKey()->postJson('/api/v1/courses/sync')
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.restored', 1)
            ->assertJsonPath('data.unchanged', 1)
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertNotSoftDeleted($manual);
        $this->assertNotSoftDeleted($same);
        $this->assertDatabaseHas('courses', ['id' => $changed->id, 'name' => 'New', 'code' => 'N', 'type' => 'Practical', 'deleted_from_main_db' => 1]);
        $this->assertNotSoftDeleted($removedEarlier);
        $this->assertSoftDeleted($gone);
        $this->assertNotSoftDeleted($badRow);
        $this->assertDatabaseHas('audits', ['event' => 'courses-synced']);
        $this->assertDatabaseHas('main_db_syncs', ['entity' => 'courses']);

        $this->withApiKey()->getJson('/api/v1/courses?sort_by_field=id&sort_by=asc')
            ->assertOk()
            ->assertJsonPath('data.items.1.inserted_id', 1)
            ->assertJsonPath('data.items.2.deleted_from_main_db', true);
    }

    public function test_sync_is_refused_when_the_setting_is_off(): void
    {
        $this->settings('no');
        Http::fake();

        $this->withApiKey()->postJson('/api/v1/courses/sync')->assertStatus(422);
        Http::assertNothingSent();
    }
}
