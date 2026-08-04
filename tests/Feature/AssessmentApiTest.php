<?php

namespace Tests\Feature;

use App\Models\Assessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_assessment_can_be_created_with_ui_question_fields(): void
    {
        $response = $this->postJson('/api/assessments', $this->payload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Backend Fundamentals')
            ->assertJsonPath('data.description', 'Checks the learner understands API basics.')
            ->assertJsonPath('data.type', 'practice')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.scoring_rules.score_unit', 'percent')
            ->assertJsonPath('data.scoring_rules.junior_max_score', 59)
            ->assertJsonPath('data.scoring_rules.senior_min_score', 85)
            ->assertJsonPath('data.scoring_rules.hard_gates_enabled', true)
            ->assertJsonPath('data.usage_analytics.times_used', 124)
            ->assertJsonPath('data.usage_analytics.completion_rate_percent', 92)
            ->assertJsonPath('data.usage_analytics.average_score_percent', 78)
            ->assertJsonPath('data.usage_analytics.completed_attempts', 113)
            ->assertJsonPath('data.usage_analytics.started_attempts', 123)
            ->assertJsonPath('data.usage_analytics.last_attempted_at', '2026-08-04T10:30:00Z')
            ->assertJsonPath('data.blocks.0.title', 'HTTP Basics')
            ->assertJsonPath('data.blocks.0.questions.0.question_text', '1. New Custom Question')
            ->assertJsonPath('data.blocks.0.questions.0.type', 'mcq')
            ->assertJsonPath('data.blocks.0.questions.0.options.0.label', 'A')
            ->assertJsonPath('data.blocks.0.questions.0.options.0.is_correct', true)
            ->assertJsonPath('data.blocks.0.questions.0.difficulty_level', 'Medium')
            ->assertJsonPath('data.blocks.0.questions.0.reward', 10)
            ->assertJsonPath('data.blocks.0.questions.0.required_to_pass_block', true);

        $this->assertDatabaseHas('assessments', [
            'title' => 'Backend Fundamentals',
            'description' => 'Checks the learner understands API basics.',
            'type' => 'practice',
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('blocks', [
            'title' => 'HTTP Basics',
            'position' => 0,
        ]);

        $this->assertDatabaseHas('questions', [
            'question_text' => '1. New Custom Question',
            'type' => 'mcq',
            'difficulty_level' => 'Medium',
            'reward' => 10,
            'required_to_pass_block' => true,
        ]);
    }

    public function test_question_options_must_include_a_correct_answer(): void
    {
        $payload = $this->payload();
        $payload['blocks'][0]['questions'][0]['options'][0]['is_correct'] = false;

        $this->postJson('/api/assessments', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['blocks.0.questions.0.options']);
    }

    public function test_assessment_index_includes_status_counts_for_the_full_library(): void
    {
        Assessment::factory()->count(8)->create(['status' => 'draft']);
        Assessment::factory()->count(16)->create(['status' => 'published']);

        $response = $this->getJson('/api/assessments?per_page=10');

        $response
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 24)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.status_counts.draft', 8)
            ->assertJsonPath('meta.status_counts.published', 16)
            ->assertJsonPath('meta.status_counts.archived', 0);
    }

    public function test_assessment_blocks_can_be_replaced_on_update(): void
    {
        $assessment = Assessment::create([
            'title' => 'Backend Fundamentals',
            'description' => 'Checks the learner understands API basics.',
            'type' => 'practice',
            'status' => 'draft',
            'scoring_rules' => $this->scoringRules(),
            'usage_analytics' => $this->usageAnalytics(),
        ]);

        $block = $assessment->blocks()->create([
            'title' => 'HTTP Basics',
            'position' => 0,
        ]);

        $block->questions()->create(array_merge($this->payload()['blocks'][0]['questions'][0], [
            'assessment_id' => $assessment->id,
        ]));

        $response = $this->putJson("/api/assessments/{$assessment->id}", [
            'title' => 'Advanced Backend Fundamentals',
            'status' => 'published',
            'scoring_rules' => [
                'score_unit' => 'percent',
                'junior_max_score' => 49,
                'senior_min_score' => 90,
                'hard_gates_enabled' => false,
            ],
            'usage_analytics' => [
                'times_used' => 140,
                'completion_rate_percent' => 94,
                'average_score_percent' => 82,
                'completed_attempts' => 132,
                'started_attempts' => 140,
                'last_attempted_at' => '2026-08-04T12:00:00Z',
            ],
            'blocks' => [
                [
                    'title' => 'Routing',
                    'questions' => [
                        [
                            'question_text' => '2. Updated Question',
                            'type' => 'mcq',
                            'options' => [
                                ['label' => 'A', 'text' => 'First', 'is_correct' => false],
                                ['label' => 'B', 'text' => 'Second', 'is_correct' => true],
                            ],
                            'difficulty_level' => 'Hard',
                            'reward' => 25,
                            'required_to_pass_block' => false,
                            'answer_explanation' => 'Second is correct.',
                        ],
                    ],
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.title', 'Advanced Backend Fundamentals')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.scoring_rules.junior_max_score', 49)
            ->assertJsonPath('data.scoring_rules.senior_min_score', 90)
            ->assertJsonPath('data.scoring_rules.hard_gates_enabled', false)
            ->assertJsonPath('data.usage_analytics.times_used', 140)
            ->assertJsonPath('data.usage_analytics.average_score_percent', 82)
            ->assertJsonPath('data.usage_analytics.last_attempted_at', '2026-08-04T12:00:00Z')
            ->assertJsonPath('data.blocks.0.title', 'Routing')
            ->assertJsonPath('data.blocks.0.questions.0.question_text', '2. Updated Question')
            ->assertJsonPath('data.blocks.0.questions.0.reward', 25);

        $this->assertDatabaseCount('blocks', 1);
        $this->assertDatabaseCount('questions', 1);
    }

    public function test_assessment_can_be_cloned_as_a_new_draft_with_reset_usage_analytics(): void
    {
        $assessment = Assessment::create([
            'title' => 'Backend Fundamentals',
            'description' => 'Checks the learner understands API basics.',
            'type' => 'practice',
            'status' => 'published',
            'scoring_rules' => $this->scoringRules(),
            'usage_analytics' => $this->usageAnalytics(),
        ]);

        $block = $assessment->blocks()->create([
            'title' => 'HTTP Basics',
            'position' => 0,
        ]);

        $block->questions()->create(array_merge($this->payload()['blocks'][0]['questions'][0], [
            'assessment_id' => $assessment->id,
        ]));

        $response = $this->postJson("/api/assessments/{$assessment->id}/clone");

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Copy of Backend Fundamentals')
            ->assertJsonPath('data.description', 'Checks the learner understands API basics.')
            ->assertJsonPath('data.type', 'practice')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.scoring_rules.score_unit', 'percent')
            ->assertJsonPath('data.scoring_rules.junior_max_score', 59)
            ->assertJsonPath('data.usage_analytics.times_used', 0)
            ->assertJsonPath('data.usage_analytics.completion_rate_percent', 0)
            ->assertJsonPath('data.usage_analytics.average_score_percent', 0)
            ->assertJsonPath('data.usage_analytics.completed_attempts', 0)
            ->assertJsonPath('data.usage_analytics.started_attempts', 0)
            ->assertJsonPath('data.usage_analytics.last_attempted_at', null)
            ->assertJsonPath('data.blocks.0.title', 'HTTP Basics')
            ->assertJsonPath('data.blocks.0.questions.0.question_text', '1. New Custom Question');

        $cloneId = $response->json('data.id');

        $this->assertNotSame($assessment->id, $cloneId);
        $this->assertDatabaseCount('assessments', 2);
        $this->assertDatabaseCount('blocks', 2);
        $this->assertDatabaseCount('questions', 2);
        $this->assertDatabaseHas('assessments', [
            'id' => $assessment->id,
            'title' => 'Backend Fundamentals',
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('assessments', [
            'id' => $cloneId,
            'title' => 'Copy of Backend Fundamentals',
            'status' => 'draft',
        ]);
    }

    public function test_block_can_be_added_to_assessment(): void
    {
        $assessment = Assessment::create([
            'title' => 'Backend Fundamentals',
            'description' => 'Checks the learner understands API basics.',
            'type' => 'practice',
            'status' => 'draft',
            'scoring_rules' => $this->scoringRules(),
            'usage_analytics' => $this->usageAnalytics(),
        ]);

        $response = $this->postJson("/api/assessments/{$assessment->id}/blocks", [
            'title' => 'HTTP Basics',
            'questions' => $this->payload()['blocks'][0]['questions'],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.assessment_id', $assessment->id)
            ->assertJsonPath('data.title', 'HTTP Basics')
            ->assertJsonPath('data.questions.0.type', 'mcq');
    }

    public function test_question_can_be_added_to_block(): void
    {
        $assessment = Assessment::create([
            'title' => 'Backend Fundamentals',
            'description' => 'Checks the learner understands API basics.',
            'type' => 'practice',
            'status' => 'draft',
            'scoring_rules' => $this->scoringRules(),
            'usage_analytics' => $this->usageAnalytics(),
        ]);

        $block = $assessment->blocks()->create([
            'title' => 'HTTP Basics',
            'position' => 0,
        ]);

        $response = $this->postJson(
            "/api/assessments/{$assessment->id}/blocks/{$block->id}/questions",
            $this->payload()['blocks'][0]['questions'][0],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.assessment_id', $assessment->id)
            ->assertJsonPath('data.block_id', $block->id)
            ->assertJsonPath('data.type', 'mcq');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'title' => 'Backend Fundamentals',
            'description' => 'Checks the learner understands API basics.',
            'type' => 'practice',
            'status' => 'draft',
            'scoring_rules' => $this->scoringRules(),
            'usage_analytics' => $this->usageAnalytics(),
            'blocks' => [
                [
                    'title' => 'HTTP Basics',
                    'questions' => [
                        [
                            'question_text' => '1. New Custom Question',
                            'type' => 'mcq',
                            'options' => [
                                ['label' => 'A', 'text' => 'Correct option', 'is_correct' => true],
                                ['label' => 'B', 'text' => 'Distractor option', 'is_correct' => false],
                            ],
                            'difficulty_level' => 'Medium',
                            'reward' => 10,
                            'required_to_pass_block' => true,
                            'answer_explanation' => 'Option A is the correct answer.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scoringRules(): array
    {
        return [
            'score_unit' => 'percent',
            'junior_max_score' => 59,
            'senior_min_score' => 85,
            'hard_gates_enabled' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function usageAnalytics(): array
    {
        return [
            'times_used' => 124,
            'completion_rate_percent' => 92,
            'average_score_percent' => 78,
            'completed_attempts' => 113,
            'started_attempts' => 123,
            'last_attempted_at' => '2026-08-04T10:30:00Z',
        ];
    }
}
