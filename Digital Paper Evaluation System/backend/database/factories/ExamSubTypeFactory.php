<?php

namespace Database\Factories;

use App\Models\ExamSubType;
use App\Models\ExamTerm;
use App\Models\ExamType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamSubType>
 */
class ExamSubTypeFactory extends Factory
{
    protected $model = ExamSubType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' Sub Type',
            'exam_type_id' => ExamType::factory(),
            'exam_term_id' => ExamTerm::factory(),
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }
}
