<?php

namespace Tests\Feature;

use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
use App\Models\Simulation;
use App\Models\SimulationInvite;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R11: drafts hidden from brokers, single-use invite links, forgot/reset password.
 */
class R11DraftsInvitesAuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingWithToken(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('api')->plainTextToken);
    }

    // ── Drafts hidden from brokers ─────────────────────────────────────────

    public function test_brokers_only_list_published_simulations(): void
    {
        $published = Simulation::factory()->create(['status' => 'published']);
        Simulation::factory()->create(['status' => 'draft']);

        $this->actingWithToken(User::factory()->create())
            ->getJson('/api/simulations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id);
    }

    public function test_brokers_get_404_for_a_draft_but_can_open_a_published_one(): void
    {
        $draft = Simulation::factory()->create(['status' => 'draft']);
        $published = Simulation::factory()->create(['status' => 'published']);
        $broker = User::factory()->create();

        $this->actingWithToken($broker)->getJson("/api/simulations/{$draft->id}")->assertNotFound();
        $this->actingWithToken($broker)->getJson("/api/simulations/{$published->id}")->assertOk();
    }

    public function test_managers_still_see_drafts(): void
    {
        $draft = Simulation::factory()->create(['status' => 'draft']);
        Simulation::factory()->create(['status' => 'published']);
        $manager = User::factory()->manager()->create();

        $this->actingWithToken($manager)->getJson('/api/simulations')->assertOk()->assertJsonCount(2, 'data');
        $this->actingWithToken($manager)->getJson("/api/simulations/{$draft->id}")->assertOk();
    }

    public function test_a_roadmap_step_on_a_draft_simulation_is_marked_unavailable_for_brokers(): void
    {
        $manager = User::factory()->manager()->create();
        $broker = User::factory()->create();
        $draft = Simulation::factory()->create(['status' => 'draft']);
        $published = Simulation::factory()->create(['status' => 'published']);

        $roadmap = Roadmap::factory()->create(['title' => 'Onboarding']);
        $roadmap->nodes()->create(['position' => 0, 'type' => 'simulation', 'simulation_id' => $draft->id]);
        $roadmap->nodes()->create(['position' => 1, 'type' => 'simulation', 'simulation_id' => $published->id]);
        RoadmapAssignment::query()->create(['user_id' => $broker->id, 'roadmap_id' => $roadmap->id, 'assigned_by' => $manager->id, 'assigned_at' => now()]);

        $this->actingWithToken($broker)
            ->getJson('/api/roadmap-assignments')
            ->assertOk()
            ->assertJsonPath('data.0.roadmap.nodes.0.available', false)
            ->assertJsonPath('data.0.roadmap.nodes.1.available', true);
    }

    // ── Single-use invite links ────────────────────────────────────────────

    private function invite(array $attributes = []): SimulationInvite
    {
        $simulation = Simulation::factory()->create(['status' => 'draft']);

        return SimulationInvite::query()->create([
            'simulation_id' => $simulation->id,
            'created_by' => User::factory()->manager()->create()->id,
            'token' => Str::random(40),
            'candidate_email' => 'guest@example.com',
            ...$attributes,
        ]);
    }

    private function sessionPayload(): array
    {
        return [
            'email' => 'guest@example.com',
            'started_at' => now()->subMinutes(5)->toISOString(),
            'ended_at' => now()->toISOString(),
            'duration_seconds' => 300,
            'transcript' => [['role' => 'agent', 'text' => 'Hello']],
        ];
    }

    public function test_an_invite_to_a_draft_simulation_still_unlocks_for_the_guest(): void
    {
        $invite = $this->invite();

        $this->getJson("/api/simulation-invites/{$invite->token}")->assertOk();
        $this->postJson("/api/simulation-invites/{$invite->token}/unlock", ['email' => 'guest@example.com'])
            ->assertOk()
            ->assertJsonPath('data.id', $invite->simulation_id);
    }

    public function test_an_invite_is_used_up_once_the_guest_session_is_saved(): void
    {
        $invite = $this->invite();

        $this->postJson("/api/simulation-invites/{$invite->token}/practice-sessions", $this->sessionPayload())
            ->assertCreated();
        $this->assertNotNull($invite->fresh()->completed_at);

        $this->getJson("/api/simulation-invites/{$invite->token}")
            ->assertStatus(410)
            ->assertJsonPath('message', 'This invite link has already been used.')
            ->assertJsonPath('reason', 'used');
        $this->postJson("/api/simulation-invites/{$invite->token}/unlock", ['email' => 'guest@example.com'])
            ->assertStatus(410)
            ->assertJsonPath('reason', 'used');
        $this->postJson("/api/simulation-invites/{$invite->token}/practice-sessions", $this->sessionPayload())
            ->assertStatus(410)
            ->assertJsonPath('reason', 'used');

        $this->assertDatabaseCount('practice_sessions', 1);
    }

    public function test_expired_invites_are_still_refused(): void
    {
        $invite = $this->invite(['expires_at' => now()->subDay()]);

        $this->getJson("/api/simulation-invites/{$invite->token}")->assertOk()->assertJsonPath('data.expired', true);
        $this->postJson("/api/simulation-invites/{$invite->token}/unlock", ['email' => 'guest@example.com'])
            ->assertStatus(410)
            ->assertJsonPath('message', 'This invite link has expired.');
    }

    public function test_the_manager_invite_list_shows_used_unused_and_expired(): void
    {
        $invite = $this->invite();
        $manager = User::query()->findOrFail($invite->created_by);
        SimulationInvite::query()->create([
            'simulation_id' => $invite->simulation_id, 'created_by' => $manager->id, 'token' => Str::random(40),
            'candidate_email' => 'b@example.com', 'expires_at' => now()->subDay(),
        ]);
        $used = SimulationInvite::query()->create([
            'simulation_id' => $invite->simulation_id, 'created_by' => $manager->id, 'token' => Str::random(40),
            'candidate_email' => 'c@example.com',
        ]);
        $used->forceFill(['completed_at' => now()])->save();

        $statuses = collect($this->actingWithToken($manager)
            ->getJson("/api/simulations/{$invite->simulation_id}/invites")
            ->assertOk()
            ->json('data'))->pluck('status', 'candidate_email');

        $this->assertSame('unused', $statuses['guest@example.com']);
        $this->assertSame('expired', $statuses['b@example.com']);
        $this->assertSame('used', $statuses['c@example.com']);
    }

    // ── Forgot / reset password ────────────────────────────────────────────

    public function test_login_with_a_wrong_password_uses_the_friendly_message(): void
    {
        User::factory()->create(['email' => 'ahmed@example.com', 'password' => 'password123']);

        $this->postJson('/api/login', ['email' => 'ahmed@example.com', 'password' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email or password is wrong. Try again.');
    }

    public function test_forgot_password_answers_the_same_for_unknown_emails(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a reset link is on its way.');

        Notification::assertNothingSent();
    }

    public function test_full_reset_flow_sends_a_frontend_link_and_changes_the_password(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://academy.test']);
        $user = User::factory()->create(['email' => 'ahmed@example.com', 'password' => 'old-password']);
        $oldToken = $user->createToken('api')->plainTextToken;

        $this->postJson('/api/forgot-password', ['email' => 'ahmed@example.com'])->assertOk();

        $resetToken = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$resetToken): bool {
            $resetToken = $notification->token;
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://academy.test/reset-password?token=')
                && str_contains($url, 'email=ahmed%40example.com');
        });

        $this->postJson('/api/reset-password', [
            'token' => $resetToken,
            'email' => 'ahmed@example.com',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
        // Old sessions are signed out.
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotEmpty($oldToken);

        $this->postJson('/api/login', ['email' => 'ahmed@example.com', 'password' => 'new-password-1'])->assertOk();
    }

    public function test_reset_with_a_bad_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'ahmed@example.com', 'password' => 'old-password']);

        $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'ahmed@example.com',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_reset_requires_a_confirmed_password(): void
    {
        $this->postJson('/api/reset-password', [
            'token' => 'x',
            'email' => 'ahmed@example.com',
            'password' => 'new-password-1',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
