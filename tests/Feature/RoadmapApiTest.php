<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoadmapApiTest extends TestCase
{
    use RefreshDatabase;

    // Content routes need a login since R1b, and changes are manager-only.
    protected function setUp(): void
    {
        parent::setUp();

        $token = User::factory()->manager()->create()->createToken('api')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_roadmap_can_be_created_by_a_manager_and_defaults_to_draft_and_manual(): void
    {
        $manager = User::factory()->manager()->create();
        [$materialA, $materialB] = Material::factory()->count(2)->create();

        $payload = $this->payload($materialA->id, $materialB->id);
        unset($payload['status'], $payload['source']);

        $response = $this->actingAsUser($manager)->postJson('/api/roadmaps', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'New Broker Onboarding')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.created_by', $manager->id)
            ->assertJsonPath('data.nodes.0.position', 1)
            ->assertJsonPath('data.nodes.0.material_id', $materialA->id)
            ->assertJsonPath('data.nodes.1.position', 2)
            ->assertJsonPath('data.nodes.1.material_id', $materialB->id);

        $this->assertDatabaseHas('roadmaps', [
            'title' => 'New Broker Onboarding',
            'status' => 'draft',
            'source' => 'manual',
            'created_by' => $manager->id,
        ]);

        $this->assertDatabaseHas('roadmap_nodes', [
            'material_id' => $materialA->id,
            'position' => 1,
        ]);
    }

    public function test_roadmap_node_requires_an_existing_material(): void
    {
        $manager = User::factory()->manager()->create();
        $material = Material::factory()->create();

        $payload = $this->payload($material->id, $material->id + 999);

        $this->actingAsUser($manager)->postJson('/api/roadmaps', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nodes.1.material_id']);
    }

    public function test_an_end_user_cannot_create_a_roadmap(): void
    {
        $endUser = User::factory()->create();
        $material = Material::factory()->create();

        $this->actingAsUser($endUser)
            ->postJson('/api/roadmaps', $this->payload($material->id, $material->id))
            ->assertForbidden();
    }

    public function test_creating_a_roadmap_requires_authentication(): void
    {
        $material = Material::factory()->create();

        $this->flushHeaders()
            ->postJson('/api/roadmaps', $this->payload($material->id, $material->id))
            ->assertUnauthorized();
    }

    public function test_roadmaps_can_be_listed_and_shown(): void
    {
        $material = Material::factory()->create();
        $roadmap = Roadmap::create($this->payload($material->id, $material->id));
        $roadmap->nodes()->create(['material_id' => $material->id, 'position' => 1]);

        $this->getJson('/api/roadmaps')
            ->assertOk()
            ->assertJsonPath('data.0.id', $roadmap->id)
            ->assertJsonPath('data.0.title', 'New Broker Onboarding');

        $this->getJson("/api/roadmaps/{$roadmap->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $roadmap->id)
            ->assertJsonPath('data.nodes.0.material_id', $material->id);
    }

    public function test_roadmaps_index_includes_status_counts_and_can_be_filtered(): void
    {
        Roadmap::factory()->count(3)->create(['status' => 'draft', 'source' => 'manual']);
        Roadmap::factory()->count(2)->create(['status' => 'published', 'source' => 'assessment_generated']);

        $this->getJson('/api/roadmaps?per_page=10')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.status_counts.draft', 3)
            ->assertJsonPath('meta.status_counts.published', 2);

        $this->getJson('/api/roadmaps?status=published')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/roadmaps?source=assessment_generated')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_roadmaps_can_be_searched_by_title_or_description(): void
    {
        Roadmap::factory()->create(['title' => 'New Broker Onboarding', 'description' => 'Baseline legal path.']);
        Roadmap::factory()->create(['title' => 'Sales Mastery', 'description' => 'Advanced negotiation.']);

        $this->getJson('/api/roadmaps?search=broker')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'New Broker Onboarding');
    }

    public function test_roadmap_can_be_updated_with_a_full_nodes_replacement_and_deleted(): void
    {
        $manager = User::factory()->manager()->create();
        $materials = Material::factory()->count(3)->create();
        $roadmap = Roadmap::create($this->payload($materials[0]->id, $materials[1]->id));
        $roadmap->nodes()->create(['material_id' => $materials[0]->id, 'position' => 1]);
        $roadmap->nodes()->create(['material_id' => $materials[1]->id, 'position' => 2]);

        $response = $this->actingAsUser($manager)->putJson("/api/roadmaps/{$roadmap->id}", [
            'status' => 'published',
            'nodes' => [
                ['position' => 1, 'type' => 'material', 'material_id' => $materials[2]->id],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonCount(1, 'data.nodes')
            ->assertJsonPath('data.nodes.0.material_id', $materials[2]->id);

        $this->assertDatabaseCount('roadmap_nodes', 1);

        $this->deleteJson("/api/roadmaps/{$roadmap->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('roadmaps', [
            'id' => $roadmap->id,
        ]);
    }

    public function test_an_end_user_cannot_update_a_roadmap(): void
    {
        $endUser = User::factory()->create();
        $material = Material::factory()->create();
        $roadmap = Roadmap::create($this->payload($material->id, $material->id));

        $this->actingAsUser($endUser)
            ->putJson("/api/roadmaps/{$roadmap->id}", ['status' => 'published'])
            ->assertForbidden();
    }

    public function test_material_referenced_by_a_roadmap_cannot_be_deleted(): void
    {
        $material = Material::factory()->create();
        $roadmap = Roadmap::create($this->payload($material->id, $material->id));
        $roadmap->nodes()->create(['material_id' => $material->id, 'position' => 1]);

        $this->deleteJson("/api/materials/{$material->id}")
            ->assertStatus(500);

        $this->assertDatabaseHas('materials', ['id' => $material->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $materialAId, int $materialBId): array
    {
        return [
            'title' => 'New Broker Onboarding',
            'description' => 'Baseline legal + sales-skills path for brokers in their first 90 days.',
            'status' => 'draft',
            'source' => 'manual',
            'nodes' => [
                ['position' => 1, 'type' => 'material', 'material_id' => $materialAId],
                ['position' => 2, 'type' => 'material', 'material_id' => $materialBId],
            ],
        ];
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
