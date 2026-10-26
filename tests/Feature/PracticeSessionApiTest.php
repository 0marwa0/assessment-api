<?php

namespace Tests\Feature;

use App\Models\PracticeSession;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeSessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_practice_session_can_be_created_with_a_null_score(): void
    {
        $simulation = Simulation::factory()->create();
        $broker = User::factory()->create();

        $response = $this->actingAsUser($broker)->postJson('/api/practice-sessions', $this->payload($simulation));

        $response
            ->assertCreated()
            ->assertJsonPath('data.simulation_id', $simulation->id)
            ->assertJsonPath('data.simulation_title', 'Objection Handling')
            ->assertJsonPath('data.simulation_level', 'Intermediate')
            ->assertJsonPath('data.duration_seconds', 577)
            ->assertJsonPath('data.score', null)
            ->assertJsonPath('data.transcript.0.role', 'agent')
            ->assertJsonPath('data.transcript.1.text', "Let's break down the pricing compared to nearby units.");

        $this->assertDatabaseHas('practice_sessions', [
            'simulation_id' => $simulation->id,
            'user_id' => $broker->id,
            'simulation_title' => 'Objection Handling',
            'duration_seconds' => 577,
            'score' => null,
        ]);
    }

    public function test_practice_session_requires_a_transcript_and_valid_simulation(): void
    {
        $payload = $this->payload(Simulation::factory()->create());
        unset($payload['transcript']);
        $payload['simulation_id'] = 99999;

        $this->actingAsUser(User::factory()->create())->postJson('/api/practice-sessions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transcript', 'simulation_id']);
    }

    public function test_practice_sessions_can_be_listed_newest_first(): void
    {
        $older = PracticeSession::factory()->create(['started_at' => now()->subDays(2)]);
        $newer = PracticeSession::factory()->create(['started_at' => now()]);

        $this->actingAsUser(User::factory()->manager()->create())->getJson('/api/practice-sessions?per_page=20')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_score_null_filter_returns_only_ungraded_sessions_for_the_review_queue(): void
    {
        $ungraded = PracticeSession::factory()->create(['score' => null]);
        PracticeSession::factory()->create(['score' => 85]);

        $this->actingAsUser(User::factory()->manager()->create())->getJson('/api/practice-sessions?score=null')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ungraded->id);
    }

    public function test_score_filter_can_also_match_an_exact_score(): void
    {
        PracticeSession::factory()->create(['score' => null]);
        $graded = PracticeSession::factory()->create(['score' => 85]);

        $this->actingAsUser(User::factory()->manager()->create())->getJson('/api/practice-sessions?score=85')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $graded->id);
    }

    public function test_the_list_includes_the_broker_name_for_the_review_queue(): void
    {
        $broker = User::factory()->create(['name' => 'Layla Broker']);
        PracticeSession::factory()->create(['user_id' => $broker->id]);

        $this->actingAsUser(User::factory()->manager()->create())->getJson('/api/practice-sessions')
            ->assertOk()
            ->assertJsonPath('data.0.user_name', 'Layla Broker');
    }

    public function test_a_broker_only_sees_their_own_calls(): void
    {
        $broker = User::factory()->create();
        $mine = PracticeSession::factory()->create(['user_id' => $broker->id]);
        PracticeSession::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAsUser($broker)->getJson('/api/practice-sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_listing_calls_requires_authentication(): void
    {
        $this->getJson('/api/practice-sessions')->assertUnauthorized();
    }

    public function test_a_broker_cannot_generate_a_report_for_someone_elses_call(): void
    {
        $session = PracticeSession::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAsUser(User::factory()->create())
            ->postJson("/api/practice-sessions/{$session->id}/ai-report")
            ->assertForbidden();
    }

    public function test_a_saved_report_is_returned_without_calling_the_ai_again(): void
    {
        // No Bedrock model is configured in tests, so reaching the AI would be a 500.
        config(['services.bedrock.model_id' => null]);
        $broker = User::factory()->create();
        $session = PracticeSession::factory()->create(['user_id' => $broker->id, 'score' => 55, 'ai_report' => ['score' => 55, 'executive_summary' => ['Saved']]]);

        $this->actingAsUser($broker)
            ->postJson("/api/practice-sessions/{$session->id}/ai-report")
            ->assertOk()
            ->assertJsonPath('message', 'AI report already exists.')
            ->assertJsonPath('data.ai_report.executive_summary.0', 'Saved');
    }

    public function test_only_managers_can_force_a_new_report(): void
    {
        $broker = User::factory()->create();
        $session = PracticeSession::factory()->create(['user_id' => $broker->id, 'ai_report' => ['score' => 40]]);

        $this->actingAsUser($broker)
            ->postJson("/api/practice-sessions/{$session->id}/ai-report", ['force' => true])
            ->assertForbidden();
    }

    public function test_a_manager_can_grade_a_practice_session(): void
    {
        $manager = User::factory()->manager()->create();
        $session = PracticeSession::factory()->create(['score' => null, 'feedback' => null]);

        $response = $this->actingAsUser($manager)->patchJson("/api/practice-sessions/{$session->id}", [
            'score' => 78,
            'feedback' => 'Good rapport, but rushed past the pricing objection.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.score', 78)
            ->assertJsonPath('data.feedback', 'Good rapport, but rushed past the pricing objection.');

        $this->assertDatabaseHas('practice_sessions', [
            'id' => $session->id,
            'score' => 78,
            'feedback' => 'Good rapport, but rushed past the pricing objection.',
        ]);
    }

    public function test_an_end_user_cannot_grade_a_practice_session(): void
    {
        $endUser = User::factory()->create();
        $session = PracticeSession::factory()->create(['score' => null]);

        $this->actingAsUser($endUser)
            ->patchJson("/api/practice-sessions/{$session->id}", ['score' => 90])
            ->assertForbidden();
    }

    public function test_grading_requires_authentication(): void
    {
        $session = PracticeSession::factory()->create(['score' => null]);

        $this->patchJson("/api/practice-sessions/{$session->id}", ['score' => 90])
            ->assertUnauthorized();
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * @return array<string, mixed>
     */
    public function test_a_broker_cannot_save_a_call_on_a_draft_simulation_but_a_manager_can(): void
    {
        $draft = Simulation::factory()->create(['status' => 'draft']);

        $this->actingAsUser(User::factory()->create())
            ->postJson('/api/practice-sessions', $this->payload($draft))
            ->assertStatus(422);
        $this->assertDatabaseCount('practice_sessions', 0);

        $this->actingAsUser(User::factory()->manager()->create())
            ->postJson('/api/practice-sessions', $this->payload($draft))
            ->assertCreated();
    }

    private function payload(Simulation $simulation): array
    {
        return [
            'simulation_id' => (string) $simulation->id,
            'simulation_title' => 'Objection Handling',
            'simulation_level' => 'Intermediate',
            'started_at' => '2026-08-21T14:02:10.000Z',
            'ended_at' => '2026-08-21T14:11:47.000Z',
            'duration_seconds' => 577,
            'transcript' => [
                ['role' => 'agent', 'text' => 'I like the property, but the price feels risky to me.'],
                ['role' => 'user', 'text' => "Let's break down the pricing compared to nearby units."],
            ],
        ];
    }
}
