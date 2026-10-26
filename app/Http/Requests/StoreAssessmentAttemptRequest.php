<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The learner sends only their answers; the server grades them
     * (see App\Services\AssessmentGrader).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'assessment_id' => ['required', 'integer', 'exists:assessments,id'],
            'assessment_assignment_id' => ['nullable', 'integer', 'exists:assessment_assignments,id'],
            // question id => the answer; unanswered questions may be omitted or null.
            // Shapes (R6): option index, list of indexes (multi-select), list of item
            // keys (put in order), {item key: match key} (match pairs), or text (short answer).
            'answers' => ['present', 'array', 'max:500'],
            'answers.*' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::validAnswer($value)) {
                    $fail('That answer is not in a format the assessment accepts.');
                }
            }],
            // R6 pools: the draw made by POST /assessments/{id}/start.
            'assessment_start_id' => ['nullable', 'integer'],
            'elapsed_seconds' => ['required', 'integer', 'min:0'],
            // question id => the learner's optional note on that question.
            'notes' => ['sometimes', 'array'],
            'notes.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private static function validAnswer(mixed $value): bool
    {
        if ($value === null || (is_int($value) && $value >= 0)) {
            return true;
        }
        if (is_string($value)) {
            return mb_strlen($value) <= 5000;
        }
        if (! is_array($value) || count($value) > 26) {
            return false;
        }
        if (array_is_list($value)) {
            // Option indexes (multi-select) or item keys (put in order).
            return collect($value)->every(fn ($v): bool => (is_int($v) && $v >= 0) || (is_string($v) && strlen($v) <= 64));
        }

        // Match pairs: item key => match key.
        return collect($value)->every(fn ($v, $k): bool => (is_int($k) || strlen($k) <= 64) && ($v === null || (is_string($v) && strlen($v) <= 64)));
    }
}
