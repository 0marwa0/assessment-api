<?php

namespace App\Http\Requests;

use App\Models\Simulation;
use App\Services\QuestionTypes;
use Illuminate\Validation\Validator;

trait QuestionRules
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function questionRules(string $prefix = '', bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            $prefix.'question_text' => [$presence, 'string', 'max:5000'],
            // R6: see App\Services\QuestionTypes for each type's options shape.
            $prefix.'type' => [$presence, 'string', 'in:'.implode(',', QuestionTypes::ALL)],
            // Per-type counts are checked in validateCorrectOption(); a short
            // answer question has no options at all.
            $prefix.'options' => [$required ? 'present' : 'sometimes', 'array', 'max:26'],
            $prefix.'options.*.label' => ['required_with:'.$prefix.'options', 'string', 'max:5'],
            $prefix.'options.*.text' => ['required_with:'.$prefix.'options', 'string', 'max:1000'],
            $prefix.'options.*.is_correct' => ['required_with:'.$prefix.'options', 'boolean'],
            // Matching: the right-hand side of the pair.
            $prefix.'options.*.match' => ['sometimes', 'nullable', 'string', 'max:1000'],
            $prefix.'settings' => ['sometimes', 'nullable', 'array'],
            $prefix.'settings.scoring' => ['sometimes', 'nullable', 'string', 'in:all,partial'],
            $prefix.'settings.model_answer' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // R4: roleplay call question (the simulation is checked in validateCorrectOption()).
            $prefix.'settings.simulation_id' => ['sometimes', 'nullable', 'integer'],
            $prefix.'settings.min_score' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            $prefix.'settings.weight_pct' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:90'],
            $prefix.'settings.attempts' => ['sometimes', 'nullable', 'integer', 'in:1,2'],
            $prefix.'settings.hold_for_review' => ['sometimes', 'nullable', 'boolean'],
            $prefix.'settings.pause_timer' => ['sometimes', 'nullable', 'boolean'],
            $prefix.'difficulty_level' => [$presence, 'string', 'max:50'],
            $prefix.'reward' => [$presence, 'integer', 'min:0'],
            $prefix.'required_to_pass_block' => [$presence, 'boolean'],
            $prefix.'answer_explanation' => ['nullable', 'string', 'max:5000'],
            $prefix.'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Per-type checks on a question's options (R6). Kept under the old name,
     * since every question request calls it.
     *
     * @param  array<string, mixed>  $question
     */
    protected function validateCorrectOption(Validator $validator, array $question, string $field): void
    {
        if (! array_key_exists('options', $question) || ! is_array($question['options'])) {
            return;
        }

        $type = QuestionTypes::normalize(is_string($question['type'] ?? null) ? $question['type'] : 'mcq');
        $options = array_values(array_filter($question['options'], 'is_array'));
        $count = count($options);
        $correct = collect($options)->filter(fn (array $o): bool => filter_var($o['is_correct'] ?? false, FILTER_VALIDATE_BOOL))->count();

        switch ($type) {
            case QuestionTypes::SHORT_ANSWER:
                if ($count > 0) {
                    $validator->errors()->add($field, 'A short written answer question has no answer options.');
                }

                return;

            case QuestionTypes::ROLEPLAY_CALL:
                if ($count > 0) {
                    $validator->errors()->add($field, 'A roleplay call question has no answer options.');
                }
                $simulationId = $question['settings']['simulation_id'] ?? null;
                $simulation = is_numeric($simulationId) ? Simulation::query()->find((int) $simulationId) : null;
                if ($simulation === null) {
                    $validator->errors()->add(preg_replace('/options$/', 'settings.simulation_id', $field), 'Pick the simulation the broker will call.');
                } elseif (! $simulation->isPublished()) {
                    $validator->errors()->add(preg_replace('/options$/', 'settings.simulation_id', $field), 'Publish this simulation before adding it to a test.');
                }

                return;

            case 'true_false':
                $texts = collect($options)->map(fn (array $o): string => mb_strtolower(trim((string) ($o['text'] ?? ''))))->all();
                if ($count !== 2 || $texts !== ['true', 'false']) {
                    $validator->errors()->add($field, 'A true or false question has exactly two answers: True and False.');
                } elseif ($correct !== 1) {
                    $validator->errors()->add($field, 'Mark either True or False as correct.');
                }

                return;

            case QuestionTypes::ORDERING:
                if ($count < 2 || $count > 10) {
                    $validator->errors()->add($field, 'Put in order needs 2 to 10 items.');
                }

                return;

            case QuestionTypes::MATCHING:
                if ($count < 2 || $count > 10) {
                    $validator->errors()->add($field, 'Match pairs needs 2 to 10 pairs.');
                }
                foreach ($options as $index => $option) {
                    if (blank($option['match'] ?? null)) {
                        $validator->errors()->add("$field.$index.match", 'Each pair needs both sides filled in.');
                    }
                }

                return;

            case 'ai_voice':
            case 'ai_chat':
                return;

            default: // mcq, multi_select
                if ($count < 2) {
                    $validator->errors()->add($field, 'A choice question needs at least two answers.');
                } elseif ($correct === 0) {
                    $validator->errors()->add($field, 'At least one option must be marked as correct.');
                }
        }
    }

    /**
     * Checks option labels are unique within this single question only.
     *
     * The built-in `distinct` rule can't be used here: when this rule set is
     * embedded under outer wildcards (e.g. blocks.*.questions.*.options.*.label),
     * Laravel's distinct validator resolves back to that full wildcard pattern
     * and compares every option label across the entire payload, not just
     * within one question — so every question's "A"/"B" labels collide with
     * every other question's. This checks only the one question's own options.
     */
    protected function validateDistinctOptionLabels(Validator $validator, array $question, string $field): void
    {
        if (! array_key_exists('options', $question) || ! is_array($question['options'])) {
            return;
        }

        $seenLabels = [];
        foreach ($question['options'] as $optionIndex => $option) {
            if (! is_array($option) || ! array_key_exists('label', $option)) {
                continue;
            }

            $normalized = mb_strtolower((string) $option['label']);
            if (isset($seenLabels[$normalized])) {
                $validator->errors()->add("$field.$optionIndex.label", 'The label has already been used by another option in this question.');
            }
            $seenLabels[$normalized] = true;
        }
    }
}
