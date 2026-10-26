<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoadmapAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Assign to one broker (user_id), several (user_ids) or every broker
     * (all_brokers). Brokers who already have it are skipped, not errors.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'roadmap_id' => ['required', 'integer', 'exists:roadmaps,id'],
            'user_id' => ['required_without_all:user_ids,all_brokers', 'integer', 'exists:users,id'],
            'user_ids' => ['required_without_all:user_id,all_brokers', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'all_brokers' => ['sometimes', 'boolean'],
            'due_at' => ['nullable', 'date'],
        ];
    }
}
