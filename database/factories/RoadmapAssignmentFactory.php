<?php

namespace Database\Factories;

use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapAssignment>
 */
class RoadmapAssignmentFactory extends Factory
{
    protected $model = RoadmapAssignment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'roadmap_id' => Roadmap::factory(),
            'assigned_by' => User::factory()->manager(),
            'assigned_at' => now(),
        ];
    }
}
