<?php

namespace Database\Factories;

use App\Models\PermissionGroup;
use App\Models\PermissionSubGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PermissionSubGroup>
 */
class PermissionSubGroupFactory extends Factory
{
    protected $model = PermissionSubGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'permission_group_id' => PermissionGroup::factory(),
            'name' => fake()->unique()->words(2, true).' Sub Group',
            'sort_order' => fake()->numberBetween(0, 50),
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }
}
