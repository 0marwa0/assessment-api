<?php

namespace Database\Factories;

use App\Models\RoadmapNode;
use App\Models\RoadmapNodeProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapNodeProgress>
 */
class RoadmapNodeProgressFactory extends Factory
{
    protected $model = RoadmapNodeProgress::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'roadmap_node_id' => RoadmapNode::factory(),
            'status' => 'not_started',
            'completed_at' => null,
        ];
    }
}
