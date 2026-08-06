<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaterialRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'summary' => ['sometimes', 'string', 'max:5000'],
            'category' => ['sometimes', 'string', 'max:100'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:50'],
            'status' => ['sometimes', 'string', 'in:draft,published,archived'],
            'difficulty_level' => ['sometimes', 'string', 'max:50'],
            'estimated_time' => ['sometimes', 'string', 'max:50'],
            'xp' => ['sometimes', 'integer', 'min:0'],
            'stages' => ['sometimes', 'array', 'min:1'],
            'stages.*.id' => ['required_with:stages', 'string', 'max:255'],
            'stages.*.title' => ['required_with:stages', 'string', 'max:255'],
            'stages.*.status' => ['required_with:stages', 'string', 'max:50'],
            'stages.*.body' => ['nullable', 'string'],
            'created_by' => ['nullable', 'string', 'max:255'],
        ];
    }
}
