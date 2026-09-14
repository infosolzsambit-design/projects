<?php

namespace Database\Factories;

use App\Models\PermissionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PermissionGroup>
 */
class PermissionGroupFactory extends Factory
{
    protected $model = PermissionGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' Group',
            'sort_order' => fake()->numberBetween(0, 50),
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }
}
