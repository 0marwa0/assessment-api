<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
use App\Models\RoadmapNode;
use App\Models\PracticeSession;
use App\Models\Simulation;
use App\Models\SimulationAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_gets_each_broker_with_progress_current_step_and_due_date(): void
    {
        $broker = User::factory()->create(['name' => 'Layla']);
        User::factory()->create(['name' => 'Omar']); // nothing assigned
        $roadmap = Roadmap::factory()->create(['title' => 'Off-plan basics']);
        $first = RoadmapNode::factory()->create(['roadmap_id' => $roadmap->id, 'position' => 1]);
        $second = RoadmapNode::factory()->create([
            'roadmap_id' => $roadmap->id, 'position' => 2, 'material_id' => Material::factory()->create(['title' => 'Escrow rules'])->id,
        ]);
        RoadmapAssignment::factory()->create(['user_id' => $broker->id, 'roadmap_id' => $roadmap->id, 'due_at' => '2026-11-01 17:00:00']);

        $token = $broker->createToken('api')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->patchJson("/api/roadmap-nodes/{$first->id}/progress", ['status' => 'done']);
        $this->app['auth']->forgetGuards();

        $managerToken = User::factory()->manager()->create()->createToken('api')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$managerToken}")->getJson('/api/team-overview')
            ->assertOk()
            ->assertJsonPath('meta.broker_count', 2)
            ->assertJsonPath('meta.active_last_7_days', 1)
            ->assertJsonPath('data.0.name', 'Layla')
            ->assertJsonPath('data.0.roadmaps.0.title', 'Off-plan basics')
            ->assertJsonPath('data.0.roadmaps.0.done_steps', 1)
            ->assertJsonPath('data.0.roadmaps.0.total_steps', 2)
            ->assertJsonPath('data.0.roadmaps.0.current_step.position', 2)
            ->assertJsonPath('data.0.roadmaps.0.current_step.title', 'Escrow rules')
            ->assertJsonPath('data.0.roadmaps.0.due_at', '2026-11-01T17:00:00.000000Z')
            ->assertJsonPath('data.1.name', 'Omar')
            ->assertJsonCount(0, 'data.1.roadmaps');
    }

    public function test_assigned_practice_calls_show_calls_best_score_and_done(): void
    {
        $broker = User::factory()->create(['name' => 'Layla']);
        $simulation = Simulation::factory()->create(['title' => 'Off-plan objection', 'status' => 'published']);
        $assignment = SimulationAssignment::factory()->create([
            'user_id' => $broker->id,
            'simulation_id' => $simulation->id,
            'assigned_at' => now()->subDays(2),
            'due_at' => now()->addDay(),
            'min_score' => 70,
        ]);
        $turns = [['role' => 'user', 'text' => 'Hi'], ['role' => 'agent', 'text' => 'Hello']];
        // Before assigning: doesn't count.
        PracticeSession::factory()->create(['user_id' => $broker->id, 'simulation_id' => $simulation->id, 'score' => 95, 'transcript' => $turns, 'created_at' => now()->subDays(5)]);
        // No transcript: doesn't count.
        PracticeSession::factory()->create(['user_id' => $broker->id, 'simulation_id' => $simulation->id, 'score' => 90, 'transcript' => [], 'created_at' => now()->subHour()]);
        PracticeSession::factory()->create(['user_id' => $broker->id, 'simulation_id' => $simulation->id, 'score' => 55, 'transcript' => $turns, 'created_at' => now()->subDay()]);
        $last = PracticeSession::factory()->create(['user_id' => $broker->id, 'simulation_id' => $simulation->id, 'score' => 62, 'transcript' => $turns, 'created_at' => now()->subMinutes(30)]);

        $managerToken = User::factory()->manager()->create()->createToken('api')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer {$managerToken}")->getJson('/api/team-overview')
            ->assertOk()
            ->assertJsonPath('data.0.simulations.0.title', 'Off-plan objection')
            ->assertJsonPath('data.0.simulations.0.published', true)
            ->assertJsonPath('data.0.simulations.0.min_score', 70)
            ->assertJsonPath('data.0.simulations.0.calls_count', 2)
            ->assertJsonPath('data.0.simulations.0.best_score', 62)
            ->assertJsonPath('data.0.simulations.0.last_session_id', $last->id)
            ->assertJsonPath('data.0.simulations.0.done', false);
        $this->assertSame($assignment->fresh()->isDone(), $response->json('data.0.simulations.0.done'));

        $last->update(['score' => 74]);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$managerToken}")->getJson('/api/team-overview')
            ->assertJsonPath('data.0.simulations.0.best_score', 74)
            ->assertJsonPath('data.0.simulations.0.done', true);
    }

    public function test_brokers_cannot_see_the_team_overview(): void
    {
        $token = User::factory()->create()->createToken('api')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/team-overview')->assertForbidden();
    }
}
