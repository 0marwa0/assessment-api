<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAssignment;
use App\Models\AssessmentAttempt;
use App\Models\PracticeSession;
use App\Models\PracticeSessionRereview;
use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
use App\Models\User;
use App\Notifications\AcademyNotification;
use App\Services\CertificateIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Backlog R12: in-app notifications, email and the manager Nudge.
 */
class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_a_roadmap_and_an_assessment_notifies_the_broker(): void
    {
        $manager = User::factory()->manager()->create(['name' => 'Sara Manager']);
        $broker = User::factory()->create();
        $roadmap = Roadmap::factory()->create(['title' => 'Off-plan 101']);
        $assessment = Assessment::factory()->create(['title' => 'Golden visa quiz']);

        $this->actingAsUser($manager)->postJson('/api/roadmap-assignments', [
            'roadmap_id' => $roadmap->id, 'user_id' => $broker->id, 'due_at' => now()->addDays(3)->toISOString(),
        ])->assertCreated();
        $this->travel(1)->seconds();
        $this->actingAsUser($manager)->postJson('/api/assessment-assignments', [
            'assessment_id' => $assessment->id, 'user_id' => $broker->id,
        ])->assertCreated();

        $this->actingAsUser($broker)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('data.0.title', 'New assessment:')
            ->assertJsonPath('data.0.text', 'Golden visa quiz')
            ->assertJsonPath('data.1.title', 'New roadmap:')
            ->assertJsonPath('data.1.link', "/roadmap/{$roadmap->id}")
            ->assertJsonPath('data.1.kind', 'assignment');
        $this->assertStringContainsString('From Sara Manager · due', $broker->notifications()->latest()->get()[1]->data['meta']);
    }

    public function test_assignment_email_goes_out_unless_the_broker_turned_emails_off(): void
    {
        Notification::fake();
        $manager = User::factory()->manager()->create();
        $on = User::factory()->create();
        $off = User::factory()->create(['email_notifications' => false]);
        $roadmap = Roadmap::factory()->create();

        $this->actingAsUser($manager)->postJson('/api/roadmap-assignments', [
            'roadmap_id' => $roadmap->id, 'user_ids' => [$on->id, $off->id],
        ])->assertCreated();

        Notification::assertSentTo($on, AcademyNotification::class, fn ($n, array $channels) => $channels === ['database', 'mail']);
        Notification::assertSentTo($off, AcademyNotification::class, fn ($n, array $channels) => $channels === ['database']);
    }

    public function test_the_broker_can_turn_emails_off(): void
    {
        $broker = User::factory()->create();

        $this->actingAsUser($broker)->patchJson('/api/notification-settings', ['email_notifications' => false])
            ->assertOk()->assertJsonPath('email_notifications', false);

        $this->assertFalse($broker->fresh()->email_notifications);
    }

    public function test_review_rereview_and_answer_notify_the_right_people(): void
    {
        $manager = User::factory()->manager()->create(['name' => 'Sara']);
        $otherManager = User::factory()->manager()->create();
        $broker = User::factory()->create(['name' => 'Omar Broker']);
        $session = PracticeSession::factory()->create([
            'user_id' => $broker->id, 'simulation_title' => 'Cold call', 'ai_report' => ['score' => 60],
        ]);

        $this->actingAsUser($manager)->postJson("/api/practice-sessions/{$session->id}/review", ['final_score' => 75])->assertOk();
        $this->assertSame('Sara reviewed', $broker->notifications()->first()->data['title']);
        $this->assertSame('Score 60 → 75', $broker->notifications()->first()->data['meta']);
        $this->assertSame("/practice/report/{$session->id}", $broker->notifications()->first()->data['link']);

        $rereviewId = $this->actingAsUser($broker)->postJson("/api/practice-sessions/{$session->id}/rereview", ['message' => 'I did ask about budget'])
            ->assertCreated()->json('data.id');
        $this->assertSame('Omar Broker asked for a re-review', $manager->notifications()->first()->data['title']);
        $this->assertSame('/review', $otherManager->notifications()->first()->data['link']);

        $this->travel(1)->seconds();
        $this->actingAsUser($manager)->postJson("/api/practice-session-rereviews/{$rereviewId}/answer", ['decision' => 'changed', 'new_score' => 80])->assertOk();
        $this->assertSame('Sara answered', $broker->notifications()->latest()->first()->data['title']);
        $this->assertSame(2, $broker->notifications()->count());
    }

    public function test_a_new_certificate_notifies_the_learner_but_a_backfill_does_not(): void
    {
        $broker = User::factory()->create();
        $assessment = Assessment::factory()->create(['title' => 'Golden visa quiz']);
        $attempt = AssessmentAttempt::create([
            'user_id' => $broker->id, 'assessment_id' => $assessment->id, 'score_pct' => 90, 'passed' => true, 'tier' => 'Senior',
            'total_correct' => 9, 'total_questions' => 10, 'xp_earned' => 0, 'elapsed_seconds' => 60, 'module_results' => [],
        ]);

        app(CertificateIssuer::class)->issueFor($attempt);

        $data = $broker->notifications()->first()->data;
        $this->assertSame(['certificate', 'Certificate earned:', 'Senior', '/certificates'], [$data['kind'], $data['title'], $data['text'], $data['link']]);

        $other = User::factory()->create();
        AssessmentAttempt::create(['user_id' => $other->id, 'assessment_id' => $assessment->id, 'score_pct' => 90, 'passed' => true, 'tier' => 'Senior', 'total_correct' => 9, 'total_questions' => 10, 'xp_earned' => 0, 'elapsed_seconds' => 60, 'module_results' => []]);
        app(CertificateIssuer::class)->backfillFor($other->id);
        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_listing_is_own_only_and_read_and_read_all_work(): void
    {
        $broker = User::factory()->create();
        $other = User::factory()->create();
        $this->notify($broker, 'One');
        $this->notify($broker, 'Two');
        $this->notify($other, 'Not yours');

        $list = $this->actingAsUser($broker)->getJson('/api/notifications')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('unread_count', 2);

        $id = $list->json('data.0.id');
        $this->actingAsUser($broker)->postJson("/api/notifications/{$id}/read")
            ->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('data.id', $id);

        $otherId = $other->notifications()->first()->id;
        $this->actingAsUser($broker)->postJson("/api/notifications/{$otherId}/read")->assertNotFound();
        $this->assertNull($other->notifications()->first()->read_at);

        $this->actingAsUser($broker)->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame(0, $broker->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_nudge_is_manager_only_throttled_per_item_per_day_and_notifies(): void
    {
        $manager = User::factory()->manager()->create(['name' => 'Sara']);
        $broker = User::factory()->create();
        $roadmap = Roadmap::factory()->create(['title' => 'Off-plan 101']);
        RoadmapAssignment::factory()->create(['user_id' => $broker->id, 'roadmap_id' => $roadmap->id, 'due_at' => now()->subDays(2)]);
        $payload = ['item_type' => 'roadmap', 'item_id' => $roadmap->id];

        $this->actingAsUser($broker)->postJson("/api/users/{$broker->id}/nudge", $payload)->assertForbidden();

        $this->actingAsUser($manager)->postJson("/api/users/{$broker->id}/nudge", [...$payload, 'message' => 'Stuck? Let\'s talk it through.'])
            ->assertCreated()->assertJsonPath('data.item_id', $roadmap->id);

        $data = $broker->notifications()->first()->data;
        $this->assertSame('Nudge from Sara:', $data['title']);
        $this->assertSame('“Stuck? Let\'s talk it through.”', $data['text']);
        $this->assertSame('Off-plan 101 is overdue', $data['meta']);

        $this->actingAsUser($manager)->postJson("/api/users/{$broker->id}/nudge", $payload)->assertStatus(429);

        $this->travel(1)->days();
        $this->actingAsUser($manager)->postJson("/api/users/{$broker->id}/nudge", $payload)->assertCreated();
        $this->assertSame(2, $broker->notifications()->count());

        $this->actingAsUser($manager)->getJson('/api/nudges')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_nudge_needs_an_assignment_and_respects_send_by(): void
    {
        $manager = User::factory()->manager()->create();
        $broker = User::factory()->create();
        $assessment = Assessment::factory()->create();

        $this->actingAsUser($manager)->postJson("/api/users/{$broker->id}/nudge", ['item_type' => 'assessment', 'item_id' => $assessment->id])
            ->assertStatus(422);

        AssessmentAssignment::factory()->create(['user_id' => $broker->id, 'assessment_id' => $assessment->id]);
        Notification::fake();
        $this->actingAsUser($manager)->postJson("/api/users/{$broker->id}/nudge", [
            'item_type' => 'assessment', 'item_id' => $assessment->id, 'via_app' => false, 'via_email' => true,
        ])->assertCreated();

        Notification::assertSentTo($broker, AcademyNotification::class, fn ($n, array $channels) => $channels === ['mail']);
    }

    public function test_reminder_command_sends_due_soon_and_overdue_once(): void
    {
        $broker = User::factory()->create();
        $soon = RoadmapAssignment::factory()->create(['user_id' => $broker->id, 'due_at' => now()->addHours(10)]);
        AssessmentAssignment::factory()->create(['user_id' => $broker->id, 'due_at' => now()->subDay()]);
        RoadmapAssignment::factory()->create(['user_id' => $broker->id, 'due_at' => now()->addDays(5)]); // not yet
        RoadmapAssignment::factory()->create(['user_id' => $broker->id]); // no due date

        $this->artisan('academy:send-reminders')->assertSuccessful();
        $this->artisan('academy:send-reminders')->assertSuccessful();

        $titles = $broker->notifications()->get()->pluck('data.title')->sort()->values()->all();
        $this->assertSame(['Due tomorrow:', 'Overdue:'], $titles);

        // Once the due-soon one passes its date it gets exactly one overdue reminder.
        $this->travel(1)->days();
        $this->artisan('academy:send-reminders')->assertSuccessful();
        $this->artisan('academy:send-reminders')->assertSuccessful();
        $this->assertSame(3, $broker->notifications()->count());
        $this->assertDatabaseHas('assignment_reminders', ['assignment_id' => $soon->id, 'kind' => 'overdue']);
    }

    public function test_reminders_skip_finished_assessments(): void
    {
        $broker = User::factory()->create();
        $assignment = AssessmentAssignment::factory()->create(['user_id' => $broker->id, 'due_at' => now()->subDay()]);
        AssessmentAttempt::create(['user_id' => $broker->id, 'assessment_id' => $assignment->assessment_id, 'score_pct' => 90, 'passed' => true, 'tier' => 'Senior', 'total_correct' => 9, 'total_questions' => 10, 'xp_earned' => 0, 'elapsed_seconds' => 60, 'module_results' => []]);

        $this->artisan('academy:send-reminders')->assertSuccessful();

        $this->assertSame(0, $broker->notifications()->count());
    }

    private function notify(User $user, string $text): void
    {
        $user->notify(new AcademyNotification('info', 'Hello', $text, null, '/home', null));
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
