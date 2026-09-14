<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\ExamTerm;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionAnswerSheetMapping>
 */
class QuestionAnswerSheetMappingFactory extends Factory
{
    protected $model = QuestionAnswerSheetMapping::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_paper_id' => QuestionPaper::factory(),
            'course_id' => Course::factory(),
            'semester' => fake()->numberBetween(1, 8),
            'exam_term_id' => ExamTerm::factory(),
            'program_name' => fake()->randomElement(['B.Tech in Computer Science Engineering', 'B.Sc in Physics', 'B.A in English']),
            'packet_code' => 'PKT-'.fake()->unique()->numberBetween(1000, 9999),
        ];
    }
}
