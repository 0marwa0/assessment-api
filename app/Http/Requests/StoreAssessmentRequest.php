<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAssessmentRequest extends FormRequest
{
    use QuestionRules;

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
            'type' => ['required', 'string', 'max:100'],
            'status' => ['required', 'string', 'max:50'],
            'questions' => ['required', 'array', 'min:1'],
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
