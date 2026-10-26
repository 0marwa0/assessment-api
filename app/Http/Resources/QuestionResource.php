<?php

namespace App\Http\Resources;

use App\Models\Simulation;
use App\Services\QuestionTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Learners get the question and its options, but not which option is
        // correct or the explanation; the server grades their answers.
        $canSeeAnswers = (bool) $request->user()?->isManager();

        // R6: ordering / matching items are shuffled and keyed for learners, so
        // the stored order (the answer) never reaches the browser.
        $learnerView = QuestionTypes::learnerView($this->id, (string) $this->type, array_values($this->options ?? []));
        $options = $canSeeAnswers ? $this->options : $learnerView['options'];
        $type = QuestionTypes::normalize($this->type);

        return [
            'id' => $this->id,
            'assessment_id' => $this->assessment_id,
            'block_id' => $this->block_id,
            'question_text' => $this->question_text,
            'type' => $this->type,
            'options' => $options,
            // Q5: more than one correct option, so learners pick all that apply.
            // Says only that there are several, not which ones.
            // A multi_select question always says "select all", even with one right answer.
            'multiple_correct' => $type === 'multi_select'
                || (QuestionTypes::isChoice($type) && collect($this->options ?? [])->filter(fn ($o) => (bool) ($o['is_correct'] ?? false))->count() > 1),
            // R6: shuffled right-hand answers of a matching question (learners only).
            'match_choices' => $this->when(! $canSeeAnswers && $learnerView['match_choices'] !== null, $learnerView['match_choices']),
            // R6: per-type extras. Learners get the scoring mode only, never a model answer.
            'settings' => $canSeeAnswers
                ? ($this->settings ?? null)
                : $this->learnerSettings($type),
            // R4: the simulation behind a roleplay call question (everyone).
            'simulation' => $this->when($type === QuestionTypes::ROLEPLAY_CALL, fn () => $this->simulationSummary()),
            'difficulty_level' => $this->difficulty_level,
            'reward' => $this->reward,
            'required_to_pass_block' => $this->required_to_pass_block,
            'answer_explanation' => $this->when($canSeeAnswers, $this->answer_explanation),
            'position' => $this->position,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Settings a learner may see: the scoring mode, and for a roleplay call
     * the call's rules (none of them are secret).
     *
     * @return array<string, mixed>|null
     */
    private function learnerSettings(string $type): ?array
    {
        if ($type === QuestionTypes::ROLEPLAY_CALL) {
            return QuestionTypes::roleplaySettings($this->settings);
        }

        return QuestionTypes::partial($this->settings) ? ['scoring' => 'partial'] : null;
    }

    /**
     * @return array{id: int, title: string, persona_name: ?string, language: ?string, time_required: mixed, level: ?string, status: ?string}|null
     */
    private function simulationSummary(): ?array
    {
        $id = QuestionTypes::roleplaySettings($this->settings)['simulation_id'];
        $simulation = $id === null ? null : Simulation::query()->find($id);

        return $simulation === null ? null : [
            'id' => $simulation->id,
            'title' => (string) $simulation->title,
            'persona_name' => $simulation->persona_name,
            'language' => $simulation->language,
            'time_required' => $simulation->time_required,
            'level' => $simulation->level,
            'status' => $simulation->status,
        ];
    }
}
