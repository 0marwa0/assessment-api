<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAssignment;
use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
use App\Models\RoadmapNode;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backlog R8: due dates, assigning to several / all brokers, retake limits,
 * assignment status, and steps that complete themselves.
 */
class AssignmentsR8ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_assign_to_several_brokers_with_a_due_date_and_retake_limit(): void
    {
        $assessment = $this->makeAssessment();
        [$a, $b] = User::factory()->count(2)->create();

        $this->as(User::factory()->manager()->create())->postJson('/api/assessment-assignments', [
            'assessment_id' => $assessment->id,
            'user_ids' => [$a->id, $b->id],
            'due_at' => '2026-11-01T17:00:00Z',
            'max_attempts' => 2,
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.due_at', '2026-11-01T17:00:00.000000Z')
            ->assertJsonPath('data.0.max_attempts', 2)
            ->assertJsonPath('message', 'Assigned to 2 brokers.');
    }

    public function test_all_brokers_skips_people_who_already_have_it_and_never_includes_managers(): void
    {
        $roadmap = Roadmap::factory()->create();
        $has = User::factory()->create();
        User::factory()->count(2)->create();
        RoadmapAssignment::factory()->create(['user_id' => $has->id, 'roadmap_id' => $roadmap->id]);
        $manager = User::factory()->manager()->create();

        $this->as($manager)->postJson('/api/roadmap-assignments', ['roadmap_id' => $roadmap->id, 'all_brokers' => true])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('skipped_user_ids.0', $has->id)
            ->assertJsonPath('message', 'Assigned to 2 brokers. 1 already had it.');

        $this->assertDatabaseMissing('roadmap_assignments', ['user_id' => $manager->id]);
    }

    public function test_a_broker_only_sees_their_own_assignments(): void
    {
        $assessment = $this->makeAssessment();
        $me = User::factory()->create();
        $other = User::factory()->create();
        AssessmentAssignment::create(['user_id' => $me->id, 'assessment_id' => $assessment->id, 'assigned_at' => now()]);
        AssessmentAssignment::create(['user_id' => $other->id, 'assessment_id' => $assessment->id, 'assigned_at' => now()]);

        $this->as($me)->getJson("/api/assessment-assignments?user_id={$other->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $me->id);
    }

    public function test_assignment_status_reports_attempts_and_passing(): void
    {
        $assessment = $this->makeAssessment(pass: 50);
        $me = User::factory()->create();
        AssessmentAssignment::create(['user_id' => $me->id, 'assessment_id' => $assessment->id, 'assigned_at' => now()]);
        $q = $assessment->blocks()->first()->questions()->first();

        $this->as($me)->postJson('/api/assessment-attempts', ['assessment_id' => $assessment->id, 'answers' => [$q->id => 1], 'elapsed_seconds' => 5]);
        $this->as($me)->getJson('/api/assessment-assignments')
            ->assertJsonPath('data.0.attempts_count', 1)
            ->assertJsonPath('data.0.passed', false);

        $this->as($me)->postJson('/api/assessment-attempts', ['assessment_id' => $assessment->id, 'answers' => [$q->id => 0], 'elapsed_seconds' => 5]);
        $this->as($me)->getJson('/api/assessment-assignments')
            ->assertJsonPath('data.0.attempts_count', 2)
            ->assertJsonPath('data.0.passed', true)
            ->assertJsonPath('data.0.best_score', 100)
            ->assertJsonPath('data.0.last_attempt.tier', 'Senior');
    }

    public function test_the_retake_limit_is_enforced(): void
    {
        $assessment = $this->makeAssessment();
        $me = User::factory()->create();
        $assignment = AssessmentAssignment::create([
            'user_id' => $me->id, 'assessment_id' => $assessment->id, 'assigned_at' => now(), 'max_attempts' => 1,
        ]);
        $payload = ['assessment_id' => $assessment->id, 'assessment_assignment_id' => $assignment->id, 'answers' => [], 'elapsed_seconds' => 5];

        $this->as($me)->postJson('/api/assessment-attempts', $payload)->assertCreated();
        $this->as($me)->postJson('/api/assessment-attempts', $payload)->assertUnprocessable();
    }

    public function test_passing_an_assessment_completes_its_roadmap_step_and_awards_xp(): void
    {
        $assessment = $this->makeAssessment(pass: 50);
        $me = User::factory()->create();
        $node = $this->roadmapStepFor($me, 'assessment', $assessment->id);
        $q = $assessment->blocks()->first()->questions()->first();

        // Failing does nothing.
        $this->as($me)->postJson('/api/assessment-attempts', ['assessment_id' => $assessment->id, 'answers' => [$q->id => 1], 'elapsed_seconds' => 5]);
        $this->assertDatabaseMissing('roadmap_node_progress', ['user_id' => $me->id, 'roadmap_node_id' => $node->id, 'status' => 'done']);

        $this->as($me)->postJson('/api/assessment-attempts', ['assessment_id' => $assessment->id, 'answers' => [$q->id => 0], 'elapsed_seconds' => 5]);
        $this->assertDatabaseHas('roadmap_node_progress', ['user_id' => $me->id, 'roadmap_node_id' => $node->id, 'status' => 'done']);
        $this->assertDatabaseHas('xp_awards', ['user_id' => $me->id, 'source_type' => 'roadmap_node', 'source_id' => $node->id]);
    }

    public function test_finishing_a_practice_call_completes_its_roadmap_step(): void
    {
        $simulation = Simulation::factory()->create();
        $me = User::factory()->create();
        $node = $this->roadmapStepFor($me, 'simulation', $simulation->id);

        $this->as($me)->postJson('/api/practice-sessions', [
            'simulation_id' => (string) $simulation->id,
            'simulation_title' => 'Call',
            'simulation_level' => 'Beginner',
            'started_at' => '2026-10-23T10:00:00Z',
            'ended_at' => '2026-10-23T10:05:00Z',
            'duration_seconds' => 300,
            'transcript' => [['role' => 'user', 'text' => 'Hello']],
        ])->assertCreated();

        $this->assertDatabaseHas('roadmap_node_progress', ['user_id' => $me->id, 'roadmap_node_id' => $node->id, 'status' => 'done']);
    }

    public function test_a_broker_cannot_tick_off_a_simulation_step_by_hand_but_can_a_reading(): void
    {
        $me = User::factory()->create();
        $simNode = $this->roadmapStepFor($me, 'simulation', Simulation::factory()->create()->id);
        $readingNode = RoadmapNode::factory()->create(['roadmap_id' => $simNode->roadmap_id, 'type' => 'material', 'position' => 2]);

        $this->as($me)->patchJson("/api/roadmap-nodes/{$simNode->id}/progress", ['status' => 'done'])->assertUnprocessable();
        $this->as($me)->patchJson("/api/roadmap-nodes/{$readingNode->id}/progress", ["status" => "done"])->assertSuccessful();
    }

    public function test_a_broker_only_sees_their_own_progress(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $node = RoadmapNode::factory()->create(['type' => 'material']);
        $this->as($other)->patchJson("/api/roadmap-nodes/{$node->id}/progress", ['status' => 'done']);

        $this->as($me)->getJson("/api/roadmap-node-progress?user_id={$other->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function roadmapStepFor(User $user, string $type, int $referenceId): RoadmapNode
    {
        $roadmap = Roadmap::factory()->create();
        RoadmapAssignment::factory()->create(['user_id' => $user->id, 'roadmap_id' => $roadmap->id]);

        return RoadmapNode::factory()->create([
            'roadmap_id' => $roadmap->id,
            'type' => $type,
            'material_id' => null,
            "{$type}_id" => $referenceId,
            'position' => 1,
        ]);
    }

    private function makeAssessment(int $pass = 70): Assessment
    {
        $assessment = Assessment::create([
            'title' => 'Basics', 'description' => 'D', 'type' => 'practice', 'status' => 'published',
            'scoring_rules' => ['score_unit' => 'percent', 'junior_max_score' => 59, 'senior_min_score' => 85, 'hard_gates_enabled' => false, 'pass_pct' => $pass],
            'usage_analytics' => [],
        ]);
        $block = $assessment->blocks()->create(['title' => 'M1', 'position' => 0]);
        $block->questions()->create([
            'assessment_id' => $assessment->id, 'question_text' => '1. Q', 'type' => 'mcq',
            'options' => [['label' => 'A', 'text' => 'Right', 'is_correct' => true], ['label' => 'B', 'text' => 'Wrong', 'is_correct' => false]],
            'difficulty_level' => 'Easy', 'reward' => 10, 'required_to_pass_block' => true, 'position' => 0,
        ]);

        return $assessment;
    }

    private function as(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
