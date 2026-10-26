<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_always_gets_the_end_user_role(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ahmed Broker',
            'email' => 'ahmed@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'manager',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ahmed Broker')
            ->assertJsonPath('data.email', 'ahmed@example.com')
            ->assertJsonPath('data.role', 'end_user')
            ->assertJsonStructure(['token']);

        $user = User::where('email', 'ahmed@example.com')->first();
        $this->assertTrue($user->hasRole('end_user'));
        $this->assertFalse($user->hasRole('manager'));
    }

    public function test_a_user_can_login_with_correct_credentials_and_receive_a_token(): void
    {
        $user = User::factory()->create(['email' => 'ahmed@example.com', 'password' => 'password123']);

        $response = $this->postJson('/api/login', [
            'email' => 'ahmed@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonStructure(['token']);
    }

    public function test_login_fails_with_incorrect_credentials(): void
    {
        User::factory()->create(['email' => 'ahmed@example.com', 'password' => 'password123']);

        $this->postJson('/api/login', [
            'email' => 'ahmed@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    public function test_an_authenticated_user_can_fetch_their_profile_and_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertNoContent();

        // Sanctum's RequestGuard caches the resolved user for the test's
        // lifetime; force it to re-resolve so the deleted token is re-checked.
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_guests_cannot_access_protected_routes(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited_after_ten_attempts_per_minute(): void
    {
        User::factory()->create(['email' => 'ahmed@example.com', 'password' => 'password123']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['email' => 'ahmed@example.com', 'password' => 'wrong-pass'])
                ->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'ahmed@example.com', 'password' => 'wrong-pass'])
            ->assertStatus(429);
    }

    public function test_the_login_limit_is_per_email_so_other_users_can_still_log_in(): void
    {
        User::factory()->create(['email' => 'ahmed@example.com', 'password' => 'password123']);
        User::factory()->create(['email' => 'sara@example.com', 'password' => 'password123']);

        for ($i = 0; $i < 11; $i++) {
            $this->postJson('/api/login', ['email' => 'ahmed@example.com', 'password' => 'wrong-pass']);
        }

        $this->postJson('/api/login', ['email' => 'sara@example.com', 'password' => 'password123'])
            ->assertOk();
    }
}
