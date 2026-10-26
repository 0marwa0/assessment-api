<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\XpAward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<XpAward>
 */
class XpAwardFactory extends Factory
{
    protected $model = XpAward::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => $this->faker->numberBetween(10, 300),
            'source_type' => 'roadmap_node',
            'source_id' => $this->faker->numberBetween(1, 1000),
            'awarded_at' => now(),
        ];
    }
}
