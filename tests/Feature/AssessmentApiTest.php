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
            ->assertJsonPath('data.type', 'mcq')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.questions.0.question_text', '1. New Custom Question')
            ->assertJsonPath('data.questions.0.options.0.label', 'A')
            ->assertJsonPath('data.questions.0.options.0.is_correct', true)
            ->assertJsonPath('data.questions.0.difficulty_level', 'Medium')
            ->assertJsonPath('data.questions.0.reward', 10)
            ->assertJsonPath('data.questions.0.required_to_pass_block', true);

        $this->assertDatabaseHas('assessments', [
            'type' => 'mcq',
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('questions', [
            'question_text' => '1. New Custom Question',
            'difficulty_level' => 'Medium',
            'reward' => 10,
            'required_to_pass_block' => true,
        ]);
    }

    public function test_question_options_must_include_a_correct_answer(): void
    {
        $payload = $this->payload();
        $payload['questions'][0]['options'][0]['is_correct'] = false;

        $this->postJson('/api/assessments', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['questions.0.options']);
    }

    public function test_assessment_questions_can_be_replaced_on_update(): void
    {
        $assessment = Assessment::create([
            'type' => 'mcq',
            'status' => 'draft',
        ]);

        $assessment->questions()->create($this->payload()['questions'][0]);

        $response = $this->putJson("/api/assessments/{$assessment->id}", [
            'status' => 'published',
            'questions' => [
                [
                    'question_text' => '2. Updated Question',
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
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.questions.0.question_text', '2. Updated Question')
            ->assertJsonPath('data.questions.0.reward', 25);

        $this->assertDatabaseCount('questions', 1);
    }

    public function test_question_can_be_added_to_assessment(): void
    {
        $assessment = Assessment::create([
            'type' => 'mcq',
            'status' => 'draft',
        ]);

        $response = $this->postJson("/api/assessments/{$assessment->id}/questions", $this->payload()['questions'][0]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.assessment_id', $assessment->id)
            ->assertJsonPath('data.question_type', 'mcq');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'type' => 'mcq',
            'status' => 'draft',
            'questions' => [
                [
                    'question_text' => '1. New Custom Question',
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
        ];
    }
}
