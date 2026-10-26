<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_list_and_view_end_users(): void
    {
        $manager = User::factory()->manager()->create();
        User::factory()->count(3)->create();

        $this->actingAsUser($manager)
            ->getJson('/api/users?role=end_user')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $endUser = User::role('end_user')->first();

        $this->actingAsUser($manager)
            ->getJson("/api/users/{$endUser->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $endUser->id);
    }

    public function test_a_manager_can_provision_a_new_user_with_a_chosen_role(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAsUser($manager)->postJson('/api/users', [
            'name' => 'New Coach',
            'email' => 'coach@example.com',
            'password' => 'password123',
            'role' => 'manager',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.email', 'coach@example.com')
            ->assertJsonPath('data.role', 'manager');

        $user = User::where('email', 'coach@example.com')->first();
        $this->assertTrue($user->hasRole('manager'));
    }

    public function test_a_manager_can_change_an_end_users_role(): void
    {
        $manager = User::factory()->manager()->create();
        $endUser = User::factory()->create();

        $this->actingAsUser($manager)
            ->patchJson("/api/users/{$endUser->id}/role", ['role' => 'manager'])
            ->assertOk()
            ->assertJsonPath('data.role', 'manager');

        $this->assertTrue($endUser->fresh()->hasRole('manager'));
        $this->assertFalse($endUser->fresh()->hasRole('end_user'));
    }

    public function test_an_end_user_cannot_access_user_management_routes(): void
    {
        $endUser = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAsUser($endUser)
            ->getJson('/api/users')
            ->assertForbidden();

        $this->actingAsUser($endUser)
            ->patchJson("/api/users/{$otherUser->id}/role", ['role' => 'manager'])
            ->assertForbidden();
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
