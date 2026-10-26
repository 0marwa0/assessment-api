<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\PracticeSession;
use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
use App\Models\RoadmapNode;
use App\Models\Simulation;
use App\Models\User;
use App\Services\RoadmapProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// MG8: "Pass score to unlock next" on simulation steps (decision D-B).
class RoadmapMinScoreApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_min_score_is_saved_on_simulation_steps_and_returned(): void
    {
        $simulation = Simulation::factory()->create();
        $material = Material::factory()->create();

        $this->as(User::factory()->manager()->create())->postJson('/api/roadmaps', [
            'title' => 'Gated path',
            'description' => 'x',
            'nodes' => [
                ['type' => 'material', 'material_id' => $material->id, 'min_score' => 50, 'position' => 0],
                ['type' => 'simulation', 'simulation_id' => $simulation->id, 'min_score' => 75, 'position' => 1],
            ],
        ])
            ->assertCreated()
            // Only meaningful on simulations: ignored on other step types.
            ->assertJsonPath('data.nodes.0.min_score', null)
            ->assertJsonPath('data.nodes.1.min_score', 75);

        $this->assertDatabaseHas('roadmap_nodes', ['simulation_id' => $simulation->id, 'min_score' => 75]);
    }

    public function test_min_score_must_be_between_0_and_100(): void
    {
        $simulation = Simulation::factory()->create();
        $manager = User::factory()->manager()->create();

        foreach ([101, -1, 'abc'] as $bad) {
            $this->as($manager)->postJson('/api/roadmaps', [
                'title' => 'Gated path',
                'description' => 'x',
                'nodes' => [['type' => 'simulation', 'simulation_id' => $simulation->id, 'min_score' => $bad]],
            ])->assertUnprocessable()->assertJsonValidationErrors('nodes.0.min_score');
        }

        $roadmap = Roadmap::factory()->create();
        $this->as($manager)->putJson("/api/roadmaps/{$roadmap->id}", [
            'nodes' => [['type' => 'simulation', 'simulation_id' => $simulation->id, 'min_score' => 200]],
        ])->assertUnprocessable()->assertJsonValidationErrors('nodes.0.min_score');

        $this->as($manager)->putJson("/api/roadmaps/{$roadmap->id}", [
            'nodes' => [['type' => 'simulation', 'simulation_id' => $simulation->id, 'min_score' => null]],
        ])->assertOk()->assertJsonPath('data.nodes.0.min_score', null);
    }

    public function test_gated_step_is_not_completed_when_the_call_is_saved_without_a_score(): void
    {
        [$me, $simulation, $gated, $open] = $this->gatedAndOpenSteps(80);

        $this->as($me)->postJson('/api/practice-sessions', $this->callPayload($simulation))->assertCreated();

        $this->assertNotDone($me, $gated);
        $this->assertDone($me, $open); // ungated behaviour unchanged
    }

    public function test_ai_score_at_or_above_min_completes_the_gated_step(): void
    {
        [$me, $simulation, $gated] = $this->gatedAndOpenSteps(80);
        $session = PracticeSession::factory()->create(['user_id' => $me->id, 'simulation_id' => $simulation->id]);

        // What generateAiReport does after the AI report is saved (Bedrock is not called in tests).
        $session->update(['score' => 79]);
        app(RoadmapProgress::class)->completeForSession($session);
        $this->assertNotDone($me, $gated);

        $session->update(['score' => 80]);
        app(RoadmapProgress::class)->completeForSession($session);
        $this->assertDone($me, $gated);
    }

    public function test_manager_grading_completes_the_gated_step_only_when_high_enough(): void
    {
        [$me, $simulation, $gated] = $this->gatedAndOpenSteps(70);
        $manager = User::factory()->manager()->create();
        $session = PracticeSession::factory()->create(['user_id' => $me->id, 'simulation_id' => $simulation->id]);

        $this->as($manager)->patchJson("/api/practice-sessions/{$session->id}", ['score' => 60])->assertOk();
        $this->assertNotDone($me, $gated);

        $this->as($manager)->patchJson("/api/practice-sessions/{$session->id}", ['score' => 85])->assertOk();
        $this->assertDone($me, $gated);

        // Only the session's own broker is credited, never the grading manager.
        $this->assertDatabaseMissing('roadmap_node_progress', ['user_id' => $manager->id]);
    }

    public function test_manager_review_final_score_completes_the_gated_step(): void
    {
        [$me, $simulation, $gated] = $this->gatedAndOpenSteps(90);
        $manager = User::factory()->manager()->create();
        $session = PracticeSession::factory()->create(['user_id' => $me->id, 'simulation_id' => $simulation->id]);

        $this->as($manager)->postJson("/api/practice-sessions/{$session->id}/review", ['final_score' => 50])->assertOk();
        $this->assertNotDone($me, $gated);

        $this->as($manager)->postJson("/api/practice-sessions/{$session->id}/review", ['final_score' => 95])->assertOk();
        $this->assertDone($me, $gated);
    }

    /**
     * One roadmap assigned to a new broker with two steps for the same simulation:
     * one gated at $minScore and one open.
     *
     * @return array{0: User, 1: Simulation, 2: RoadmapNode, 3: RoadmapNode}
     */
    private function gatedAndOpenSteps(int $minScore): array
    {
        $me = User::factory()->create();
        $simulation = Simulation::factory()->create();
        $roadmap = Roadmap::factory()->create(['status' => 'published']);
        $gated = RoadmapNode::create([
            'roadmap_id' => $roadmap->id, 'type' => 'simulation', 'simulation_id' => $simulation->id,
            'material_id' => null, 'min_score' => $minScore, 'position' => 0,
        ]);
        $open = RoadmapNode::create([
            'roadmap_id' => $roadmap->id, 'type' => 'simulation', 'simulation_id' => $simulation->id,
            'material_id' => null, 'min_score' => null, 'position' => 1,
        ]);
        RoadmapAssignment::factory()->create(['user_id' => $me->id, 'roadmap_id' => $roadmap->id]);

        return [$me, $simulation, $gated, $open];
    }

    /** @return array<string, mixed> */
    private function callPayload(Simulation $simulation): array
    {
        return [
            'simulation_id' => (string) $simulation->id,
            'simulation_title' => 'Call',
            'simulation_level' => 'Beginner',
            'started_at' => '2026-10-23T10:00:00Z',
            'ended_at' => '2026-10-23T10:05:00Z',
            'duration_seconds' => 300,
            'transcript' => [['role' => 'user', 'text' => 'Hello']],
        ];
    }

    private function assertDone(User $user, RoadmapNode $node): void
    {
        $this->assertDatabaseHas('roadmap_node_progress', ['user_id' => $user->id, 'roadmap_node_id' => $node->id, 'status' => 'done']);
    }

    private function assertNotDone(User $user, RoadmapNode $node): void
    {
        $this->assertDatabaseMissing('roadmap_node_progress', ['user_id' => $user->id, 'roadmap_node_id' => $node->id, 'status' => 'done']);
    }

    private function as(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
