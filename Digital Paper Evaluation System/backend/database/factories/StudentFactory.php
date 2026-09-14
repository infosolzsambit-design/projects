<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'roll_no' => fake()->unique()->bothify('ROLL-####'),
            'semester' => fake()->numberBetween(1, 8),
            'program_name' => fake()->randomElement(['B.Tech in Computer Science Engineering', 'B.Sc in Physics', 'B.A in English']),
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }
}
