<?php

namespace Tests\Feature;

use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoadmapAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_assign_a_roadmap_to_an_end_user(): void
    {
        $manager = User::factory()->manager()->create();
        $endUser = User::factory()->create();
        $roadmap = Roadmap::factory()->create();

        $response = $this->actingAsUser($manager)->postJson('/api/roadmap-assignments', [
            'user_id' => $endUser->id,
            'roadmap_id' => $roadmap->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.user_id', $endUser->id)
            ->assertJsonPath('data.0.roadmap_id', $roadmap->id)
            ->assertJsonPath('data.0.assigned_by', $manager->id)
            ->assertJsonPath('data.0.roadmap.title', $roadmap->title);

        $this->assertDatabaseHas('roadmap_assignments', [
            'user_id' => $endUser->id,
            'roadmap_id' => $roadmap->id,
            'assigned_by' => $manager->id,
        ]);
    }

    // Since R8 a duplicate is skipped (so "All brokers" works when some already have it).
    public function test_the_same_roadmap_is_not_assigned_to_the_same_user_twice(): void
    {
        $manager = User::factory()->manager()->create();
        $endUser = User::factory()->create();
        $roadmap = Roadmap::factory()->create();
        RoadmapAssignment::factory()->create(['user_id' => $endUser->id, 'roadmap_id' => $roadmap->id]);

        $this->actingAsUser($manager)->postJson('/api/roadmap-assignments', [
            'user_id' => $endUser->id,
            'roadmap_id' => $roadmap->id,
        ])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('skipped_user_ids.0', $endUser->id);

        $this->assertSame(1, RoadmapAssignment::query()->where('user_id', $endUser->id)->where('roadmap_id', $roadmap->id)->count());
    }

    public function test_an_end_user_cannot_assign_a_roadmap(): void
    {
        $endUser = User::factory()->create();
        $otherUser = User::factory()->create();
        $roadmap = Roadmap::factory()->create();

        $this->actingAsUser($endUser)->postJson('/api/roadmap-assignments', [
            'user_id' => $otherUser->id,
            'roadmap_id' => $roadmap->id,
        ])->assertForbidden();
    }

    public function test_a_broker_can_list_their_own_assigned_roadmaps(): void
    {
        $endUser = User::factory()->create();
        $mine = RoadmapAssignment::factory()->create(['user_id' => $endUser->id]);
        RoadmapAssignment::factory()->create(); // someone else's assignment

        $this->actingAsUser($endUser)
            ->getJson("/api/roadmap-assignments?user_id={$endUser->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.roadmap.id', $mine->roadmap_id);
    }

    public function test_assignments_can_be_filtered_by_roadmap_id(): void
    {
        $roadmap = Roadmap::factory()->create();
        RoadmapAssignment::factory()->count(2)->create(['roadmap_id' => $roadmap->id]);
        RoadmapAssignment::factory()->create();

        $this->actingAsUser(User::factory()->manager()->create())
            ->getJson("/api/roadmap-assignments?roadmap_id={$roadmap->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_listing_assignments_requires_authentication(): void
    {
        $this->getJson('/api/roadmap-assignments')->assertUnauthorized();
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
