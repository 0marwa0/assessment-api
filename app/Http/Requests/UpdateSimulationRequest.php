<?php

namespace App\Http\Requests;

use App\Rules\ScorecardWeightsTotal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSimulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'level' => ['sometimes', 'string', 'max:50'],
            'time_required' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', 'string', 'max:50'],
            'opening_message' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'persona' => ['sometimes', 'string', 'max:5000'],
            'persona_name' => ['sometimes', 'string', 'max:255'],
            'persona_gender' => ['sometimes', Rule::in(['male', 'female'])],
            'persona_tone' => ['sometimes', Rule::in(['nice', 'less_rude', 'rude', 'sassy'])],
            'scenario_brief' => ['sometimes', 'string', 'max:5000'],
            'language' => ['sometimes', 'string', 'max:10'],
            // A/B experiment: which realtime voice stack runs the roleplay.
            'voice_engine' => ['sometimes', Rule::in(['elevenlabs', 'openai'])],
            'content_type' => ['sometimes', Rule::in(['scenario', 'project_launch'])],
            'project_subtitle' => ['nullable', 'string', 'max:255'],
            'launch_tag' => ['nullable', 'string', 'max:50'],
            'xp' => ['nullable', 'integer', 'min:0'],
            'objections' => ['nullable', 'array', 'max:20'],
            'objections.*' => ['string', 'max:500'],
            'hidden_motivation' => ['nullable', 'string', 'max:5000'],
            'win_criteria' => ['nullable', 'string', 'max:5000'],
            'loss_criteria' => ['nullable', 'string', 'max:5000'],
            // R3: weighted scorecard — names required, weights 1–100, total 100.
            'scorecard' => ['nullable', 'array', 'min:1', 'max:10', new ScorecardWeightsTotal],
            'scorecard.*.name' => ['required', 'string', 'max:120'],
            'scorecard.*.weight' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
