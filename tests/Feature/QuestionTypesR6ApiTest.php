<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\User;
use App\Services\QuestionTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backlog R6: more question types (true/false, multiple select, put in order,
 * match pairs, short written answer) and question pools.
 */
class QuestionTypesR6ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_new_type_saves_and_bad_shapes_are_rejected(): void
    {
        $manager = User::factory()->manager()->create();
        $payload = $this->payload([
            $this->q('true_false', [['A', 'True', false], ['B', 'False', true]]),
            $this->q('multi_select', [['A', 'One', true], ['B', 'Two', true], ['C', 'Three', false]]),
            $this->q('ordering', [['A', 'First', true], ['B', 'Second', true], ['C', 'Third', true]], ['scoring' => 'partial']),
            $this->q('matching', [['A', 'Escrow', true, 'Protects buyer funds'], ['B', 'Oqood', true, 'Off-plan registration']]),
            $this->q('short_answer', [], ['model_answer' => 'Ask about budget first.']),
        ], poolSize: 3);

        $this->actingAsUser($manager)->postJson('/api/assessments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.blocks.0.pool_size', 3)
            ->assertJsonPath('data.blocks.0.questions.2.settings.scoring', 'partial')
            ->assertJsonPath('data.blocks.0.questions.3.options.1.match', 'Off-plan registration')
            ->assertJsonPath('data.blocks.0.questions.4.settings.model_answer', 'Ask about budget first.');

        $bad = [
            'blocks.0.questions.0.options' => $this->q('true_false', [['A', 'Yes', true], ['B', 'No', false]]),
            'blocks.0.questions.0.options.1.match' => $this->q('matching', [['A', 'Escrow', true, 'x'], ['B', 'Oqood', true, '']]),
            'blocks.0.questions.0.type' => $this->q('essay', [['A', 'x', true], ['B', 'y', false]]),
        ];
        foreach ($bad as $error => $question) {
            $this->actingAsUser($manager)->postJson('/api/assessments', $this->payload([$question]))
                ->assertUnprocessable()->assertJsonValidationErrors($error);
        }
        $this->actingAsUser($manager)->postJson('/api/assessments', $this->payload([$this->q('short_answer', [['A', 'x', true]])]))
            ->assertUnprocessable()->assertJsonValidationErrors('blocks.0.questions.0.options');
        $this->actingAsUser($manager)->postJson('/api/assessments', $this->payload([$this->q('ordering', [['A', 'only', true]])]))
            ->assertUnprocessable()->assertJsonValidationErrors('blocks.0.questions.0.options');
    }

    public function test_learners_get_shuffled_keyed_items_without_order_pairs_or_model_answer(): void
    {
        $assessment = $this->makeAssessment();
        $data = $this->actingAsUser(User::factory()->create())->getJson("/api/assessments/{$assessment->id}")->assertOk()->json('data.blocks.0.questions');
        [, , $ordering, $matching, $short] = $data;

        $this->assertArrayNotHasKey('label', $ordering['options'][0]);
        $this->assertNotSame(['First', 'Second', 'Third'], array_column($ordering['options'], 'text'));
        $this->assertCount(3, array_filter(array_column($ordering['options'], 'key')));
        $this->assertArrayNotHasKey('match', $matching['options'][0]);
        $this->assertEqualsCanonicalizing(['Protects buyer funds', 'Off-plan registration'], array_column($matching['match_choices'], 'text'));
        $this->assertNull($short['settings']);
        $this->assertTrue($data[1]['multiple_correct']);
    }

    public function test_each_type_is_graded_on_the_server(): void
    {
        $assessment = $this->makeAssessment(['hard_gates_enabled' => false]);
        [$tf, $multi, $ord, $match, $short] = $assessment->blocks()->first()->questions()->pluck('id')->all();
        $k = fn (int $id, string $side, int $i) => QuestionTypes::key($id, $side, $i);

        // All right (short answer pending): 4/4 = 100%.
        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [
                $tf => 1,
                $multi => [0, 1],
                $ord => [$k($ord, 'item', 0), $k($ord, 'item', 1), $k($ord, 'item', 2)],
                $match => [$k($match, 'item', 0) => $k($match, 'match', 0), $k($match, 'item', 1) => $k($match, 'match', 1)],
                $short => 'I would ask about their budget.',
            ],
            'elapsed_seconds' => 30,
        ])
            ->assertCreated()
            ->assertJsonPath('data.score_pct', 100)
            ->assertJsonPath('data.total_questions', 4)
            ->assertJsonPath('pending_review', 1)
            ->assertJsonPath('data.module_results.0.questionResults.4.pending', true)
            ->assertJsonPath('data.module_results.0.questionResults.4.response', 'I would ask about their budget.')
            ->assertJsonPath('data.module_results.0.questionResults.4.model_answer', 'Ask about budget first.')
            ->assertJsonPath('data.module_results.0.questionResults.2.correct_order', [0, 1, 2]);

        // Ordering with partial credit (1 of 3 in place) and a swapped match (all or nothing):
        // tf 1 + multi 0 (only one of two picked) + ordering 1/3 + matching 0 = 1.33 / 4 = 33%.
        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [
                $tf => 1,
                $multi => [0],
                $ord => [$k($ord, 'item', 0), $k($ord, 'item', 2), $k($ord, 'item', 1)],
                $match => [$k($match, 'item', 0) => $k($match, 'match', 1), $k($match, 'item', 1) => $k($match, 'match', 0)],
            ],
            'elapsed_seconds' => 30,
        ])
            ->assertCreated()
            ->assertJsonPath('data.score_pct', 33)
            ->assertJsonPath('data.total_correct', 1)
            ->assertJsonPath('data.module_results.0.questionResults.2.correct', false)
            ->assertJsonPath('data.module_results.0.questionResults.2.xp', 3)
            ->assertJsonPath('data.module_results.0.questionResults.3.chosen', [1, 0])
            ->assertJsonPath('pending_review', 0);
    }

    public function test_answer_shapes_are_validated(): void
    {
        $assessment = $this->makeAssessment();
        $id = $assessment->blocks()->first()->questions()->value('id');

        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id,
            'answers' => [$id => [['nested' => true]]],
            'elapsed_seconds' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors("answers.$id");
    }

    public function test_pools_draw_on_start_and_grade_only_the_drawn_questions(): void
    {
        $assessment = $this->makeAssessment(['hard_gates_enabled' => false], poolSize: 2);
        $broker = User::factory()->create();

        // Without a start the attempt is refused.
        $this->actingAsUser($broker)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id, 'answers' => [], 'elapsed_seconds' => 1,
        ])->assertUnprocessable();

        $start = $this->actingAsUser($broker)->postJson("/api/assessments/{$assessment->id}/start")->assertCreated();
        $ids = $start->json('data.question_ids');
        $startId = $start->json('data.id');
        $this->assertCount(2, $ids);

        $response = $this->actingAsUser($broker)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id, 'assessment_start_id' => $startId, 'answers' => [], 'elapsed_seconds' => 1,
        ])->assertCreated();
        $graded = collect($response->json('data.module_results.0.questionResults'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing($ids, $graded);

        // A start is used once, and only by its owner.
        $this->actingAsUser($broker)->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id, 'assessment_start_id' => $startId, 'answers' => [], 'elapsed_seconds' => 1,
        ])->assertUnprocessable();
        $other = $this->actingAsUser($broker)->postJson("/api/assessments/{$assessment->id}/start")->json('data.id');
        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id, 'assessment_start_id' => $other, 'answers' => [], 'elapsed_seconds' => 1,
        ])->assertUnprocessable();
    }

    public function test_assessments_without_pools_still_work_without_a_start(): void
    {
        $assessment = $this->makeAssessment();

        $this->actingAsUser(User::factory()->create())->postJson('/api/assessment-attempts', [
            'assessment_id' => $assessment->id, 'answers' => [], 'elapsed_seconds' => 1,
        ])->assertCreated()->assertJsonPath('data.total_questions', 4);
    }

    // ── helpers ──

    /** @param list<array{0: string, 1: string, 2: bool, 3?: string}> $options */
    private function q(string $type, array $options, ?array $settings = null): array
    {
        return [
            'question_text' => 'Q '.$type,
            'type' => $type,
            'options' => array_map(fn ($o) => array_filter([
                'label' => $o[0], 'text' => $o[1], 'is_correct' => $o[2], 'match' => $o[3] ?? null,
            ], fn ($v) => $v !== null), $options),
            'settings' => $settings,
            'difficulty_level' => 'Medium',
            'reward' => 10,
            'required_to_pass_block' => true,
            'answer_explanation' => '',
        ];
    }

    private function payload(array $questions, ?int $poolSize = null): array
    {
        return [
            'title' => 'R6', 'description' => 'Types', 'type' => 'practice', 'status' => 'draft',
            'scoring_rules' => ['score_unit' => 'percent', 'junior_max_score' => 59, 'senior_min_score' => 85, 'hard_gates_enabled' => false],
            'usage_analytics' => ['times_used' => 0, 'completion_rate_percent' => 0, 'average_score_percent' => 0, 'completed_attempts' => 0, 'started_attempts' => 0, 'last_attempted_at' => now()->toISOString()],
            'blocks' => [['title' => 'Block', 'pool_size' => $poolSize, 'questions' => $questions]],
        ];
    }

    private function makeAssessment(array $rules = [], ?int $poolSize = null): Assessment
    {
        $assessment = Assessment::create([
            'title' => 'R6', 'description' => 'Types', 'type' => 'practice', 'status' => 'published',
            'scoring_rules' => ['score_unit' => 'percent', 'junior_max_score' => 59, 'senior_min_score' => 85, 'hard_gates_enabled' => true, ...$rules],
            'usage_analytics' => [],
        ]);
        $block = $assessment->blocks()->create(['title' => 'Block', 'position' => 0, 'pool_size' => $poolSize]);
        $questions = [
            $this->q('true_false', [['A', 'True', false], ['B', 'False', true]]),
            $this->q('multi_select', [['A', 'One', true], ['B', 'Two', true], ['C', 'Three', false]]),
            $this->q('ordering', [['A', 'First', true], ['B', 'Second', true], ['C', 'Third', true]], ['scoring' => 'partial']),
            $this->q('matching', [['A', 'Escrow', true, 'Protects buyer funds'], ['B', 'Oqood', true, 'Off-plan registration']]),
            $this->q('short_answer', [], ['model_answer' => 'Ask about budget first.']),
        ];
        foreach ($questions as $i => $q) {
            $block->questions()->create([...$q, 'assessment_id' => $assessment->id, 'position' => $i]);
        }

        return $assessment;
    }

    private function actingAsUser(User $user): static
    {
        $token = $user->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
