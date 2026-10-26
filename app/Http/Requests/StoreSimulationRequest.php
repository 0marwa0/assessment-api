<?php

namespace App\Http\Requests;

use App\Rules\ScorecardWeightsTotal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSimulationRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'level' => ['required', 'string', 'max:50'],
            'time_required' => ['required', 'string', 'max:50'],
            'status' => ['required', 'string', 'max:50'],
            // Optional: what's on the client's mind. Blank is stored as ''.
            'opening_message' => ['nullable', 'string', 'max:5000'],
            'persona' => ['required', 'string', 'max:5000'],
            'persona_name' => ['required', 'string', 'max:255'],
            'persona_gender' => ['required', Rule::in(['male', 'female'])],
            'persona_tone' => ['required', Rule::in(['nice', 'less_rude', 'rude', 'sassy'])],
            'scenario_brief' => ['required', 'string', 'max:5000'],
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
