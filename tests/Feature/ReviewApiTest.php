<?php

namespace Tests\Feature;

use App\Models\PracticeSession;
use App\Models\PracticeSessionReview;
use App\Models\PracticeSessionRereview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_lists_unreviewed_calls_with_transcripts_newest_first(): void
    {
        $older = PracticeSession::factory()->create(['started_at' => now()->subDay(), 'score' => 70, 'ai_report' => $this->aiReport()]);
        $newer = PracticeSession::factory()->create(['started_at' => now()]);
        PracticeSession::factory()->create(['transcript' => []]);
        $reviewed = PracticeSession::factory()->create();
        PracticeSessionReview::create(['practice_session_id' => $reviewed->id, 'final_score' => 80, 'reviewed_at' => now()]);

        $this->actingAsUser(User::factory()->manager()->create())->getJson('/api/review-queue')
            ->assertOk()
            ->assertJsonCount(2, 'data.to_review')
            ->assertJsonPath('data.to_review.0.id', $newer->id)
            ->assertJsonPath('data.to_review.1.id', $older->id)
            ->assertJsonPath('data.counts.to_review', 2)
            ->assertJsonPath('data.counts.rereviews_open', 0);
    }

    public function test_review_endpoints_are_manager_only(): void
    {
        $broker = User::factory()->create();
        $session = PracticeSession::factory()->create(['user_id' => $broker->id]);

        $this->actingAsUser($broker)->getJson('/api/review-queue')->assertForbidden();
        $this->actingAsUser($broker)->postJson("/api/practice-sessions/{$session->id}/review", ['final_score' => 90])->assertForbidden();
        $this->actingAsUser($broker)->postJson("/api/practice-sessions/{$session->id}/comments", ['turn_index' => 0, 'body' => 'x'])->assertForbidden();
    }

    public function test_review_queue_requires_authentication(): void
    {
        $this->getJson('/api/review-queue')->assertUnauthorized();
    }

    public function test_review_saves_criteria_and_final_score_on_the_session(): void
    {
        $session = PracticeSession::factory()->create(['score' => 60, 'ai_report' => $this->aiReport()]);

        $this->actingAsUser(User::factory()->manager()->create())
            ->postJson("/api/practice-sessions/{$session->id}/review", [
                'criteria' => [
                    ['name' => 'Opener', 'status' => 'accepted'],
                    ['name' => 'Discovery', 'status' => 'adjusted', 'final_score' => 100, 'reason' => 'Asked about budget at #3'],
                ],
                'feedback' => 'Strong discovery.',
            ])
            ->assertOk()
            // AI 60 moved by the average criterion change: (0 + 100) / 2 = +50, capped at 100.
            ->assertJsonPath('data.review.final_score', 100)
            ->assertJsonPath('data.review.criteria.0.ai_score', 50)
            ->assertJsonPath('data.review.criteria.0.status', 'accepted')
            ->assertJsonPath('data.review.criteria.1.ai_score', 0)
            ->assertJsonPath('data.review.criteria.1.status', 'adjusted')
            ->assertJsonPath('data.review.criteria.1.reason', 'Asked about budget at #3');

        $session->refresh();
        $this->assertSame(100, $session->score);
        $this->assertSame('Strong discovery.', $session->feedback);
    }

    public function test_call_without_ai_score_is_graded_with_a_given_score_and_leaves_the_queue(): void
    {
        $manager = User::factory()->manager()->create();
        $session = PracticeSession::factory()->create();

        $this->actingAsUser($manager)->postJson("/api/practice-sessions/{$session->id}/review", [])->assertUnprocessable();

        $this->actingAsUser($manager)->postJson("/api/practice-sessions/{$session->id}/review", ['final_score' => 72, 'feedback' => 'Good'])
            ->assertOk()
            ->assertJsonPath('data.review.final_score', 72);

        $this->assertSame(72, $session->refresh()->score);
        $this->actingAsUser($manager)->getJson('/api/review-queue')->assertJsonCount(0, 'data.to_review');
    }

    public function test_manager_can_comment_on_a_transcript_line_and_owner_sees_it(): void
    {
        $broker = User::factory()->create();
        $session = PracticeSession::factory()->create(['user_id' => $broker->id]);

        $this->actingAsUser(User::factory()->manager()->create())
            ->postJson("/api/practice-sessions/{$session->id}/comments", ['turn_index' => 1, 'body' => 'Pause here.'])
            ->assertCreated()
            ->assertJsonPath('data.turn_index', 1);

        $this->actingAsUser(User::factory()->manager()->create())
            ->postJson("/api/practice-sessions/{$session->id}/comments", ['turn_index' => 9, 'body' => 'Out of range'])
            ->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->actingAsUser($broker)->getJson("/api/practice-sessions/{$session->id}/review")
            ->assertOk()
            ->assertJsonPath('data.comments.0.body', 'Pause here.');

        $this->app['auth']->forgetGuards();
        $this->actingAsUser(User::factory()->create())->getJson("/api/practice-sessions/{$session->id}/review")->assertForbidden();
    }

    public function test_broker_can_request_rereview_only_on_own_call(): void
    {
        $broker = User::factory()->create();
        $mine = PracticeSession::factory()->create(['user_id' => $broker->id]);
        $theirs = PracticeSession::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAsUser($broker)->postJson("/api/practice-sessions/{$theirs->id}/rereview", ['message' => 'Please'])->assertForbidden();
        $this->actingAsUser($broker)->postJson("/api/practice-sessions/{$mine->id}/rereview", ['criterion' => 'Discovery', 'message' => 'I asked about budget.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open');
        // Only one open request per call.
        $this->actingAsUser($broker)->postJson("/api/practice-sessions/{$mine->id}/rereview", ['message' => 'Again'])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->actingAsUser(User::factory()->manager()->create())->getJson('/api/review-queue')
            ->assertJsonPath('data.counts.rereviews_open', 1)
            ->assertJsonPath('data.rereviews.0.criterion', 'Discovery')
            ->assertJsonPath('data.rereviews.0.session.id', $mine->id);
    }

    public function test_manager_answers_rereview_by_changing_the_criterion_score(): void
    {
        $broker = User::factory()->create();
        $session = PracticeSession::factory()->create(['user_id' => $broker->id, 'score' => 60, 'ai_report' => $this->aiReport()]);
        $rereview = PracticeSessionRereview::create([
            'practice_session_id' => $session->id, 'user_id' => $broker->id, 'criterion' => 'Discovery', 'message' => 'I did ask.', 'status' => 'open',
        ]);

        $this->actingAsUser(User::factory()->manager()->create())
            ->postJson("/api/practice-session-rereviews/{$rereview->id}/answer", ['decision' => 'changed', 'reply' => 'Fair point.'])
            ->assertUnprocessable();

        $this->actingAsUser(User::factory()->manager()->create())
            ->postJson("/api/practice-session-rereviews/{$rereview->id}/answer", ['decision' => 'changed', 'new_score' => 100, 'reply' => 'Fair point.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'answered')
            ->assertJsonPath('data.decision', 'changed')
            ->assertJsonPath('data.new_score', 100);

        // Opener 50→50, Discovery 0→100: +50 on AI 60.
        $this->assertSame(100, $session->refresh()->score);
        $this->assertSame('adjusted', $session->review->criteria[1]['status']);

        $this->app['auth']->forgetGuards();
        $this->actingAsUser($broker)->postJson("/api/practice-session-rereviews/{$rereview->id}/answer", ['decision' => 'kept'])->assertForbidden();
    }

    public function test_manager_can_keep_the_score(): void
    {
        $broker = User::factory()->create();
        $session = PracticeSession::factory()->create(['user_id' => $broker->id, 'score' => 60]);
        $rereview = PracticeSessionRereview::create([
            'practice_session_id' => $session->id, 'user_id' => $broker->id, 'message' => 'Hmm', 'status' => 'open',
        ]);

        $this->actingAsUser(User::factory()->manager()->create())
            ->postJson("/api/practice-session-rereviews/{$rereview->id}/answer", ['decision' => 'kept', 'reply' => 'Score stands.'])
            ->assertOk()
            ->assertJsonPath('data.decision', 'kept')
            ->assertJsonPath('data.reply', 'Score stands.');

        $this->assertSame(60, $session->refresh()->score);
    }

    /**
     * @return array<string, mixed>
     */
    private function aiReport(): array
    {
        return [
            'score' => 60,
            'scorecard' => [
                ['name' => 'Opener', 'items' => [['label' => 'a', 'passed' => true], ['label' => 'b', 'passed' => false]]],
                ['name' => 'Discovery', 'items' => [['label' => 'c', 'passed' => false]]],
            ],
        ];
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
