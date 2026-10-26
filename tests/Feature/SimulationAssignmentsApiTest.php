<?php

namespace Tests\Feature;

use App\Models\PracticeSession;
use App\Models\Simulation;
use App\Models\SimulationAssignment;
use App\Models\User;
use App\Notifications\AcademyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Direct simulation assignments (like assessments). */
class SimulationAssignmentsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_assigns_a_published_simulation_to_several_brokers_and_they_are_notified(): void
    {
        Notification::fake();
        $simulation = Simulation::factory()->create();
        [$a, $b] = User::factory()->count(2)->create();

        $this->as(User::factory()->manager()->create())->postJson('/api/simulation-assignments', [
            'simulation_id' => $simulation->id,
            'user_ids' => [$a->id, $b->id],
            'due_at' => '2026-11-01T17:00:00Z',
            'min_score' => 70,
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.min_score', 70)
            ->assertJsonPath('data.0.done', false)
            ->assertJsonPath('data.0.simulation.title', $simulation->title)
            ->assertJsonPath('message', 'Assigned to 2 brokers.');

        Notification::assertSentTo($a, AcademyNotification::class, fn (AcademyNotification $n) => $n->link === '/practice?sim='.$simulation->id);
    }

    public function test_drafts_cannot_be_assigned_and_brokers_cannot_assign(): void
    {
        $draft = Simulation::factory()->create(['status' => 'draft']);
        $broker = User::factory()->create();

        $this->as(User::factory()->manager()->create())
            ->postJson('/api/simulation-assignments', ['simulation_id' => $draft->id, 'user_id' => $broker->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('simulation_id');

        $published = Simulation::factory()->create();
        $this->as($broker)
            ->postJson('/api/simulation-assignments', ['simulation_id' => $published->id, 'user_id' => $broker->id])
            ->assertForbidden();
    }

    public function test_all_brokers_skips_people_who_already_have_it(): void
    {
        $simulation = Simulation::factory()->create();
        $has = User::factory()->create();
        User::factory()->create();
        SimulationAssignment::factory()->create(['user_id' => $has->id, 'simulation_id' => $simulation->id]);

        $this->as(User::factory()->manager()->create())
            ->postJson('/api/simulation-assignments', ['simulation_id' => $simulation->id, 'all_brokers' => true])
            ->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('message', 'Assigned to 1 broker. 1 already had it.');
    }

    public function test_status_counts_calls_since_assigning_and_respects_the_target_score(): void
    {
        $simulation = Simulation::factory()->create();
        $me = User::factory()->create();
        $other = User::factory()->create();
        SimulationAssignment::factory()->create(['user_id' => $me->id, 'simulation_id' => $simulation->id, 'assigned_at' => now()->subDay(), 'min_score' => 70]);
        SimulationAssignment::factory()->create(['user_id' => $other->id, 'simulation_id' => $simulation->id]);

        // A call from before the assignment doesn't count.
        PracticeSession::factory()->create(['user_id' => $me->id, 'simulation_id' => $simulation->id, 'score' => 95, 'created_at' => now()->subDays(3)]);
        PracticeSession::factory()->create(['user_id' => $me->id, 'simulation_id' => $simulation->id, 'score' => 60]);

        $this->as($me)->getJson("/api/simulation-assignments?user_id={$other->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $me->id)
            ->assertJsonPath('data.0.calls_count', 1)
            ->assertJsonPath('data.0.best_score', 60)
            ->assertJsonPath('data.0.done', false);

        PracticeSession::factory()->create(['user_id' => $me->id, 'simulation_id' => $simulation->id, 'score' => 82]);

        $this->as($me)->getJson('/api/simulation-assignments')
            ->assertJsonPath('data.0.calls_count', 2)
            ->assertJsonPath('data.0.done', true)
            ->assertJsonPath('data.0.best_score', 82);
    }

    public function test_managers_can_nudge_about_an_assigned_simulation(): void
    {
        Notification::fake();
        $simulation = Simulation::factory()->create();
        $broker = User::factory()->create();
        SimulationAssignment::factory()->create(['user_id' => $broker->id, 'simulation_id' => $simulation->id]);

        $this->as(User::factory()->manager()->create())
            ->postJson("/api/users/{$broker->id}/nudge", ['item_type' => 'simulation', 'item_id' => $simulation->id])
            ->assertSuccessful();

        Notification::assertSentTo($broker, AcademyNotification::class);
    }

    public function test_reminders_cover_overdue_simulation_assignments(): void
    {
        Notification::fake();
        $broker = User::factory()->create();
        SimulationAssignment::factory()->create(['user_id' => $broker->id, 'due_at' => now()->subHour()]);

        $this->artisan('academy:send-reminders')->assertSuccessful();

        Notification::assertSentTo($broker, AcademyNotification::class, fn (AcademyNotification $n) => $n->kind === 'overdue');
        $this->assertDatabaseHas('assignment_reminders', ['assignment_type' => 'simulation', 'kind' => 'overdue']);
    }

    private function as(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
