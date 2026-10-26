<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\Roadmap;
use App\Models\RoadmapNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapNode>
 */
class RoadmapNodeFactory extends Factory
{
    protected $model = RoadmapNode::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'roadmap_id' => Roadmap::factory(),
            'material_id' => Material::factory(),
            'position' => $this->faker->unique()->numberBetween(0, 100000),
        ];
    }
}
