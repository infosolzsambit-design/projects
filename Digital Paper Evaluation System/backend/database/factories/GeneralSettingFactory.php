<?php

namespace Database\Factories;

use App\Models\GeneralSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneralSetting>
 */
class GeneralSettingFactory extends Factory
{
    protected $model = GeneralSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'field_name' => fake()->unique()->slug(2, '_'),
            'label' => fake()->words(2, true),
            'group_name' => null,
            'type' => 'text',
            'value' => fake()->sentence(),
            'extra_value' => null,
            'options' => null,
            'placeholder' => null,
            'help_text' => null,
            'validation_rules' => null,
            'is_required' => false,
            'sort_order' => fake()->numberBetween(1, 20),
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }

    public function required(): static
    {
        return $this->state(fn () => ['is_required' => true]);
    }

    public function file(): static
    {
        return $this->state(fn () => ['type' => 'file', 'value' => null]);
    }

    public function radio(array $options): static
    {
        return $this->state(fn () => ['type' => 'radio', 'options' => $options]);
    }

    public function selectbox(array $options): static
    {
        return $this->state(fn () => ['type' => 'selectbox', 'options' => $options]);
    }

    public function checkbox(array $options): static
    {
        return $this->state(fn () => ['type' => 'checkbox', 'options' => $options, 'value' => '[]']);
    }

    public function group(string $groupName): static
    {
        return $this->state(fn () => ['group_name' => $groupName]);
    }
}
