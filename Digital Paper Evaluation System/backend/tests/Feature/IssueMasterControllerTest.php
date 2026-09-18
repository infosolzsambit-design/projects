<?php

namespace Tests\Feature;

use App\Models\IssueMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IssueMasterControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_active_issue_types(): void
    {
        Sanctum::actingAs(User::factory()->create());
        IssueMaster::factory()->create(['name' => 'Printing Issue', 'status' => true]);
        IssueMaster::factory()->create(['name' => 'Retired Issue', 'status' => false]);

        $response = $this->withApiKey()->getJson('/api/v1/issue-masters');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Printing Issue'], $names);
    }
}
