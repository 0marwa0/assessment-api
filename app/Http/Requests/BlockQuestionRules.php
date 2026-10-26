<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

trait BlockQuestionRules
{
    use QuestionRules;

    /**
     * @return array<string, array<int, string>>
     */
    protected function blockRules(string $prefix = '', bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';
        $questionsPresence = $required ? 'required' : 'sometimes';

        return array_merge([
            $prefix.'title' => [$presence, 'string', 'max:255'],
            $prefix.'position' => ['sometimes', 'integer', 'min:0'],
            $prefix.'enabled' => ['sometimes', 'boolean'],
            // R6 pools: show this many random questions from the block (null = all).
            $prefix.'pool_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            $prefix.'questions' => [$questionsPresence, 'array', 'min:1'],
        ], $this->questionRules($prefix.'questions.*.'));
    }

    protected function validateBlockCorrectOptions(Validator $validator, array $blocks, string $field = 'blocks'): void
    {
        foreach ($blocks as $blockIndex => $block) {
            if (! is_array($block)) {
                continue;
            }

            foreach ($block['questions'] ?? [] as $questionIndex => $question) {
                if (is_array($question)) {
                    $optionsField = "$field.$blockIndex.questions.$questionIndex.options";
                    $this->validateCorrectOption($validator, $question, $optionsField);
                    $this->validateDistinctOptionLabels($validator, $question, $optionsField);
                }
            }
        }
    }
}
