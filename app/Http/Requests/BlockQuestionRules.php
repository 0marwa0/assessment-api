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
                    $this->validateCorrectOption($validator, $question, "$field.$blockIndex.questions.$questionIndex.options");
                }
            }
        }
    }
}
