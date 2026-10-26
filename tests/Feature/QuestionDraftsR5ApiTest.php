<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backlog R5: AI draft questions from materials. Never calls AWS in tests.
 */
class QuestionDraftsR5ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_drafts_are_manager_only_and_validated(): void
    {
        $material = Material::factory()->create(['content' => '<p>Escrow protects buyer funds.</p>']);
        $valid = ['material_ids' => [$material->id], 'count' => 5, 'types' => ['mcq', 'true_false'], 'difficulty' => 'mixed'];

        $this->postJson('/api/assessment-question-drafts', $valid)->assertUnauthorized();
        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-question-drafts', $valid)->assertForbidden();

        $manager = User::factory()->manager()->create();
        $this->actingAsUser($manager)->postJson('/api/assessment-question-drafts', [
            'material_ids' => [999999], 'count' => 50, 'types' => ['essay'], 'difficulty' => 'expert',
        ])->assertUnprocessable()->assertJsonValidationErrors(['material_ids.0', 'count', 'types.0', 'difficulty']);

        config(['services.bedrock.model_id' => null]);
        $this->actingAsUser($manager)->postJson('/api/assessment-question-drafts', $valid)
            ->assertStatus(500)
            ->assertJsonPath('message', 'BEDROCK_MODEL_ID is not configured on the server, so AI question drafts are unavailable.');
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
