<?php

namespace Tests\Feature;

use App\Models\Simulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulation_can_be_created(): void
    {
        $response = $this->postJson('/api/simulations', $this->payload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', '1- B2B Discovery Call')
            ->assertJsonPath('data.description', 'Qualify a corporate investor seeking bulk off-plan units in Dubai Marina.')
            ->assertJsonPath('data.level', 'Intermediate')
            ->assertJsonPath('data.time_required', '12-18 min')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.opening_message', 'I am looking to buy multiple off-plan units. What should I know first?');

        $this->assertDatabaseHas('simulations', [
            'title' => '1- B2B Discovery Call',
            'level' => 'Intermediate',
            'status' => 'draft',
        ]);
    }

    public function test_simulations_can_be_listed_and_shown(): void
    {
        $simulation = Simulation::create($this->payload());

        $this->getJson('/api/simulations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $simulation->id)
            ->assertJsonPath('data.0.title', '1- B2B Discovery Call');

        $this->getJson("/api/simulations/{$simulation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $simulation->id)
            ->assertJsonPath('data.opening_message', 'I am looking to buy multiple off-plan units. What should I know first?');
    }

    public function test_simulation_can_be_updated_and_deleted(): void
    {
        $simulation = Simulation::create($this->payload());

        $this->putJson("/api/simulations/{$simulation->id}", [
            'status' => 'published',
            'level' => 'Advanced',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.level', 'Advanced');

        $this->deleteJson("/api/simulations/{$simulation->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('simulations', [
            'id' => $simulation->id,
        ]);
    }

    public function test_test_session_starts_with_the_simulation_opening_message(): void
    {
        $simulation = Simulation::create($this->payload());

        $response = $this->postJson("/api/simulations/{$simulation->id}/test-sessions");

        $response
            ->assertCreated()
            ->assertJsonPath('data.simulation_id', $simulation->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.messages.0.role', 'simulation')
            ->assertJsonPath('data.messages.0.content', 'I am looking to buy multiple off-plan units. What should I know first?')
            ->assertJsonPath('data.messages.0.position', 0);

        $this->assertDatabaseHas('simulation_test_sessions', [
            'simulation_id' => $simulation->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('simulation_test_messages', [
            'role' => 'simulation',
            'content' => 'I am looking to buy multiple off-plan units. What should I know first?',
            'position' => 0,
        ]);
    }

    public function test_message_can_be_added_to_a_simulation_test_session(): void
    {
        $simulation = Simulation::create($this->payload());
        $session = $simulation->testSessions()->create([
            'status' => 'active',
            'started_at' => now(),
        ]);

        $session->messages()->create([
            'role' => 'simulation',
            'content' => $simulation->opening_message,
            'position' => 0,
        ]);

        $response = $this->postJson("/api/simulation-test-sessions/{$session->id}/messages", [
            'role' => 'user',
            'content' => 'Can you explain payment plans first?',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.simulation_test_session_id', $session->id)
            ->assertJsonPath('data.role', 'user')
            ->assertJsonPath('data.content', 'Can you explain payment plans first?')
            ->assertJsonPath('data.position', 1);

        $this->assertDatabaseHas('simulation_test_messages', [
            'simulation_test_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Can you explain payment plans first?',
            'position' => 1,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        return [
            'title' => '1- B2B Discovery Call',
            'description' => 'Qualify a corporate investor seeking bulk off-plan units in Dubai Marina.',
            'level' => 'Intermediate',
            'time_required' => '12-18 min',
            'status' => 'draft',
            'opening_message' => 'I am looking to buy multiple off-plan units. What should I know first?',
        ];
    }
}
