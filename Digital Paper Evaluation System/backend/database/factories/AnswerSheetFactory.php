<?php

namespace Database\Factories;

use App\Models\AnswerSheet;
use App\Models\QuestionAnswerSheetMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnswerSheet>
 */
class AnswerSheetFactory extends Factory
{
    protected $model = AnswerSheet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_answer_sheet_mapping_id' => QuestionAnswerSheetMapping::factory(),
            'branch_code' => 'BR1',
            'branch_name' => 'Main Branch',
            'subject_code' => fake()->bothify('???###'),
            'subject_name' => fake()->words(3, true),
            'semester' => fake()->numberBetween(1, 8),
            'subject_barcode' => fake()->unique()->numerify('BC-#######'),
            'fi_code' => 'FI01',
            'roll_no' => fake()->unique()->numerify('R####'),
            'name' => fake()->name(),
            'registration_no' => fake()->numerify('REG####'),
            'absent' => false,
            'locked_time' => null,
            'packet_no' => 'PKT-001',
            'barcode' => null,
            'marks' => null,
            'top_sheet' => null,
            'pdf_name' => null,
            'pdf_path' => null,
        ];
    }
}
