<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaterialRequest extends FormRequest
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
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // MG10: one-page body (sanitized on save) + attached files.
            'content' => ['sometimes', 'nullable', 'string', 'max:500000'],
            'attachments' => ['sometimes', 'nullable', 'array', 'max:50'],
            'attachments.*.name' => ['required', 'string', 'max:255'],
            'attachments.*.url' => ['required', 'string', 'max:2048'],
            'attachments.*.type' => ['sometimes', Rule::in(['pdf', 'video', 'link', 'file'])],
            'category' => ['sometimes', 'string', 'max:100'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:50'],
            'status' => ['sometimes', 'string', 'in:draft,published,archived'],
            'difficulty_level' => ['sometimes', 'nullable', 'string', 'max:50'],
            'estimated_time' => ['sometimes', 'nullable', 'string', 'max:50'],
            'xp' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'stages' => ['sometimes', 'array'],
            'stages.*.id' => ['required_with:stages', 'string', 'max:255'],
            'stages.*.title' => ['required_with:stages', 'string', 'max:255'],
            'stages.*.status' => ['required_with:stages', 'string', 'max:50'],
            'stages.*.type' => ['sometimes', Rule::in(['text', 'video', 'pdf', 'link'])],
            'stages.*.body' => ['nullable', 'string'],
            'stages.*.url' => ['nullable', 'string', 'max:2048'],
            'created_by' => ['nullable', 'string', 'max:255'],
        ];
    }
}
