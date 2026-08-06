<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialRequest extends FormRequest
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
            'summary' => ['required', 'string', 'max:5000'],
            'category' => ['required', 'string', 'max:100'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:50'],
            'status' => ['sometimes', 'string', 'in:draft,published,archived'],
            'difficulty_level' => ['required', 'string', 'max:50'],
            'estimated_time' => ['required', 'string', 'max:50'],
            'xp' => ['required', 'integer', 'min:0'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.id' => ['required', 'string', 'max:255'],
            'stages.*.title' => ['required', 'string', 'max:255'],
            'stages.*.status' => ['required', 'string', 'max:50'],
            'stages.*.body' => ['nullable', 'string'],
            'created_by' => ['nullable', 'string', 'max:255'],
        ];
    }
}
