<?php

namespace Tests\Feature;

use App\Models\ExamType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API dates go out in the app timezone (Asia/Kolkata, +05:30), not as UTC
 * "…Z" — see App\Helpers\JsonDate / SerializesDatesInAppTimezone.
 */
class DateSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_and_resource_dates_are_in_the_app_timezone(): void
    {
        $this->assertSame('Asia/Kolkata', config('app.timezone'));
        Sanctum::actingAs(User::factory()->create());
        $type = ExamType::factory()->create(['created_at' => '2026-10-23 18:44:26']);

        // Through a resource…
        $this->withApiKey()->getJson("/api/v1/exam-types/{$type->id}")
            ->assertOk()->assertJsonPath('data.created_at', '2026-10-23T18:44:26+05:30');

        // …and a model returned as-is (status=all lists).
        $row = collect($this->withApiKey()->getJson('/api/v1/exam-types?status=all')->json('data'))->firstWhere('id', $type->id);
        $this->assertSame('2026-10-23T18:44:26+05:30', $row['created_at']);

        // …and a bare Carbon value.
        $this->assertSame('"2026-10-23T18:44:26+05:30"', json_encode(now()->setDateTime(2026, 10, 23, 18, 44, 26)));
    }
}
