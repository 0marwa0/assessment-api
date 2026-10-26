<?php

namespace Database\Factories;

use App\Models\Simulation;
use App\Models\SimulationAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationAssignment>
 */
class SimulationAssignmentFactory extends Factory
{
    protected $model = SimulationAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'simulation_id' => Simulation::factory(),
            'assigned_by' => User::factory()->manager(),
            'assigned_at' => now(),
        ];
    }
}
