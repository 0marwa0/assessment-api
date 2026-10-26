<?php

namespace Database\Factories;

use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Roadmap>
 */
class RoadmapFactory extends Factory
{
    protected $model = Roadmap::class;

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
            'status' => $this->faker->randomElement(['draft', 'published']),
            'source' => $this->faker->randomElement(['manual', 'assessment_generated']),
            'created_by' => User::factory()->manager(),
        ];
    }
}
