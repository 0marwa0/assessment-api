<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\AssessmentAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssessmentAssignment>
 */
class AssessmentAssignmentFactory extends Factory
{
    protected $model = AssessmentAssignment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'assessment_id' => Assessment::factory(),
            'assigned_by' => User::factory()->manager(),
            'assigned_at' => now(),
        ];
    }
}
