<?php

namespace App\Http\Requests;

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
            $prefix.'question_type' => ['sometimes', 'string', 'max:50'],
            $prefix.'options' => [$presence, 'array', 'min:2', 'max:26'],
            $prefix.'options.*.label' => ['required_with:'.$prefix.'options', 'string', 'max:5', 'distinct:ignore_case'],
            $prefix.'options.*.text' => ['required_with:'.$prefix.'options', 'string', 'max:1000'],
            $prefix.'options.*.is_correct' => ['required_with:'.$prefix.'options', 'boolean'],
            $prefix.'difficulty_level' => [$presence, 'string', 'max:50'],
            $prefix.'reward' => [$presence, 'integer', 'min:0'],
            $prefix.'required_to_pass_block' => [$presence, 'boolean'],
            $prefix.'answer_explanation' => ['nullable', 'string', 'max:5000'],
            $prefix.'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function validateCorrectOption(Validator $validator, array $question, string $field): void
    {
        if (! array_key_exists('options', $question) || ! is_array($question['options'])) {
            return;
        }

        $hasCorrectOption = collect($question['options'])
            ->contains(fn (mixed $option): bool => is_array($option) && filter_var($option['is_correct'] ?? false, FILTER_VALIDATE_BOOL));

        if (! $hasCorrectOption) {
            $validator->errors()->add($field, 'At least one option must be marked as correct.');
        }
    }
}
