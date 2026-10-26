<?php

namespace Tests\Feature;

use App\Models\PracticeSession;
use App\Models\Roadmap;
use App\Models\RoadmapNode;
use App\Models\Simulation;
use App\Models\User;
use App\Services\SimulationScorecard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MG9 Simulation Library + R3 weighted scorecard.
 */
class SimulationLibraryMg9ApiTest extends TestCase
{
    use RefreshDatabase;

    private function asManager(): static
    {
        $token = User::factory()->manager()->create()->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function asBroker(): static
    {
        $token = User::factory()->create()->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_a_weighted_scorecard_is_stored_and_returned(): void
    {
        $scorecard = [['name' => 'Discovery questions', 'weight' => 40], ['name' => 'Handles price objection', 'weight' => 60]];

        $this->asManager()->postJson('/api/simulations', [...$this->payload(), 'scorecard' => $scorecard])
            ->assertCreated()
            ->assertJsonPath('data.scorecard', $scorecard);

        $this->assertSame($scorecard, Simulation::first()->scorecard);
    }

    public function test_scorecard_weights_must_add_up_to_100(): void
    {
        $this->asManager()->postJson('/api/simulations', [...$this->payload(), 'scorecard' => [
            ['name' => 'Discovery', 'weight' => 40], ['name' => 'Closing', 'weight' => 50],
        ]])->assertUnprocessable()->assertJsonValidationErrors('scorecard');

        $simulation = Simulation::factory()->create();
        $this->patchJson("/api/simulations/{$simulation->id}", ['scorecard' => [
            ['name' => '', 'weight' => 100],
        ]])->assertUnprocessable()->assertJsonValidationErrors('scorecard.0.name');

        $this->patchJson("/api/simulations/{$simulation->id}", ['scorecard' => [
            ['name' => 'Closing', 'weight' => 0], ['name' => 'Discovery', 'weight' => 100],
        ]])->assertUnprocessable()->assertJsonValidationErrors('scorecard.0.weight');

        // Clearing the scorecard falls back to the fixed rubric.
        $this->patchJson("/api/simulations/{$simulation->id}", ['scorecard' => null])->assertOk()->assertJsonPath('data.scorecard', null);
    }

    public function test_moods_keep_their_stored_values(): void
    {
        foreach (['nice', 'less_rude', 'rude', 'sassy'] as $tone) {
            $this->asManager()->postJson('/api/simulations', [...$this->payload(), 'persona_tone' => $tone])
                ->assertCreated()->assertJsonPath('data.persona_tone', $tone);
        }

        $this->postJson('/api/simulations', [...$this->payload(), 'persona_tone' => 'skeptical'])
            ->assertUnprocessable()->assertJsonValidationErrors('persona_tone');
    }

    public function test_managers_see_calls_and_roadmap_counts_brokers_do_not(): void
    {
        $simulation = Simulation::factory()->create();
        PracticeSession::factory()->count(3)->create(['simulation_id' => $simulation->id]);
        $roadmap = Roadmap::factory()->create();
        RoadmapNode::factory()->count(2)->create([
            'roadmap_id' => $roadmap->id, 'type' => 'simulation', 'simulation_id' => $simulation->id, 'material_id' => null,
        ]);

        $this->asManager()->getJson('/api/simulations')
            ->assertOk()
            ->assertJsonPath('data.0.calls_count', 3)
            ->assertJsonPath('data.0.roadmaps_count', 1);
        $this->getJson("/api/simulations/{$simulation->id}")->assertJsonPath('data.calls_count', 3);

        $this->asBroker()->getJson('/api/simulations')
            ->assertOk()
            ->assertJsonMissingPath('data.0.calls_count');
    }

    public function test_a_simulation_used_in_a_roadmap_cannot_be_deleted(): void
    {
        $simulation = Simulation::factory()->create();
        RoadmapNode::factory()->create(['type' => 'simulation', 'simulation_id' => $simulation->id, 'material_id' => null]);

        $this->asManager()->deleteJson("/api/simulations/{$simulation->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', "It's used in 1 roadmap. Remove it from there first.");
    }

    public function test_scorecard_draft_is_manager_only_and_validated(): void
    {
        $simulation = Simulation::factory()->create();

        $this->asBroker()->postJson('/api/simulations/scorecard-draft', ['scenario_brief' => 'x'])->assertForbidden();
        $this->postJson("/api/simulations/{$simulation->id}/scorecard-draft")->assertForbidden();

        $this->asManager()->postJson('/api/simulations/scorecard-draft', [])
            ->assertUnprocessable()->assertJsonValidationErrors('scenario_brief');

        // No Bedrock model configured → clear 500 (never calls AWS in tests).
        config(['services.bedrock.model_id' => null]);
        $this->postJson("/api/simulations/{$simulation->id}/scorecard-draft")
            ->assertStatus(500)
            ->assertJsonPath('message', 'BEDROCK_MODEL_ID is not configured on the server, so the AI scorecard draft is unavailable.');
    }

    public function test_report_prompt_and_schema_use_custom_criteria(): void
    {
        $service = new SimulationScorecard;
        $criteria = $service->criteria([['name' => 'Rapport', 'weight' => 30], ['name' => 'Price objection', 'weight' => 70]]);

        $prompt = $service->reportPrompt($criteria);
        $this->assertStringContainsString('"Rapport" (weight 30%)', $prompt);
        $this->assertStringContainsString('"Price objection" (weight 70%)', $prompt);
        $this->assertStringNotContainsString('Social Proof', $prompt);
        $this->assertSame(['Rapport', 'Price objection'], $service->reportSchema($criteria)['items']['properties']['name']['enum']);

        // No scorecard → the fixed 5-category rubric.
        $this->assertNull($service->criteria(null));
        $this->assertStringContainsString('Social Proof', $service->reportPrompt(null));
        $this->assertSame(SimulationScorecard::FIXED_CATEGORIES, $service->reportSchema(null)['items']['properties']['name']['enum']);
    }

    public function test_weighted_score_is_the_weighted_average_of_pass_rates(): void
    {
        $service = new SimulationScorecard;
        $criteria = [['name' => 'Rapport', 'weight' => 30], ['name' => 'Price objection', 'weight' => 70]];
        $report = ['score' => 12, 'scorecard' => [
            ['name' => 'Rapport', 'items' => [['label' => 'a', 'passed' => true], ['label' => 'b', 'passed' => false]]],
            ['name' => 'Price objection', 'items' => [['label' => 'c', 'passed' => true]]],
        ]];

        $weighted = $service->applyWeights($report, $criteria);

        $this->assertSame(85, $weighted['score']); // 0.5*30 + 1*70
        $this->assertSame([30, 70], array_column($weighted['scorecard'], 'weight'));
        $this->assertSame($report, $service->applyWeights($report, null));
        $this->assertSame(100, array_sum(array_column($service->normalizeWeights([
            ['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1], ['name' => 'c', 'weight' => 1],
        ]), 'weight')));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'title' => 'Price objection — off-plan',
            'description' => 'Handle a buyer who thinks the price is too high.',
            'level' => 'Intermediate',
            'time_required' => '10 min',
            'status' => 'draft',
            'persona' => 'A careful first-time investor.',
            'persona_name' => 'Omar',
            'persona_gender' => 'male',
            'persona_tone' => 'nice',
            'scenario_brief' => 'Omar likes the project but thinks it is overpriced.',
        ];
    }
}
