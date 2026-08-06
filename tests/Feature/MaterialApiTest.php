<?php

namespace Tests\Feature;

use App\Models\Material;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_can_be_created_and_defaults_to_draft(): void
    {
        $payload = $this->payload();
        unset($payload['status']);

        $response = $this->postJson('/api/materials', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Contract Law Basics')
            ->assertJsonPath('data.summary', 'Core principles of real estate contracts under UAE law.')
            ->assertJsonPath('data.category', 'Legal')
            ->assertJsonPath('data.tags.0', 'contracts')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.difficulty_level', 'Medium')
            ->assertJsonPath('data.estimated_time', '20 min')
            ->assertJsonPath('data.xp', 50)
            ->assertJsonPath('data.stages.0.id', 'stage-1')
            ->assertJsonPath('data.stages.0.title', 'What Makes a Contract Valid');

        $this->assertDatabaseHas('materials', [
            'title' => 'Contract Law Basics',
            'category' => 'Legal',
            'status' => 'draft',
        ]);
    }

    public function test_materials_can_be_listed_and_shown(): void
    {
        $material = Material::create($this->payload());

        $this->getJson('/api/materials')
            ->assertOk()
            ->assertJsonPath('data.0.id', $material->id)
            ->assertJsonPath('data.0.title', 'Contract Law Basics');

        $this->getJson("/api/materials/{$material->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $material->id)
            ->assertJsonPath('data.category', 'Legal');
    }

    public function test_materials_index_includes_status_counts_and_can_be_filtered(): void
    {
        Material::factory()->count(3)->create(['status' => 'draft', 'category' => 'Legal']);
        Material::factory()->count(2)->create(['status' => 'published', 'category' => 'Sales']);

        $this->getJson('/api/materials?per_page=10')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.status_counts.draft', 3)
            ->assertJsonPath('meta.status_counts.published', 2)
            ->assertJsonPath('meta.status_counts.archived', 0);

        $this->getJson('/api/materials?status=published')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/materials?category=Legal')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_materials_can_be_searched_by_title_or_summary(): void
    {
        Material::factory()->create(['title' => 'Contract Law Basics', 'summary' => 'Real estate contracts.']);
        Material::factory()->create(['title' => 'Sales Negotiation', 'summary' => 'Handling objections.']);

        $this->getJson('/api/materials?search=contract')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Contract Law Basics');
    }

    public function test_material_can_be_updated_with_a_full_stages_replacement_and_deleted(): void
    {
        $material = Material::create($this->payload());

        $response = $this->putJson("/api/materials/{$material->id}", [
            'status' => 'published',
            'stages' => [
                ['id' => 'stage-1', 'title' => 'Updated Stage', 'status' => 'complete', 'body' => 'New content'],
                ['id' => 'stage-2', 'title' => 'Second Stage', 'status' => 'incomplete', 'body' => ''],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.stages.0.title', 'Updated Stage')
            ->assertJsonPath('data.stages.1.id', 'stage-2');

        $this->deleteJson("/api/materials/{$material->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('materials', [
            'id' => $material->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'title' => 'Contract Law Basics',
            'summary' => 'Core principles of real estate contracts under UAE law.',
            'category' => 'Legal',
            'tags' => ['contracts', 'law'],
            'status' => 'draft',
            'difficulty_level' => 'Medium',
            'estimated_time' => '20 min',
            'xp' => 50,
            'stages' => [
                ['id' => 'stage-1', 'title' => 'What Makes a Contract Valid', 'status' => 'complete', 'body' => ''],
            ],
            'created_by' => 'Sara A.',
        ];
    }
}
