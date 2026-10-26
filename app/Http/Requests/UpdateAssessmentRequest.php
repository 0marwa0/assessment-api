<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAssessmentRequest extends FormRequest
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
            'description' => ['sometimes', 'string', 'max:5000'],
            'type' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'max:50'],
            'scoring_rules' => ['sometimes', 'array'],
            'scoring_rules.score_unit' => ['required_with:scoring_rules', 'string', 'in:percent'],
            'scoring_rules.junior_max_score' => ['required_with:scoring_rules', 'integer', 'min:0', 'max:100'],
            'scoring_rules.senior_min_score' => ['required_with:scoring_rules', 'integer', 'min:0', 'max:100', 'gte:scoring_rules.junior_max_score'],
            'scoring_rules.hard_gates_enabled' => ['required_with:scoring_rules', 'boolean'],
            // Added in R1; optional so older payloads still save (defaults: 70% / 15 min).
            'scoring_rules.pass_pct' => ['sometimes', 'integer', 'min:1', 'max:100'],
            // 0 = no time limit.
            'scoring_rules.time_limit_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            // MG3 (all optional; defaults live in AssessmentGrader::rules / the front end).
            'scoring_rules.block_gates' => ['sometimes', 'nullable', 'array'],
            'scoring_rules.block_gates.*' => ['integer', 'min:0', 'max:100'],
            'scoring_rules.pass_bonus_xp' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'scoring_rules.warn_last_minute' => ['sometimes', 'boolean'],
            'scoring_rules.shuffle_questions' => ['sometimes', 'boolean'],
            'scoring_rules.shuffle_answers' => ['sometimes', 'boolean'],
            'scoring_rules.allow_mark_for_review' => ['sometimes', 'boolean'],
            'scoring_rules.results_visibility' => ['sometimes', 'string', 'in:full,answers,score'],
            'scoring_rules.default_max_attempts' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'scoring_rules.certificate_enabled' => ['sometimes', 'boolean'],
            'usage_analytics' => ['sometimes', 'array'],
            'usage_analytics.times_used' => ['required_with:usage_analytics', 'integer', 'min:0'],
            'usage_analytics.completion_rate_percent' => ['required_with:usage_analytics', 'integer', 'min:0', 'max:100'],
            'usage_analytics.average_score_percent' => ['required_with:usage_analytics', 'integer', 'min:0', 'max:100'],
            'usage_analytics.completed_attempts' => ['required_with:usage_analytics', 'integer', 'min:0', 'lte:usage_analytics.started_attempts'],
            'usage_analytics.started_attempts' => ['required_with:usage_analytics', 'integer', 'min:0'],
            'usage_analytics.last_attempted_at' => ['required_with:usage_analytics', 'date'],
            'blocks' => ['sometimes', 'array', 'min:1'],
        ], $this->blockRules('blocks.*.'));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateBlockCorrectOptions($validator, $this->input('blocks', []));
        });
    }
}
