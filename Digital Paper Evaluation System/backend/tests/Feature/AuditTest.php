<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_creates_an_audit_entry(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']);

        $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'Password!23',
        ])->assertOk();

        $this->assertDatabaseHas('audits', [
            'event' => 'login',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
        ]);
    }

    public function test_user_without_audit_permission_cannot_view_audits(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withApiKey()->getJson('/api/v1/audits');

        $response->assertStatus(403);
    }

    public function test_user_with_audit_permission_can_view_audits(): void
    {
        $this->seed(PermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('audit-list');
        Sanctum::actingAs($user);

        Audit::create([
            'event' => 'created',
            'new_values' => ['foo' => 'bar'],
        ]);

        $response = $this->withApiKey()->getJson('/api/v1/audits');

        $response->assertOk()->assertJsonStructure(['data' => ['items', 'pagination']]);
    }

    public function test_created_user_audit_never_exposes_the_password_field(): void
    {
        $this->seed(PermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->givePermissionTo(['user-create', 'audit-list']);
        Sanctum::actingAs($admin);

        $this->withApiKey()->postJson('/api/v1/users', [
            'name' => 'Someone',
            'username' => 'someone1',
            'email' => 'someone1@example.com',
            'password' => 'Passw0rd!23',
            'password_confirmation' => 'Passw0rd!23',
        ])->assertStatus(201);

        $audit = Audit::where('event', 'created')
            ->where('auditable_type', User::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertArrayNotHasKey('password', $audit->new_values ?? []);
    }
}
