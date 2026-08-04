<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Block;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Block>
 */
class BlockFactory extends Factory
{
    protected $model = Block::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'title' => $this->faker->sentence(3),
            'position' => 0,
        ];
    }
}
