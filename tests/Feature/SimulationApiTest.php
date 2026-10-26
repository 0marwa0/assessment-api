<?php

namespace Tests\Feature;

use App\Models\Simulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationApiTest extends TestCase
{
    use RefreshDatabase;

    // Content routes need a login since R1b, and changes are manager-only.
    protected function setUp(): void
    {
        parent::setUp();

        $token = User::factory()->manager()->create()->createToken('api')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");
    }

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
            ->assertJsonPath('data.opening_message', 'I am looking to buy multiple off-plan units. What should I know first?')
            ->assertJsonPath('data.persona', 'Ahmed, a corporate procurement lead evaluating bulk off-plan purchases.')
            ->assertJsonPath('data.scenario_brief', 'The client is comparing developers and wants clarity on payment plans before committing.')
            ->assertJsonPath('data.language', 'en');

        $this->assertDatabaseHas('simulations', [
            'title' => '1- B2B Discovery Call',
            'level' => 'Intermediate',
            'status' => 'draft',
            'language' => 'en',
        ]);
    }

    public function test_simulation_language_defaults_to_en_but_can_be_overridden(): void
    {
        $payload = $this->payload();
        unset($payload['language']);

        $this->postJson('/api/simulations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.language', 'en');

        $payload['language'] = 'ar';
        $response = $this->postJson('/api/simulations', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.language', 'ar');

        $this->putJson("/api/simulations/{$response->json('data.id')}", ['language' => 'fr'])
            ->assertOk()
            ->assertJsonPath('data.language', 'fr');
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

    public function test_structured_persona_fields_are_optional_and_round_trip(): void
    {
        $payload = [
            ...$this->payload(),
            'persona_name' => 'Ahmed',
            'persona_gender' => 'male',
            'persona_tone' => 'nice',
            'opening_message' => null,
        ];

        $response = $this->postJson('/api/simulations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.opening_message', '')
            ->assertJsonPath('data.objections', [])
            ->assertJsonPath('data.hidden_motivation', null);

        $id = $response->json('data.id');

        $this->putJson("/api/simulations/{$id}", [
            'objections' => ['Price is above my target yield', 'Handover date feels risky'],
            'hidden_motivation' => 'Needs to close before fiscal year end.',
            'win_criteria' => 'Broker quantifies ROI with comparables.',
            'loss_criteria' => 'Broker dodges the handover question.',
        ])
            ->assertOk()
            ->assertJsonPath('data.objections.1', 'Handover date feels risky')
            ->assertJsonPath('data.hidden_motivation', 'Needs to close before fiscal year end.')
            ->assertJsonPath('data.win_criteria', 'Broker quantifies ROI with comparables.')
            ->assertJsonPath('data.loss_criteria', 'Broker dodges the handover question.');

        $this->putJson("/api/simulations/{$id}", ['objections' => 'not-a-list'])
            ->assertUnprocessable();
    }

    public function test_voice_engine_defaults_to_elevenlabs_and_can_be_switched_to_openai(): void
    {
        $payload = [
            ...$this->payload(),
            'persona_name' => 'Ahmed',
            'persona_gender' => 'male',
            'persona_tone' => 'nice',
        ];

        $response = $this->postJson('/api/simulations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.voice_engine', 'elevenlabs');

        $id = $response->json('data.id');
        $this->assertDatabaseHas('simulations', ['id' => $id, 'voice_engine' => 'elevenlabs']);

        $this->putJson("/api/simulations/{$id}", ['voice_engine' => 'openai'])
            ->assertOk()
            ->assertJsonPath('data.voice_engine', 'openai');

        $this->getJson("/api/simulations/{$id}")
            ->assertOk()
            ->assertJsonPath('data.voice_engine', 'openai');

        // Updates that omit voice_engine leave it untouched.
        $this->putJson("/api/simulations/{$id}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.voice_engine', 'openai');

        $this->putJson("/api/simulations/{$id}", ['voice_engine' => 'vapi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voice_engine');

        $this->postJson('/api/simulations', [...$payload, 'voice_engine' => 'openai'])
            ->assertCreated()
            ->assertJsonPath('data.voice_engine', 'openai');

        $this->postJson('/api/simulations', [...$payload, 'voice_engine' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voice_engine');
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
            'persona' => 'Ahmed, a corporate procurement lead evaluating bulk off-plan purchases.',
            'scenario_brief' => 'The client is comparing developers and wants clarity on payment plans before committing.',
            'persona_name' => 'Ahmed',
            'persona_gender' => 'male',
            'persona_tone' => 'nice',
        ];
    }
}
