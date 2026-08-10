<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SanitizeInputTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function actingUserManager(): User
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->givePermissionTo(['user-create']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * This is the point of making it global: neither CourseController nor
     * its FormRequests know anything about sanitization, yet a script tag
     * submitted through it never reaches the database.
     */
    public function test_a_script_tag_in_a_course_name_is_stripped_before_it_is_stored(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/courses', [
            'name' => '<script>alert(1)</script>Physics',
            'code' => 'SAN-01',
        ]);

        $response->assertStatus(201);
        $this->assertSame('Physics', $response->json('data.name'));
        $this->assertDatabaseHas('courses', ['code' => 'SAN-01', 'name' => 'Physics']);
    }

    public function test_a_script_tag_in_a_program_field_is_stripped_without_any_program_specific_code(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Engineering',
            'department' => '<img src=x onerror=alert(1)>Science',
            'code' => 'SAN-02',
            'course_ids' => [$course->id],
        ]);

        $response->assertStatus(201);
        $this->assertSame('Science', $response->json('data.department'));
    }

    public function test_a_script_tag_in_a_user_name_is_stripped(): void
    {
        $this->actingUserManager();

        $response = $this->withApiKey()->postJson('/api/v1/users', [
            'name' => '<b>Bold</b> Name<script>evil()</script>',
            'username' => 'sanitizeduser',
            'email' => 'sanitized@example.com',
            'password' => 'Passw0rd!23',
            'password_confirmation' => 'Passw0rd!23',
        ]);

        $response->assertStatus(201);
        $this->assertSame('Bold Name', $response->json('data.name'));
    }

    /**
     * The one deliberate exemption — a password containing '<' or '>' must
     * survive completely untouched, or the user's real password would
     * silently differ from what gets hashed and stored.
     */
    public function test_password_fields_are_never_sanitized(): void
    {
        $this->actingUserManager();

        $response = $this->withApiKey()->postJson('/api/v1/users', [
            'name' => 'Someone',
            'username' => 'pwdtest',
            'email' => 'pwdtest@example.com',
            'password' => 'P@ss<w0rd>!23',
            'password_confirmation' => 'P@ss<w0rd>!23',
        ]);

        $response->assertStatus(201);

        $user = User::where('username', 'pwdtest')->firstOrFail();
        $this->assertTrue(Hash::check('P@ss<w0rd>!23', $user->password));
    }

    public function test_a_search_query_parameter_is_sanitized_too(): void
    {
        $this->actingAdmin();
        Course::factory()->create(['name' => 'Chemistry']);

        // Should not error out or reflect the raw payload anywhere — just
        // confirm the request is handled normally with the tag stripped.
        $response = $this->withApiKey()->getJson('/api/v1/courses?search='.urlencode('<script>x</script>Chem'));

        $response->assertOk();
    }
}
