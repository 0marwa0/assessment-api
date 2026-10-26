<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backlog R1: login required, learners never receive the answers, and the
 * server (not the browser) grades attempts.
 */
class AssessmentGradingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_assessments_require_login(): void
    {
        $assessment = $this->makeAssessment();

        $this->getJson('/api/assessments')->assertUnauthorized();
        $this->getJson("/api/assessments/{$assessment->id}")->assertUnauthorized();
        $this->deleteJson("/api/assessments/{$assessment->id}")->assertUnauthorized();
    }

    public function test_a_broker_cannot_edit_or_delete_an_assessment(): void
    {
        $assessment = $this->makeAssessment();
        $broker = User::factory()->create();

        $this->actingAsUser($broker)->deleteJson("/api/assessments/{$assessment->id}")->assertForbidden();
        $this->actingAsUser($broker)->postJson("/api/assessments/{$assessment->id}/clone")->assertForbidden();
        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);
    }

    public function test_a_broker_gets_questions_without_the_answers(): void
    {
        $assessment = $this->makeAssessment();

        $question = $this->actingAsUser(User::factory()->create())
            ->getJson("/api/assessments/{$assessment->id}")
            ->assertOk()
            ->json('data.blocks.0.questions.0');

        $this->assertCount(2, $question['options']);
        $this->assertArrayNotHasKey('is_correct', $question['options'][0]);
        $this->assertArrayNotHasKey('answer_explanation', $question);
    }

    public function test_a_manager_still_gets_the_answers(): void
    {
        $assessment = $this->makeAssessment();

        $this->actingAsUser(User::factory()->manager()->create())
            ->getJson("/api/assessments/{$assessment->id}")
            ->assertOk()
            ->assertJsonPath('data.blocks.0.questions.0.options.0.is_correct', true)
            ->assertJsonPath('data.blocks.0.questions.0.answer_explanation', 'A is right.');
    }

    public function test_the_server_grades_the_answers_and_uses_the_saved_pass_mark(): void
    {
        // 2 of 3 correct = 67%. Passes at a 50% pass mark (it would fail the old fixed 70%).
        $assessment = $this->makeAssessment(['pass_pct' => 50, 'hard_gates_enabled' => false], questions: 3);
        [$q1, $q2, $q3] = $this->questionIds($assessment);
        $broker = User::factory()->create();

        $this->actingAsUser($broker)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$q1 => 0, $q2 => 0, $q3 => 1],
            'elapsed_seconds' => 120,
            // A browser trying to pick its own score is ignored.
            'score_pct' => 100,
        ])
            ->assertCreated()
            ->assertJsonPath('data.score_pct', 67)
            ->assertJsonPath('data.total_correct', 2)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.tier', 'Mid-Tier')
            ->assertJsonPath('data.xp_earned', 20)
            ->assertJsonPath('pass_pct', 50);

        $this->assertDatabaseHas('assessment_attempts', ['user_id' => $broker->id, 'score_pct' => 67, 'passed' => true]);
        $this->assertDatabaseHas('xp_awards', ['user_id' => $broker->id, 'amount' => 20]);
    }

    public function test_a_wrong_must_pass_question_fails_the_attempt_when_hard_gates_are_on(): void
    {
        $assessment = $this->makeAssessment(['pass_pct' => 50, 'hard_gates_enabled' => true], questions: 3);
        [$q1, $q2, $q3] = $this->questionIds($assessment);

        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$q1 => 0, $q2 => 0, $q3 => 1],
            'elapsed_seconds' => 60,
        ])
            ->assertCreated()
            ->assertJsonPath('data.score_pct', 67)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.module_results.0.gateFailed', true);
    }

    public function test_unanswered_questions_count_as_wrong(): void
    {
        $assessment = $this->makeAssessment(questions: 2);

        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [],
            'elapsed_seconds' => 900,
        ])
            ->assertCreated()
            ->assertJsonPath('data.score_pct', 0)
            ->assertJsonPath('data.tier', 'Junior')
            ->assertJsonPath('data.passed', false);
    }

    public function test_notes_are_saved_with_the_attempt(): void
    {
        $assessment = $this->makeAssessment(questions: 1);
        [$q1] = $this->questionIds($assessment);

        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$q1 => 0],
            'elapsed_seconds' => 30,
            'notes' => [$q1 => '  I guessed this one. '],
        ])
            ->assertCreated()
            ->assertJsonPath('data.notes.0.question_id', (string) $q1)
            ->assertJsonPath('data.notes.0.note', 'I guessed this one.');
    }

    public function test_a_broker_cannot_submit_against_someone_elses_assignment(): void
    {
        $assessment = $this->makeAssessment(questions: 1);
        $assignment = AssessmentAssignment::create([
            'user_id' => User::factory()->create()->id,
            'assessment_id' => $assessment->id,
            'assigned_at' => now(),
        ]);

        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'assessment_assignment_id' => $assignment->id,
            'answers' => [],
            'elapsed_seconds' => 30,
        ])->assertForbidden();
    }

    public function test_a_switched_off_block_is_kept_hidden_from_learners_and_not_graded(): void
    {
        $assessment = $this->makeAssessment(questions: 1);
        $off = $assessment->blocks()->create(['title' => 'Draft module', 'position' => 1, 'enabled' => false]);
        $off->questions()->create([
            'assessment_id' => $assessment->id, 'question_text' => '1. Hidden', 'type' => 'mcq',
            'options' => [['label' => 'A', 'text' => 'x', 'is_correct' => true], ['label' => 'B', 'text' => 'y', 'is_correct' => false]],
            'difficulty_level' => 'Easy', 'reward' => 10, 'required_to_pass_block' => true, 'position' => 0,
        ]);
        [$q1] = $this->questionIds($assessment);

        // Manager still sees it, switched off.
        $this->actingAsUser(User::factory()->manager()->create())
            ->getJson("/api/assessments/{$assessment->id}")
            ->assertJsonCount(2, 'data.blocks')
            ->assertJsonPath('data.blocks.1.enabled', false);

        // Learner doesn't get it, and it doesn't count.
        $broker = User::factory()->create();
        $this->actingAsUser($broker)
            ->getJson("/api/assessments/{$assessment->id}")
            ->assertJsonCount(1, 'data.blocks');

        $this->actingAsUser($broker)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$q1 => 0],
            'elapsed_seconds' => 30,
        ])
            ->assertJsonPath('data.total_questions', 1)
            ->assertJsonPath('data.score_pct', 100);
    }

    public function test_the_builder_saves_a_switched_off_block(): void
    {
        $token = User::factory()->manager()->create()->createToken('api')->plainTextToken;
        $question = [
            'question_text' => '1. Q', 'type' => 'mcq', 'difficulty_level' => 'Easy', 'reward' => 10,
            'required_to_pass_block' => true, 'answer_explanation' => '',
            'options' => [['label' => 'A', 'text' => 'x', 'is_correct' => true], ['label' => 'B', 'text' => 'y', 'is_correct' => false]],
        ];

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/assessments', [
            'title' => 'T', 'description' => 'D', 'type' => 'practice', 'status' => 'draft',
            'scoring_rules' => ['score_unit' => 'percent', 'junior_max_score' => 59, 'senior_min_score' => 85, 'hard_gates_enabled' => true],
            'usage_analytics' => ['times_used' => 0, 'completion_rate_percent' => 0, 'average_score_percent' => 0, 'completed_attempts' => 0, 'started_attempts' => 0, 'last_attempted_at' => '2026-10-23T00:00:00Z'],
            'blocks' => [
                ['title' => 'On', 'enabled' => true, 'questions' => [$question]],
                ['title' => 'Off', 'enabled' => false, 'questions' => [$question]],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.blocks.1.title', 'Off')
            ->assertJsonPath('data.blocks.1.enabled', false);
    }

    public function test_select_all_that_apply_needs_exactly_the_correct_options(): void
    {
        $assessment = $this->makeAssessment(['hard_gates_enabled' => false], questions: 0);
        $block = $assessment->blocks()->first();
        $question = $block->questions()->create([
            'assessment_id' => $assessment->id, 'question_text' => '1. Pick B and D', 'type' => 'mcq',
            'options' => [
                ['label' => 'A', 'text' => 'a', 'is_correct' => false],
                ['label' => 'B', 'text' => 'b', 'is_correct' => true],
                ['label' => 'C', 'text' => 'c', 'is_correct' => false],
                ['label' => 'D', 'text' => 'd', 'is_correct' => true],
            ],
            'difficulty_level' => 'Medium', 'reward' => 10, 'required_to_pass_block' => false, 'position' => 0,
        ]);
        $broker = User::factory()->create();

        $this->actingAsUser($broker)->getJson("/api/assessments/{$assessment->id}")
            ->assertJsonPath('data.blocks.0.questions.0.multiple_correct', true);

        $submit = fn ($answer) => $this->actingAsUser($broker)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$question->id => $answer],
            'elapsed_seconds' => 10,
        ])->json('data.score_pct');

        $this->assertSame(100, $submit([3, 1]));
        $this->assertSame(0, $submit([1]));        // only one of the two
        $this->assertSame(0, $submit([1, 2, 3]));  // one extra wrong pick
    }

    /**
     * One block; question N's correct answer is option 0. Every question is
     * "required to pass" and worth 10 XP.
     *
     * @param  array<string, mixed>  $rules
     */
    public function test_a_past_result_can_be_reopened_by_its_owner_or_a_manager_only(): void
    {
        $assessment = $this->makeAssessment(['pass_pct' => 50, 'hard_gates_enabled' => false, 'results_visibility' => 'answers'], questions: 2);
        [$q1, $q2] = $this->questionIds($assessment);
        $broker = User::factory()->create();

        $id = $this->actingAsUser($broker)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$q1 => 0, $q2 => 1],
            'elapsed_seconds' => 60,
        ])->assertCreated()->json('data.id');

        $this->actingAsUser($broker)->getJson("/api/assessment-attempts/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.score_pct', 50)
            ->assertJsonPath('is_final', true)
            ->assertJsonPath('pending_review', 0)
            ->assertJsonPath('results_visibility', 'answers')
            ->assertJsonPath('attempt_number', 1)
            ->assertJsonPath('assessment_title', 'Off-plan basics')
            // Same reveal rules as submitting: answers yes, explanations no.
            ->assertJsonPath('data.module_results.0.questionResults.0.correct_indexes', [0])
            ->assertJsonPath('data.module_results.0.questionResults.0.explanation', '');

        $this->actingAsUser(User::factory()->manager()->create())->getJson("/api/assessment-attempts/{$id}")->assertOk();
        $this->actingAsUser(User::factory()->create())->getJson("/api/assessment-attempts/{$id}")->assertForbidden();
    }

    public function test_score_only_visibility_hides_the_questions_of_a_past_result(): void
    {
        $assessment = $this->makeAssessment(['results_visibility' => 'score']);
        $broker = User::factory()->create();
        $id = $this->actingAsUser($broker)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [],
            'elapsed_seconds' => 60,
        ])->json('data.id');

        $this->actingAsUser($broker)->getJson("/api/assessment-attempts/{$id}")
            ->assertOk()
            ->assertJsonPath('data.module_results.0.questionResults', []);
    }

    private function makeAssessment(array $rules = [], int $questions = 1): Assessment
    {
        $assessment = Assessment::create([
            'title' => 'Off-plan basics',
            'description' => 'Checks the basics.',
            'type' => 'practice',
            'status' => 'published',
            'scoring_rules' => [
                'score_unit' => 'percent',
                'junior_max_score' => 59,
                'senior_min_score' => 85,
                'hard_gates_enabled' => true,
                ...$rules,
            ],
            'usage_analytics' => [],
        ]);

        $block = $assessment->blocks()->create(['title' => 'Module 1', 'position' => 0]);

        for ($i = 0; $i < $questions; $i++) {
            $block->questions()->create([
                'assessment_id' => $assessment->id,
                'question_text' => ($i + 1).'. Question '.($i + 1),
                'type' => 'mcq',
                'options' => [
                    ['label' => 'A', 'text' => 'Right', 'is_correct' => true],
                    ['label' => 'B', 'text' => 'Wrong', 'is_correct' => false],
                ],
                'difficulty_level' => 'Medium',
                'reward' => 10,
                'required_to_pass_block' => true,
                'answer_explanation' => 'A is right.',
                'position' => $i,
            ]);
        }

        return $assessment;
    }

    /**
     * @return list<int>
     */
    private function questionIds(Assessment $assessment): array
    {
        return $assessment->blocks()->first()->questions()->pluck('id')->all();
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;
        // The test app keeps the first logged-in user; forget it so each
        // request runs as the user whose token it sends.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
