<?php

namespace Database\Factories;

use App\Models\Simulation;
use App\Models\SimulationTestSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationTestSession>
 */
class SimulationTestSessionFactory extends Factory
{
    protected $model = SimulationTestSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'simulation_id' => Simulation::factory(),
            'status' => 'active',
            'started_at' => now(),
            'completed_at' => null,
        ];
    }
}
