<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Certificate;
use App\Models\PracticeSession;
use App\Models\Simulation;
use App\Models\User;
use App\Models\XpAward;
use App\Notifications\AcademyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Backlog R4: a roleplay call inside a test (weighted, min score, held for
 * manager review) and manager grading of written answers.
 */
class RoleplayCallR4ApiTest extends TestCase
{
    use RefreshDatabase;

    private Simulation $simulation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->simulation = Simulation::factory()->create(['status' => 'published', 'title' => 'Hot lead', 'persona_name' => 'Sara']);
    }

    public function test_roleplay_question_settings_are_validated_and_shown_to_learners(): void
    {
        $manager = User::factory()->manager()->create();
        $draft = Simulation::factory()->create(['status' => 'draft']);

        $this->actingAsUser($manager)->postJson('/api/assessments', $this->payload([$this->callQuestion(['simulation_id' => $draft->id])]))
            ->assertUnprocessable()->assertJsonValidationErrors('blocks.0.questions.1.settings.simulation_id');
        $this->actingAsUser($manager)->postJson('/api/assessments', $this->payload([$this->callQuestion(['simulation_id' => 999999])]))
            ->assertUnprocessable()->assertJsonValidationErrors('blocks.0.questions.1.settings.simulation_id');
        $this->actingAsUser($manager)->postJson('/api/assessments', $this->payload([$this->callQuestion(['weight_pct' => 95])]))
            ->assertUnprocessable()->assertJsonValidationErrors('blocks.0.questions.1.settings.weight_pct');
        $this->actingAsUser($manager)->postJson('/api/assessments', $this->payload([$this->callQuestion(['attempts' => 3])]))
            ->assertUnprocessable()->assertJsonValidationErrors('blocks.0.questions.1.settings.attempts');

        $id = $this->actingAsUser($manager)->postJson('/api/assessments', $this->payload([$this->callQuestion()]))
            ->assertCreated()->json('data.id');
        Assessment::query()->whereKey($id)->update(['status' => 'published']);

        $question = collect($this->actingAsUser(User::factory()->create())->getJson("/api/assessments/{$id}")->assertOk()
            ->json('data.blocks.0.questions'))->firstWhere('type', 'roleplay_call');
        $this->assertSame($this->simulation->id, $question['settings']['simulation_id']);
        $this->assertSame(70, $question['settings']['min_score']);
        $this->assertSame(50, $question['settings']['weight_pct']);
        $this->assertSame('Hot lead', $question['simulation']['title']);
        $this->assertSame('Sara', $question['simulation']['persona_name']);
    }

    public function test_call_counts_its_weight_and_scores_all_or_nothing(): void
    {
        [$assessment, $mcq, $call] = $this->makeAssessment(['weight_pct' => 50, 'min_score' => 70]);
        $broker = User::factory()->create();

        // 2 of 4 written right (2/4) + call 80 ≥ 70 (full) → (2 + 4) / 8 = 75%.
        $session = $this->practiceCall($broker, 80);
        $this->submit($broker, $assessment, [$mcq[0] => 0, $mcq[1] => 0, $mcq[2] => 1, $mcq[3] => 1, $call => $session->id])
            ->assertCreated()
            ->assertJsonPath('data.score_pct', 75)
            ->assertJsonPath('pending_review', 0)
            ->assertJsonPath('is_final', true)
            ->assertJsonPath('data.module_results.0.questionResults.4.call_score', 80)
            ->assertJsonPath('data.module_results.0.questionResults.4.weight', 4);

        // All written right, call 69 < 70 → (4 + 0) / 8 = 50%, and the required call fails the attempt.
        $low = $this->practiceCall($broker, 69);
        $this->submit($broker, $assessment, [$mcq[0] => 0, $mcq[1] => 0, $mcq[2] => 0, $mcq[3] => 0, $call => $low->id])
            ->assertCreated()
            ->assertJsonPath('data.score_pct', 50)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.module_results.0.gateFailed', true)
            ->assertJsonPath('data.module_results.0.questionResults.4.points', 0);
    }

    public function test_best_of_two_attempts_counts(): void
    {
        [$assessment, $mcq, $call] = $this->makeAssessment(['attempts' => 2, 'weight_pct' => 20]);
        $broker = User::factory()->create();
        $a = $this->practiceCall($broker, 40);
        $b = $this->practiceCall($broker, 90);

        $this->submit($broker, $assessment, [$mcq[0] => 0, $mcq[1] => 0, $mcq[2] => 0, $mcq[3] => 0, $call => [$a->id, $b->id]])
            ->assertCreated()
            ->assertJsonPath('data.score_pct', 100)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.module_results.0.questionResults.4.session_id', $b->id);
    }

    public function test_session_must_be_the_learners_own_call_for_this_simulation(): void
    {
        [$assessment, $mcq, $call] = $this->makeAssessment();
        $broker = User::factory()->create();
        $other = $this->practiceCall(User::factory()->create(), 90);
        $wrongSim = $this->practiceCall($broker, 90, Simulation::factory()->create(['status' => 'published']));
        $empty = $this->practiceCall($broker, 90);
        $empty->update(['transcript' => []]);

        foreach ([$other->id, $wrongSim->id, $empty->id, [$this->practiceCall($broker, 90)->id, $this->practiceCall($broker, 90)->id]] as $answer) {
            $this->submit($broker, $assessment, [$call => $answer])->assertUnprocessable()->assertJsonValidationErrors("answers.$call");
        }
    }

    public function test_attempt_finalizes_when_the_ai_score_arrives(): void
    {
        Notification::fake();
        [$assessment, $mcq, $call] = $this->makeAssessment(['weight_pct' => 50], ['pass_bonus_xp' => 25]);
        $broker = User::factory()->create();
        $session = $this->practiceCall($broker, null);

        $attemptId = $this->submit($broker, $assessment, [$mcq[0] => 0, $mcq[1] => 0, $mcq[2] => 0, $mcq[3] => 0, $call => $session->id])
            ->assertCreated()
            ->assertJsonPath('pending_review', 1)
            ->assertJsonPath('is_final', false)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.score_pct', 100) // provisional: the call is left out
            ->assertJsonPath('data.module_results.0.questionResults.4.pending', true)
            ->json('data.id');
        $this->assertSame(40, (int) XpAward::query()->where('source_id', $attemptId)->value('amount'));

        // AI score (here set by the grading endpoint) settles the attempt.
        $manager = User::factory()->manager()->create();
        $this->actingAsUser($manager)->patchJson("/api/practice-sessions/{$session->id}", ['score' => 85])->assertOk();

        $attempt = AssessmentAttempt::query()->find($attemptId);
        $this->assertSame(0, $attempt->pending_review);
        $this->assertTrue($attempt->passed);
        $this->assertSame(100, $attempt->score_pct);
        $this->assertNotNull($attempt->finalized_at);
        // 4 × 10 + call 10 + pass bonus 25, in the same single award row.
        $this->assertSame(1, XpAward::query()->where('source_type', 'assessment_attempt')->where('source_id', $attemptId)->count());
        $this->assertSame(75, (int) XpAward::query()->where('source_id', $attemptId)->value('amount'));
        $this->assertSame(1, Certificate::query()->where('user_id', $broker->id)->count());
        Notification::assertSentTo($broker, AcademyNotification::class, fn ($n) => $n->kind === 'result');

        // Idempotent: a later score change doesn't award again or re-notify.
        $this->actingAsUser($manager)->patchJson("/api/practice-sessions/{$session->id}", ['score' => 90])->assertOk();
        $this->assertSame(1, XpAward::query()->where('source_id', $attemptId)->count());
        $this->assertSame(75, (int) XpAward::query()->where('source_id', $attemptId)->value('amount'));
        $this->assertSame(1, Certificate::query()->where('user_id', $broker->id)->count());
        Notification::assertSentToTimes($broker, AcademyNotification::class, 2); // certificate + result
    }

    public function test_hold_for_review_waits_for_the_manager_score(): void
    {
        [$assessment, $mcq, $call] = $this->makeAssessment(['hold_for_review' => true, 'min_score' => 70]);
        $broker = User::factory()->create();
        $session = $this->practiceCall($broker, 90); // AI score present, but held

        $attemptId = $this->submit($broker, $assessment, [$mcq[0] => 0, $mcq[1] => 0, $mcq[2] => 0, $mcq[3] => 0, $call => $session->id])
            ->assertCreated()->assertJsonPath('pending_review', 1)->json('data.id');

        $manager = User::factory()->manager()->create();
        $this->actingAsUser($manager)->getJson('/api/written-answers?type=roleplay')
            ->assertOk()->assertJsonPath('data.0.attempt_id', $attemptId)->assertJsonPath('data.0.hold_for_review', true);

        $this->actingAsUser($manager)->patchJson("/api/practice-sessions/{$session->id}", ['score' => 95])->assertOk();
        $this->assertSame(1, AssessmentAttempt::query()->find($attemptId)->pending_review);

        // Manager scores it 60 < 70: zero points, required call fails the attempt.
        $this->actingAsUser($manager)->postJson("/api/practice-sessions/{$session->id}/review", ['final_score' => 60])->assertOk();
        $attempt = AssessmentAttempt::query()->find($attemptId);
        $this->assertSame(0, $attempt->pending_review);
        $this->assertFalse($attempt->passed);
        $this->assertSame(60, $attempt->module_results[0]['questionResults'][4]['call_score']);
    }

    public function test_manager_grades_written_answers(): void
    {
        Notification::fake();
        [$assessment, $mcq] = $this->makeAssessment(null, [], withShort: true);
        $short = $assessment->blocks()->first()->questions()->where('type', 'short_answer')->value('id');
        $broker = User::factory()->create();

        $attemptId = $this->submit($broker, $assessment, [$mcq[0] => 0, $mcq[1] => 0, $mcq[2] => 0, $mcq[3] => 1, $short => 'Budget first.'])
            ->assertCreated()->assertJsonPath('pending_review', 1)->assertJsonPath('data.score_pct', 75)->json('data.id');

        $this->actingAsUser($broker)->getJson('/api/written-answers')->assertForbidden();
        $this->actingAsUser($broker)->postJson("/api/assessment-attempts/{$attemptId}/questions/{$short}/grade", ['credit' => 1])->assertForbidden();

        $manager = User::factory()->manager()->create();
        $this->actingAsUser($manager)->getJson('/api/written-answers?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.response', 'Budget first.')
            ->assertJsonPath('data.0.model_answer', 'Ask about budget first.')
            ->assertJsonPath('data.0.broker.name', $broker->name);

        // Full credit: (3 + 1) / 5 = 80% → passes, final.
        $this->actingAsUser($manager)->postJson("/api/assessment-attempts/{$attemptId}/questions/{$short}/grade", ['credit' => 1, 'comment' => 'Good'])
            ->assertOk()->assertJsonPath('data.score_pct', 80)->assertJsonPath('is_final', true)->assertJsonPath('data.passed', true);
        Notification::assertSentTo($broker, AcademyNotification::class, fn ($n) => $n->kind === 'result');

        // Re-grade to half credit: (3 + 0.5) / 5 = 70%; history kept, XP row topped down, not duplicated.
        $this->actingAsUser($manager)->postJson("/api/assessment-attempts/{$attemptId}/questions/{$short}/grade", ['credit' => 0.5])
            ->assertOk()->assertJsonPath('data.score_pct', 70);
        $q = AssessmentAttempt::query()->find($attemptId)->module_results[0]['questionResults'][4];
        $this->assertSame(0.5, (float) $q['decision']['credit']);
        $this->assertCount(1, $q['history']);
        $this->assertSame(1, XpAward::query()->where('source_id', $attemptId)->count());
        $this->assertSame(35, (int) XpAward::query()->where('source_id', $attemptId)->value('amount'));

        $this->actingAsUser($manager)->getJson('/api/written-answers?status=pending')->assertJsonCount(0, 'data');
        $this->actingAsUser($manager)->getJson('/api/written-answers?status=graded')->assertJsonCount(1, 'data');
        $this->actingAsUser($manager)->postJson("/api/assessment-attempts/{$attemptId}/questions/{$mcq[0]}/grade", ['credit' => 1])->assertNotFound();
        $this->actingAsUser($manager)->postJson("/api/assessment-attempts/{$attemptId}/questions/{$short}/grade", ['credit' => 2])->assertUnprocessable();
    }

    /**
     * 4 MCQs (option 0 correct) + optionally a roleplay call / short answer, all required.
     *
     * @return array{0: Assessment, 1: list<int>, 2: ?int}
     */
    private function makeAssessment(?array $call = [], array $rules = [], bool $withShort = false): array
    {
        $assessment = Assessment::create([
            'title' => 'R4', 'description' => 'Call', 'type' => 'practice', 'status' => 'published',
            'scoring_rules' => ['pass_pct' => 70, 'hard_gates_enabled' => true, ...$rules],
            'usage_analytics' => [],
        ]);
        $block = $assessment->blocks()->create(['title' => 'Block', 'position' => 0]);
        $mcq = [];
        for ($i = 0; $i < 4; $i++) {
            $mcq[] = $block->questions()->create([
                'assessment_id' => $assessment->id, 'question_text' => "Q$i", 'type' => 'mcq', 'position' => $i,
                'options' => [['label' => 'A', 'text' => 'Right', 'is_correct' => true], ['label' => 'B', 'text' => 'Wrong', 'is_correct' => false]],
                'difficulty_level' => 'Medium', 'reward' => 10, 'required_to_pass_block' => false,
            ])->id;
        }
        $callId = null;
        if ($call !== null) {
            $callId = $block->questions()->create([
                'assessment_id' => $assessment->id, 'question_text' => 'Call Sara', 'type' => 'roleplay_call', 'position' => 4,
                'options' => [], 'settings' => ['simulation_id' => $this->simulation->id, ...$call],
                'difficulty_level' => 'Medium', 'reward' => 10, 'required_to_pass_block' => true,
            ])->id;
        }
        if ($withShort) {
            $block->questions()->create([
                'assessment_id' => $assessment->id, 'question_text' => 'Explain', 'type' => 'short_answer', 'position' => 5,
                'options' => [], 'settings' => ['model_answer' => 'Ask about budget first.'],
                'difficulty_level' => 'Medium', 'reward' => 10, 'required_to_pass_block' => false,
            ]);
        }

        return [$assessment, $mcq, $callId];
    }

    private function practiceCall(User $user, ?int $score, ?Simulation $simulation = null): PracticeSession
    {
        return PracticeSession::factory()->create([
            'user_id' => $user->id,
            'simulation_id' => ($simulation ?? $this->simulation)->id,
            'score' => $score,
        ]);
    }

    private function submit(User $user, Assessment $assessment, array $answers)
    {
        return $this->actingAsUser($user)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => $answers,
            'elapsed_seconds' => 60,
        ]);
    }

    private function callQuestion(array $settings = []): array
    {
        return [
            'question_text' => 'Call the lead',
            'type' => 'roleplay_call',
            'options' => [],
            'settings' => ['simulation_id' => $this->simulation->id, 'min_score' => 70, 'weight_pct' => 50, 'attempts' => 1, 'hold_for_review' => false, 'pause_timer' => true, ...$settings],
            'difficulty_level' => 'Medium',
            'reward' => 10,
            'required_to_pass_block' => true,
            'answer_explanation' => '',
        ];
    }

    private function payload(array $questions): array
    {
        return [
            'title' => 'R4', 'description' => 'Call', 'type' => 'practice', 'status' => 'draft',
            'scoring_rules' => ['score_unit' => 'percent', 'junior_max_score' => 59, 'senior_min_score' => 85, 'hard_gates_enabled' => true],
            'usage_analytics' => ['times_used' => 0, 'completion_rate_percent' => 0, 'average_score_percent' => 0, 'completed_attempts' => 0, 'started_attempts' => 0, 'last_attempted_at' => now()->toISOString()],
            'blocks' => [['title' => 'Block', 'questions' => [
                ['question_text' => 'Q1', 'type' => 'mcq', 'options' => [['label' => 'A', 'text' => 'x', 'is_correct' => true], ['label' => 'B', 'text' => 'y', 'is_correct' => false]],
                    'difficulty_level' => 'Medium', 'reward' => 10, 'required_to_pass_block' => false, 'answer_explanation' => ''],
                ...$questions,
            ]]],
        ];
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
