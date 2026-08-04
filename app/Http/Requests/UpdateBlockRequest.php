<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBlockRequest extends FormRequest
{
    use BlockQuestionRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return array_merge([
            'title' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'questions' => ['sometimes', 'array', 'min:1'],
        ], $this->questionRules('questions.*.'));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('questions', []) as $index => $question) {
                if (is_array($question)) {
                    $this->validateCorrectOption($validator, $question, "questions.$index.options");
                }
            }
        });
    }
}
