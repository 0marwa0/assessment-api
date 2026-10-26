<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backlog R9 (certificates) and the richer results returned after an attempt.
 */
class CertificateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_passing_attempt_issues_a_certificate(): void
    {
        $assessment = $this->makeAssessment(questions: 2);
        [$q1, $q2] = $this->questionIds($assessment);
        $broker = User::factory()->create();

        $code = $this->submit($broker, $assessment, [$q1 => 0, $q2 => 0])
            ->assertJsonPath('data.passed', true)
            ->json('certificate_code');

        $this->assertNotNull($code);
        $this->assertDatabaseHas('certificates', [
            'user_id' => $broker->id, 'assessment_id' => $assessment->id, 'score_pct' => 100, 'tier' => 'Senior', 'code' => $code,
        ]);
    }

    public function test_a_failing_attempt_issues_nothing(): void
    {
        $assessment = $this->makeAssessment(questions: 2);
        [$q1, $q2] = $this->questionIds($assessment);

        $this->submit(User::factory()->create(), $assessment, [$q1 => 1, $q2 => 1])
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('certificate_code', null);

        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_no_certificate_when_the_assessment_has_certificates_off(): void
    {
        $assessment = $this->makeAssessment(['certificate_enabled' => false], questions: 1);
        [$q1] = $this->questionIds($assessment);

        $this->submit(User::factory()->create(), $assessment, [$q1 => 0])->assertJsonPath('data.passed', true);

        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_a_better_attempt_upgrades_the_same_certificate(): void
    {
        $assessment = $this->makeAssessment(['pass_pct' => 50], questions: 3);
        [$q1, $q2, $q3] = $this->questionIds($assessment);
        $broker = User::factory()->create();

        $first = $this->submit($broker, $assessment, [$q1 => 0, $q2 => 0, $q3 => 1])->json('certificate_code');
        $this->assertDatabaseHas('certificates', ['code' => $first, 'score_pct' => 67, 'tier' => 'Mid-Tier']);

        $second = $this->submit($broker, $assessment, [$q1 => 0, $q2 => 0, $q3 => 0])
            ->assertJsonPath('attempt_number', 2)
            ->json('certificate_code');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseHas('certificates', ['code' => $first, 'score_pct' => 100, 'tier' => 'Senior']);
    }

    public function test_brokers_list_only_their_own_and_old_passes_are_backfilled(): void
    {
        $assessment = $this->makeAssessment(questions: 1);
        [$q1] = $this->questionIds($assessment);
        $broker = User::factory()->create();
        $other = User::factory()->create();
        $this->submit($other, $assessment, [$q1 => 0]);

        // A pass recorded before certificates existed.
        AssessmentAttempt::create([
            'user_id' => $broker->id, 'assessment_id' => $assessment->id, 'score_pct' => 90, 'passed' => true,
            'tier' => 'Senior', 'total_correct' => 1, 'total_questions' => 1, 'xp_earned' => 0, 'elapsed_seconds' => 30,
            'module_results' => [],
        ]);
        $this->assertDatabaseCount('certificates', 1);

        $this->actingAsUser($broker)->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $broker->id)
            ->assertJsonPath('data.0.assessment_title', 'Off-plan basics')
            ->assertJsonPath('data.0.score_pct', 90);

        // Brokers can't read someone else's list; managers can.
        $this->actingAsUser($broker)->getJson("/api/certificates?user_id={$other->id}")
            ->assertJsonPath('data.0.user_id', $broker->id);
        $this->actingAsUser(User::factory()->manager()->create())->getJson("/api/certificates?user_id={$other->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $other->id);
    }

    public function test_a_certificate_can_be_verified_by_code_without_login(): void
    {
        $assessment = $this->makeAssessment(questions: 1);
        [$q1] = $this->questionIds($assessment);
        $broker = User::factory()->create(['name' => 'Layla Broker']);
        $code = $this->submit($broker, $assessment, [$q1 => 0])->json('certificate_code');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson("/api/certificates/{$code}")
            ->assertOk()
            ->assertJsonPath('data.user_name', 'Layla Broker')
            ->assertJsonPath('data.assessment_title', 'Off-plan basics')
            ->assertJsonMissingPath('data.user_id');
        $this->getJson('/api/certificates/NOPE1234')->assertNotFound();
    }

    public function test_full_results_include_your_answer_the_correct_answer_and_explanation(): void
    {
        $assessment = $this->makeAssessment(questions: 1);
        [$q1] = $this->questionIds($assessment);

        $this->submit(User::factory()->create(), $assessment, [$q1 => 1])
            ->assertJsonPath('results_visibility', 'full')
            ->assertJsonPath('data.module_results.0.questionResults.0.chosen', [1])
            ->assertJsonPath('data.module_results.0.questionResults.0.correct_indexes', [0])
            ->assertJsonPath('data.module_results.0.questionResults.0.options', ['Right', 'Wrong'])
            ->assertJsonPath('data.module_results.0.questionResults.0.explanation', 'A is right.');

        // The stored attempt keeps the learner's choice but not the answer key.
        $stored = AssessmentAttempt::query()->first()->module_results[0]['questionResults'][0];
        $this->assertSame([1], $stored['chosen']);
        $this->assertArrayNotHasKey('correct_indexes', $stored);
    }

    public function test_answers_visibility_hides_explanations(): void
    {
        $assessment = $this->makeAssessment(['results_visibility' => 'answers'], questions: 1);
        [$q1] = $this->questionIds($assessment);

        $this->submit(User::factory()->create(), $assessment, [$q1 => 1])
            ->assertJsonPath('results_visibility', 'answers')
            ->assertJsonPath('data.module_results.0.questionResults.0.correct_indexes', [0])
            ->assertJsonPath('data.module_results.0.questionResults.0.explanation', '');
    }

    public function test_score_visibility_hides_per_question_detail(): void
    {
        $assessment = $this->makeAssessment(['results_visibility' => 'score'], questions: 1);
        [$q1] = $this->questionIds($assessment);

        $this->submit(User::factory()->create(), $assessment, [$q1 => 1])
            ->assertJsonPath('results_visibility', 'score')
            ->assertJsonPath('data.score_pct', 0)
            ->assertJsonCount(0, 'data.module_results.0.questionResults');
    }

    /**
     * @param  array<int, int>  $answers
     */
    private function submit(User $user, Assessment $assessment, array $answers): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsUser($user)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => $answers,
            'elapsed_seconds' => 60,
        ])->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $rules
     */
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
                'hard_gates_enabled' => false,
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
                'required_to_pass_block' => false,
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
        return $assessment->blocks()->first()->questions()->orderBy('position')->pluck('id')->all();
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
