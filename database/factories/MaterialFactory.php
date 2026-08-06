<?php

namespace Database\Factories;

use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    protected $model = Material::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'summary' => $this->faker->paragraph(),
            'category' => $this->faker->randomElement(['Legal', 'Sales', 'Finance']),
            'tags' => $this->faker->randomElements(['contracts', 'law', 'negotiation', 'compliance'], 2),
            'status' => $this->faker->randomElement(['draft', 'published', 'archived']),
            'difficulty_level' => $this->faker->randomElement(['Easy', 'Medium', 'Hard']),
            'estimated_time' => $this->faker->randomElement(['10 min', '20 min', '30 min']),
            'xp' => $this->faker->numberBetween(10, 100),
            'stages' => [
                ['id' => 'stage-1', 'title' => 'Introduction', 'status' => 'complete', 'body' => ''],
            ],
            'created_by' => $this->faker->name(),
        ];
    }
}
