<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->givePermissionTo([
            'user-list', 'user-create', 'user-view', 'user-update', 'user-delete', 'user-restore',
        ]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_a_user(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/users', [
            'name' => 'New Guy',
            'username' => 'newguy',
            'email' => 'newguy@example.com',
            'password' => 'Passw0rd!23',
            'password_confirmation' => 'Passw0rd!23',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.username', 'newguy')
            ->assertJsonPath('data.is_active', true);

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertDatabaseHas('users', ['username' => 'newguy']);
    }

    public function test_user_creation_requires_a_strong_password(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/users', [
            'name' => 'Weak Pass',
            'username' => 'weakpass',
            'email' => 'weakpass@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_admin_can_update_a_user(): void
    {
        $this->actingAdmin();
        $target = User::factory()->create(['name' => 'Old Name']);

        $response = $this->withApiKey()->putJson("/api/v1/users/{$target->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk()->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'New Name']);
    }

    public function test_admin_can_soft_delete_a_user(): void
    {
        $this->actingAdmin();
        $target = User::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/users/{$target->id}");

        $response->assertOk();
        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_soft_deleted_user_is_excluded_from_default_listing(): void
    {
        $this->actingAdmin();
        $target = User::factory()->create();
        $target->delete();

        $response = $this->withApiKey()->getJson('/api/v1/users?per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($target->id, $ids);
    }

    public function test_admin_can_restore_a_soft_deleted_user(): void
    {
        $this->actingAdmin();
        $target = User::factory()->create();
        $target->delete();

        $response = $this->withApiKey()->postJson("/api/v1/users/{$target->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->actingAdmin();

        $response = $this->withApiKey()->deleteJson("/api/v1/users/{$admin->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }
}
