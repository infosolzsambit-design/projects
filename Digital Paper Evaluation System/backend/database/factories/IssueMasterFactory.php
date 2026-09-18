<?php

namespace Database\Factories;

use App\Models\IssueMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueMaster>
 */
class IssueMasterFactory extends Factory
{
    protected $model = IssueMaster::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' Issue',
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }
}
