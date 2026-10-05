<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function notify(User $user, array $overrides = []): UserNotification
    {
        return UserNotification::create(array_merge([
            'user_id' => $user->id,
            'type' => UserNotification::ASSIGNED,
            'title' => 'Answer sheets assigned',
            'message' => '3 answer sheets have been assigned to you.',
            'link' => '/my-pending-courses',
        ], $overrides));
    }

    public function test_index_lists_only_my_notifications_newest_first_with_unread_count(): void
    {
        $me = $this->actingUser();
        $first = $this->notify($me, ['read_at' => now()]);
        $second = $this->notify($me);
        $third = $this->notify($me, ['type' => UserNotification::ISSUE_RESOLVED, 'title' => 'Your issue is resolved']);
        $this->notify(User::factory()->create()); // someone else's — never shown

        $response = $this->withApiKey()->getJson('/api/v1/user-notifications');

        $response->assertOk();
        $this->assertSame([$third->id, $second->id, $first->id], collect($response->json('data.items'))->pluck('id')->all());
        $this->assertSame(2, $response->json('data.unread_count'));
        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertFalse($response->json('data.items.0.is_read'));
        $this->assertTrue($response->json('data.items.2.is_read'));
        $this->assertSame('/my-pending-courses', $response->json('data.items.0.link'));
    }

    public function test_index_paginates_and_caps_per_page(): void
    {
        $me = $this->actingUser();
        foreach (range(1, 12) as $i) {
            $this->notify($me);
        }

        $page2 = $this->withApiKey()->getJson('/api/v1/user-notifications?per_page=5&page=2');
        $page2->assertOk();
        $this->assertCount(5, $page2->json('data.items'));
        $this->assertSame(3, $page2->json('data.pagination.last_page'));

        $capped = $this->withApiKey()->getJson('/api/v1/user-notifications?per_page=500');
        $this->assertSame(50, $capped->json('data.pagination.per_page'));
    }

    public function test_index_can_filter_to_unread_only(): void
    {
        $me = $this->actingUser();
        $this->notify($me, ['read_at' => now()]);
        $unread = $this->notify($me);

        $response = $this->withApiKey()->getJson('/api/v1/user-notifications?filter=unread');

        $response->assertOk();
        $this->assertSame([$unread->id], collect($response->json('data.items'))->pluck('id')->all());
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    public function test_unread_count_counts_only_my_unread(): void
    {
        $me = $this->actingUser();
        $this->notify($me);
        $this->notify($me);
        $this->notify($me, ['read_at' => now()]);
        $this->notify(User::factory()->create());

        $this->withApiKey()->getJson('/api/v1/user-notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_mark_read_marks_one_and_returns_the_new_count(): void
    {
        $me = $this->actingUser();
        $target = $this->notify($me);
        $this->notify($me);

        $this->withApiKey()->postJson("/api/v1/user-notifications/{$target->id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->assertNotNull($target->fresh()->read_at);

        // Marking it again is harmless.
        $this->withApiKey()->postJson("/api/v1/user-notifications/{$target->id}/read")->assertOk();
    }

    public function test_mark_read_cannot_touch_someone_elses_notification(): void
    {
        $this->actingUser();
        $theirs = $this->notify(User::factory()->create());

        $this->withApiKey()->postJson("/api/v1/user-notifications/{$theirs->id}/read")->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_mark_all_read_only_affects_my_notifications(): void
    {
        $me = $this->actingUser();
        $this->notify($me);
        $this->notify($me);
        $theirs = $this->notify(User::factory()->create());

        $this->withApiKey()->postJson('/api/v1/user-notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(0, UserNotification::where('user_id', $me->id)->whereNull('read_at')->count());
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_requires_authentication(): void
    {
        $this->withApiKey()->getJson('/api/v1/user-notifications')->assertUnauthorized();
    }
}
