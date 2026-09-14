<?php

namespace Database\Factories;

use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeacherDetail>
 */
class TeacherDetailFactory extends Factory
{
    protected $model = TeacherDetail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'emp_code' => fake()->unique()->bothify('EMP-####'),
            'department' => fake()->randomElement(['Computer Science', 'Mathematics', 'Physics', 'English']),
            'designation' => fake()->randomElement(['Assistant Professor', 'Associate Professor', 'Professor', 'Lecturer']),
        ];
    }
}
