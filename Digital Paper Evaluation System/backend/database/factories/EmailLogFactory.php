<?php

namespace Database\Factories;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailLog>
 */
class EmailLogFactory extends Factory
{
    protected $model = EmailLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_id' => User::factory(),
            'receiver_id' => User::factory(),
            'type' => 'answer_sheet_assigned',
            'subject' => fake()->sentence(),
            'body' => fake()->paragraphs(3, true),
            'is_sent' => true,
            'error_message' => null,
            'sent_at' => now(),
            'is_read' => false,
            'read_at' => null,
        ];
    }

    public function unsent(): static
    {
        return $this->state(fn () => ['is_sent' => false, 'sent_at' => null, 'error_message' => 'Connection timed out']);
    }

    public function read(): static
    {
        return $this->state(fn () => ['is_read' => true, 'read_at' => now()]);
    }
}
