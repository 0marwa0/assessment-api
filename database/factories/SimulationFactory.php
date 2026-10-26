<?php

namespace Database\Factories;

use App\Models\Simulation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Simulation>
 */
class SimulationFactory extends Factory
{
    protected $model = Simulation::class;

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
            'level' => $this->faker->randomElement(['Beginner', 'Intermediate', 'Advanced']),
            'time_required' => $this->faker->randomElement(['8-10 min', '12-18 min', '20-25 min']),
            'status' => 'published', // tests that need a draft set it explicitly
            'opening_message' => $this->faker->sentence(),
            'persona' => $this->faker->paragraph(),
            'scenario_brief' => $this->faker->paragraph(),
            'language' => $this->faker->randomElement(['en', 'ar']),
        ];
    }
}
