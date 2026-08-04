<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Block;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'block_id' => Block::factory(),
            'question_text' => $this->faker->sentence(),
            'type' => 'mcq',
            'options' => [
                ['label' => 'A', 'text' => $this->faker->words(3, true), 'is_correct' => true],
                ['label' => 'B', 'text' => $this->faker->words(3, true), 'is_correct' => false],
            ],
            'difficulty_level' => $this->faker->randomElement(['Easy', 'Medium', 'Hard']),
            'reward' => $this->faker->numberBetween(0, 100),
            'required_to_pass_block' => $this->faker->boolean(),
            'answer_explanation' => $this->faker->sentence(),
            'position' => 0,
        ];
    }
}
