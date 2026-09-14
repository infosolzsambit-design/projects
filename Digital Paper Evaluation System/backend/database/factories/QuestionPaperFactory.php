<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\ExamTerm;
use App\Models\QuestionPaper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionPaper>
 */
class QuestionPaperFactory extends Factory
{
    protected $model = QuestionPaper::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_year' => fake()->numberBetween(2020, 2026),
            'course_id' => Course::factory(),
            'semester' => fake()->numberBetween(1, 8),
            'exam_term_id' => ExamTerm::factory(),
            'pdf_path' => '/storage/question-papers/'.fake()->uuid().'.pdf',
            'full_marks' => 80,
            'time_allotted' => '3 hours',
            'status' => 'draft',
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => ['status' => 'ready']);
    }
}
