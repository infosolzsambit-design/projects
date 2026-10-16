<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\GeneralSetting;
use App\Models\TeacherDetail;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://college.test/api/V1/DataApi/teacher';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class); // the Teacher role new teachers get
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

    /** What the fake API returns — changeable between syncs in one test. */
    private array $apiRows = [];

    private function fakeApi(array $data): void
    {
        $this->apiRows = $data;
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'inserted_id' => '1', 'name' => 'Ankur  Mondal', 'email' => 'ankur@example.com', 'phone_no' => '91-8902425',
            'emp_code' => '110572', 'department_id' => '', 'designation' => null, 'dept_id' => '', 'dept_name' => null,
            'roll_id' => '20,8', 'roll_name' => 'Assistant Professor, HOD', 'delete_status' => '1',
        ], $overrides);
    }

    public function test_first_sync_creates_teachers_with_departments_and_designation(): void
    {
        Mail::fake();
        $cse = Department::create(['inserted_id' => 75, 'name' => 'CSE', 'status' => true]);
        $law = Department::create(['inserted_id' => 64, 'name' => 'Law', 'status' => true]);

        $this->fakeApi([
            $this->row(['department_id' => '75,64,999', 'dept_id' => '75,64,999']),
            $this->row(['inserted_id' => '2', 'name' => 'Gone There', 'email' => 'gone@example.com', 'phone_no' => '91-8902425', 'roll_name' => "Academic Coordinator\r\n", 'delete_status' => '0']),
            $this->row(['inserted_id' => '3', 'email' => 'not-an-email']),
            $this->row(['inserted_id' => '4', 'name' => 'Same Email', 'email' => 'ANKUR@example.com']),
        ]);

        $this->withApiKey()->postJson('/api/v1/teachers/sync')
            ->assertOk()
            ->assertJsonPath('data.fetched', 4)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 2);

        $user = User::where('email', 'ankur@example.com')->firstOrFail();
        $this->assertSame('Ankur Mondal', $user->name);
        $this->assertSame('91-8902425', $user->phone_no); // stored as sent
        $this->assertNull($user->password);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasRole('Teacher'));
        $this->assertSame(1, $user->teacherDetail->inserted_id);
        $this->assertSame('Assistant Professor, HOD', $user->teacherDetail->designation); // roll_name, not roll_id
        $this->assertEqualsCanonicalizing([$cse->id, $law->id], $user->departments->pluck('id')->all());

        // delete_status 0 = deleted there: flagged and can't sign in. Same phone is fine.
        $gone = User::where('email', 'gone@example.com')->firstOrFail();
        $this->assertFalse($gone->is_active);
        $this->assertTrue($gone->teacherDetail->deleted_from_main_db);
        $this->assertSame('Academic Coordinator', $gone->teacherDetail->designation);

        $this->assertDatabaseHas('main_db_syncs', ['entity' => 'teachers']);
        Mail::assertNothingSent();
    }

    public function test_resync_updates_toggles_sign_in_soft_deletes_and_links_by_email(): void
    {
        $cse = Department::create(['inserted_id' => 75, 'name' => 'CSE', 'status' => true]);
        $law = Department::create(['inserted_id' => 64, 'name' => 'Law', 'status' => true]);
        $this->fakeApi([
            $this->row(['department_id' => '75']),
            $this->row(['inserted_id' => '2', 'email' => 'two@example.com', 'department_id' => '64']),
            $this->row(['inserted_id' => '3', 'email' => 'three@example.com', 'delete_status' => '0']),
        ]);
        $this->withApiKey()->postJson('/api/v1/teachers/sync')->assertOk()->assertJsonPath('data.created', 3);

        // Added by hand, never synced — and one added by hand that the API also has.
        $manual = TeacherDetail::factory()->create();
        $handAdded = TeacherDetail::factory()->create();
        $handAdded->user->update(['email' => 'hand@example.com']);

        $this->fakeApi([
            $this->row(['name' => 'Ankur Mondal Renamed', 'department_id' => '75,64']),
            // 2 is gone from the API → soft-deleted.
            $this->row(['inserted_id' => '3', 'email' => 'three@example.com', 'delete_status' => '1', 'department_id' => '']),
            $this->row(['inserted_id' => '9', 'email' => 'hand@example.com', 'name' => 'Hand Added']),
        ]);

        $this->withApiKey()->postJson('/api/v1/teachers/sync')
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 3)
            ->assertJsonPath('data.deleted', 1);

        $one = User::where('email', 'ankur@example.com')->firstOrFail();
        $this->assertSame('Ankur Mondal Renamed', $one->name);
        $this->assertEqualsCanonicalizing([$cse->id, $law->id], $one->departments->pluck('id')->all());

        $this->assertSoftDeleted('users', ['email' => 'two@example.com']);
        $this->assertSoftDeleted('teacher_details', ['inserted_id' => 2]);

        // Un-deleted there → can sign in again.
        $this->assertTrue(User::where('email', 'three@example.com')->first()->is_active);

        // Linked, not duplicated.
        $this->assertSame(9, $handAdded->fresh()->inserted_id);
        $this->assertSame(1, User::where('email', 'hand@example.com')->count());
        $this->assertNull($manual->fresh()->inserted_id);
        $this->assertNotSoftDeleted($manual);
    }

    public function test_an_admins_deactivation_stands_while_the_main_database_flag_is_unchanged(): void
    {
        $this->fakeApi([$this->row()]);
        $this->withApiKey()->postJson('/api/v1/teachers/sync')->assertOk();
        User::where('email', 'ankur@example.com')->update(['is_active' => false]);

        $this->withApiKey()->postJson('/api/v1/teachers/sync')->assertOk()->assertJsonPath('data.unchanged', 1);

        $this->assertFalse((bool) User::where('email', 'ankur@example.com')->value('is_active'));
    }

    public function test_default_request_sends_delete_status_1(): void
    {
        $this->withApiKey()->getJson('/api/v1/main-db-sync/status?entity=teachers')
            ->assertOk()
            ->assertJsonPath('data.config.path', 'DataApi/teacher')
            ->assertJsonPath('data.config.params', [['key' => 'delete_status', 'value' => '1', 'enabled' => true]]);

        $this->fakeApi([]);
        $this->withApiKey()->postJson('/api/v1/teachers/sync')->assertOk();
        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'?delete_status=1');
    }
}
