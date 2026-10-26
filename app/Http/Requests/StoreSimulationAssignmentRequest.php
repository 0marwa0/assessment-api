<?php

namespace App\Http\Requests;

use App\Models\Simulation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSimulationAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same targets as assessments (one broker, several, or all). Only
     * published simulations can be assigned: brokers can't open drafts.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'simulation_id' => ['required', 'integer', Rule::exists('simulations', 'id')->where('status', Simulation::STATUS_PUBLISHED)],
            'user_id' => ['required_without_all:user_ids,all_brokers', 'integer', 'exists:users,id'],
            'user_ids' => ['required_without_all:user_id,all_brokers', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'all_brokers' => ['sometimes', 'boolean'],
            'due_at' => ['nullable', 'date'],
            'min_score' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['simulation_id.exists' => 'Publish this simulation before assigning it.'];
    }
}
