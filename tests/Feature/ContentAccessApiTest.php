<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Roadmap;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backlog R1b: simulations, materials, roadmaps and uploads need a login;
 * brokers can read content but only managers can change it.
 */
class ContentAccessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_requires_login(): void
    {
        $simulation = Simulation::factory()->create();

        $this->getJson('/api/simulations')->assertUnauthorized();
        $this->getJson('/api/materials')->assertUnauthorized();
        $this->getJson('/api/roadmaps')->assertUnauthorized();
        $this->deleteJson("/api/simulations/{$simulation->id}")->assertUnauthorized();
        $this->postJson('/api/uploads')->assertUnauthorized();
        $this->postJson("/api/simulations/{$simulation->id}/test-sessions")->assertUnauthorized();
    }

    public function test_a_broker_can_read_content(): void
    {
        // R11: brokers only see published simulations.
        $simulation = Simulation::factory()->create(['status' => 'published']);
        $material = Material::factory()->create();
        $broker = $this->actingAsUser(User::factory()->create());

        $broker->getJson('/api/simulations')->assertOk();
        $broker->getJson("/api/simulations/{$simulation->id}")->assertOk();
        $broker->getJson('/api/materials')->assertOk();
        $broker->getJson("/api/materials/{$material->id}")->assertOk();
        $broker->getJson('/api/roadmaps')->assertOk();
    }

    public function test_a_broker_cannot_change_content(): void
    {
        $simulation = Simulation::factory()->create();
        $material = Material::factory()->create();
        $roadmap = Roadmap::factory()->create();
        $broker = $this->actingAsUser(User::factory()->create());

        $broker->deleteJson("/api/simulations/{$simulation->id}")->assertForbidden();
        $broker->putJson("/api/simulations/{$simulation->id}", [])->assertForbidden();
        $broker->postJson('/api/simulations', [])->assertForbidden();
        $broker->postJson("/api/simulations/{$simulation->id}/test-sessions")->assertForbidden();
        $broker->deleteJson("/api/materials/{$material->id}")->assertForbidden();
        $broker->postJson('/api/materials', [])->assertForbidden();
        $broker->deleteJson("/api/roadmaps/{$roadmap->id}")->assertForbidden();
        $broker->postJson('/api/uploads')->assertForbidden();

        $this->assertDatabaseHas('simulations', ['id' => $simulation->id]);
        $this->assertDatabaseHas('materials', ['id' => $material->id]);
        $this->assertDatabaseHas('roadmaps', ['id' => $roadmap->id]);
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
