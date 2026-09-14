<?php

namespace Database\Factories;

use App\Models\ExamTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamTerm>
 */
class ExamTermFactory extends Factory
{
    protected $model = ExamTerm::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' Term',
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }
}
