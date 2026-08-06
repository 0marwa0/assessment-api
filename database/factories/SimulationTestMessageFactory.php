<?php

namespace Database\Factories;

use App\Models\SimulationTestMessage;
use App\Models\SimulationTestSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationTestMessage>
 */
class SimulationTestMessageFactory extends Factory
{
    protected $model = SimulationTestMessage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'simulation_test_session_id' => SimulationTestSession::factory(),
            'role' => $this->faker->randomElement(['user', 'assistant', 'simulation']),
            'content' => $this->faker->sentence(),
            'position' => 0,
        ];
    }
}
