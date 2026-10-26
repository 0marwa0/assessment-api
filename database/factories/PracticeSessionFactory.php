<?php

namespace Database\Factories;

use App\Models\PracticeSession;
use App\Models\Simulation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PracticeSession>
 */
class PracticeSessionFactory extends Factory
{
    protected $model = PracticeSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-1 week', 'now');
        $endedAt = (clone $startedAt)->modify('+'.$this->faker->numberBetween(60, 900).' seconds');

        return [
            'simulation_id' => Simulation::factory(),
            'simulation_title' => $this->faker->sentence(3),
            'simulation_level' => $this->faker->randomElement(['Beginner', 'Intermediate', 'Advanced']),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $endedAt->getTimestamp() - $startedAt->getTimestamp(),
            'transcript' => [
                ['role' => 'agent', 'text' => $this->faker->sentence()],
                ['role' => 'user', 'text' => $this->faker->sentence()],
            ],
            'score' => null,
            'feedback' => null,
        ];
    }
}
