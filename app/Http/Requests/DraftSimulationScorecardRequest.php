<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * R3: "Draft with AI" — the editor sends its current (possibly unsaved)
 * fields; a saved simulation's stored fields fill any gaps.
 */
class DraftSimulationScorecardRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:255'],
            'persona' => ['nullable', 'string', 'max:5000'],
            // Without a saved simulation the brief is the minimum the AI needs.
            'scenario_brief' => [$this->route('simulation') ? 'nullable' : 'required', 'string', 'max:5000'],
            'objections' => ['nullable', 'array', 'max:20'],
            'objections.*' => ['nullable', 'string', 'max:500'],
            'win_criteria' => ['nullable', 'string', 'max:5000'],
            'loss_criteria' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
