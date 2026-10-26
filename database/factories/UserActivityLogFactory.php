<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserActivityLog>
 */
class UserActivityLogFactory extends Factory
{
    protected $model = UserActivityLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'activity_date' => now()->toDateString(),
        ];
    }
}
