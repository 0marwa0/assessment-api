<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backlog MG3 (+ R2): builder settings in scoring_rules, block gates, pass
 * bonus XP, stored chosen answers and real usage numbers.
 */
class AssessmentBuilderMg3ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_builder_saves_the_new_settings(): void
    {
        $manager = User::factory()->manager()->create();
        $rules = [
            'score_unit' => 'percent',
            'junior_max_score' => 59,
            'senior_min_score' => 85,
            'hard_gates_enabled' => true,
            'pass_pct' => 70,
            'time_limit_minutes' => 15,
            'block_gates' => ['0' => 80],
            'pass_bonus_xp' => 50,
            'warn_last_minute' => false,
            'shuffle_questions' => true,
            'shuffle_answers' => true,
            'allow_mark_for_review' => false,
            'results_visibility' => 'score',
            'default_max_attempts' => 2,
            'certificate_enabled' => false,
        ];

        $this->actingAsUser($manager)->postJson('/api/assessments', [
            'title' => 'Settings test',
            'description' => 'x',
            'type' => 'practice',
            'status' => 'draft',
            'scoring_rules' => $rules,
            'usage_analytics' => [
                'times_used' => 0, 'completion_rate_percent' => 0, 'average_score_percent' => 0,
                'completed_attempts' => 0, 'started_attempts' => 0, 'last_attempted_at' => '2026-01-01T00:00:00Z',
            ],
            'blocks' => [[
                'title' => 'Block',
                'questions' => [[
                    'question_text' => '1. Q',
                    'type' => 'mcq',
                    'options' => [['label' => 'A', 'text' => 'Yes', 'is_correct' => true], ['label' => 'B', 'text' => 'No', 'is_correct' => false]],
                    'reward' => 10,
                    'difficulty_level' => 'Medium',
                    'required_to_pass_block' => true,
                ]],
            ]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.scoring_rules.results_visibility', 'score')
            ->assertJsonPath('data.scoring_rules.default_max_attempts', 2)
            ->assertJsonPath('data.scoring_rules.block_gates.0', 80)
            ->assertJsonPath('data.scoring_rules.certificate_enabled', false);
    }

    public function test_invalid_settings_are_rejected(): void
    {
        $assessment = $this->makeAssessment();

        $this->actingAsUser(User::factory()->manager()->create())
            ->putJson("/api/assessments/{$assessment->id}", [
                'scoring_rules' => [
                    'score_unit' => 'percent',
                    'junior_max_score' => 59,
                    'senior_min_score' => 85,
                    'hard_gates_enabled' => true,
                    'results_visibility' => 'everything',
                    'block_gates' => ['0' => 150],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scoring_rules.results_visibility', 'scoring_rules.block_gates.0']);
    }

    public function test_a_block_below_its_gate_fails_the_attempt(): void
    {
        // 2 of 3 = 67%: above the 50% pass mark, but below the block's 80% gate.
        $assessment = $this->makeAssessment(['pass_pct' => 50, 'hard_gates_enabled' => false, 'block_gates' => ['0' => 80]], questions: 3);
        [$q1, $q2, $q3] = $this->questionIds($assessment);

        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$q1 => 0, $q2 => 0, $q3 => 1],
            'elapsed_seconds' => 60,
        ])
            ->assertCreated()
            ->assertJsonPath('data.score_pct', 67)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.module_results.0.belowBlockGate', true);
    }

    public function test_without_gates_or_bonus_the_old_behaviour_is_kept(): void
    {
        $assessment = $this->makeAssessment(['pass_pct' => 50, 'hard_gates_enabled' => false], questions: 3);
        [$q1, $q2, $q3] = $this->questionIds($assessment);

        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$q1 => 0, $q2 => 0, $q3 => 1],
            'elapsed_seconds' => 60,
        ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.xp_earned', 20);
    }

    public function test_passing_adds_the_bonus_xp_and_failing_does_not(): void
    {
        $assessment = $this->makeAssessment(['pass_bonus_xp' => 25], questions: 1);
        [$q1] = $this->questionIds($assessment);
        $broker = User::factory()->create();

        $this->actingAsUser($broker)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$q1 => 0],
            'elapsed_seconds' => 60,
        ])->assertCreated()->assertJsonPath('data.passed', true)->assertJsonPath('data.xp_earned', 35);

        $this->actingAsUser($broker)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$q1 => 1],
            'elapsed_seconds' => 60,
        ])->assertCreated()->assertJsonPath('data.passed', false)->assertJsonPath('data.xp_earned', 0);
    }

    public function test_the_chosen_answer_is_stored_with_the_attempt(): void
    {
        $assessment = $this->makeAssessment(questions: 1);
        [$q1] = $this->questionIds($assessment);

        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$q1 => 1],
            'elapsed_seconds' => 60,
        ])->assertCreated();

        $stored = AssessmentAttempt::query()->firstOrFail()->module_results;
        $this->assertSame([1], $stored[0]['questionResults'][0]['chosen']);
    }

    public function test_usage_reports_real_numbers_hardest_question_first(): void
    {
        $assessment = $this->makeAssessment(['pass_pct' => 50, 'hard_gates_enabled' => false], questions: 2);
        [$q1, $q2] = $this->questionIds($assessment);
        $a = User::factory()->create();
        $b = User::factory()->create();

        // Broker A: fails first (both wrong), then passes. Broker B: passes first time (q2 wrong).
        foreach ([[$a, [$q1 => 1, $q2 => 1]], [$a, [$q1 => 0, $q2 => 0]], [$b, [$q1 => 0, $q2 => 1]]] as [$user, $answers]) {
            $this->actingAsUser($user)->postJson('/api/assessment-attempts', [
                'assessment_id' => $assessment->id,
                'answers' => $answers,
                'elapsed_seconds' => 300,
            ])->assertCreated();
        }

        $manager = User::factory()->manager()->create();
        $data = $this->actingAsUser($manager)
            ->getJson("/api/assessments/{$assessment->id}/usage?period=all")
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $data['attempts']);
        $this->assertSame(2, $data['brokers']);
        $this->assertSame(67, $data['pass_rate_pct']);
        $this->assertSame(50, $data['first_attempt_pass_rate_pct']);
        $this->assertSame(300, $data['avg_seconds']);
        $this->assertSame(1, $data['not_passed']);
        $this->assertSame(['Junior' => 1, 'Mid-Tier' => 0, 'Senior' => 1], $data['tiers']);
        // q2: 1 of 3 correct (33%) is the hardest, flagged, and "Wrong" was picked 2 of 3 times.
        $this->assertSame((string) $q2, $data['questions'][0]['id']);
        $this->assertSame(33, $data['questions'][0]['correct_pct']);
        $this->assertTrue($data['questions'][0]['check_wording']);
        $this->assertSame('Wrong', $data['questions'][0]['most_picked_wrong']['text']);
        $this->assertSame(67, $data['questions'][0]['most_picked_wrong']['pct']);

        $this->actingAsUser($manager)->getJson('/api/assessment-usage')
            ->assertOk()
            ->assertJsonPath("data.{$assessment->id}.attempts", 3)
            ->assertJsonPath("data.{$assessment->id}.pass_rate_pct", 67);
    }

    public function test_an_assessment_used_in_a_roadmap_cannot_be_deleted(): void
    {
        $assessment = $this->makeAssessment();
        $roadmap = \App\Models\Roadmap::factory()->create();
        \App\Models\RoadmapNode::factory()->create([
            'roadmap_id' => $roadmap->id, 'type' => 'assessment', 'assessment_id' => $assessment->id,
            'material_id' => null, 'simulation_id' => null,
        ]);

        $this->actingAsUser(User::factory()->manager()->create())
            ->deleteJson("/api/assessments/{$assessment->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', "It's used in 1 roadmap. Remove it from there first.");
        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);
    }

    public function test_brokers_cannot_see_usage(): void
    {
        $assessment = $this->makeAssessment();
        $broker = User::factory()->create();

        $this->actingAsUser($broker)->getJson("/api/assessments/{$assessment->id}/usage")->assertForbidden();
        $this->actingAsUser($broker)->getJson('/api/assessment-usage')->assertForbidden();
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
        return $assessment->blocks()->first()->questions()->orderBy('position')->pluck('id')->all();
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
