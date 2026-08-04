<?php

namespace Database\Factories;

use App\Models\Assessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assessment>
 */
class AssessmentFactory extends Factory
{
    protected $model = Assessment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'type' => $this->faker->randomElement(['practice', 'quiz', 'survey']),
            'status' => $this->faker->randomElement(['draft', 'published', 'archived']),
            'scoring_rules' => [
                'score_unit' => 'percent',
                'junior_max_score' => 59,
                'senior_min_score' => 85,
                'hard_gates_enabled' => true,
            ],
            'usage_analytics' => [
                'times_used' => 124,
                'completion_rate_percent' => 92,
                'average_score_percent' => 78,
                'completed_attempts' => 113,
                'started_attempts' => 123,
                'last_attempted_at' => '2026-08-04T10:30:00Z',
            ],
        ];
    }
}
