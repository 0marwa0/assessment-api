<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoadmapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', 'in:draft,published'],
            'source' => ['sometimes', 'string', 'in:manual,assessment_generated'],
            'nodes' => ['required', 'array', 'min:1'],
            'nodes.*.position' => ['sometimes', 'integer', 'min:0'],
            'nodes.*.type' => ['required', 'string', 'in:material,simulation,assessment'],
            'nodes.*.material_id' => ['nullable', 'required_if:nodes.*.type,material', 'integer', 'exists:materials,id'],
            'nodes.*.simulation_id' => ['nullable', 'required_if:nodes.*.type,simulation', 'integer', 'exists:simulations,id'],
            'nodes.*.assessment_id' => ['nullable', 'required_if:nodes.*.type,assessment', 'integer', 'exists:assessments,id'],
            // MG8: pass score to unlock the next step (simulation steps only; null = any finished call).
            'nodes.*.min_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
