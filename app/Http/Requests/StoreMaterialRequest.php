<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialRequest extends FormRequest
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
            // MG10: a one-page material sends `content`; its summary can then be
            // left empty (derived from the first paragraph) and stages omitted.
            'summary' => ['required_without_all:content,attachments', 'nullable', 'string', 'max:5000'],
            'content' => ['nullable', 'string', 'max:500000'],
            'attachments' => ['sometimes', 'nullable', 'array', 'max:50'],
            'attachments.*.name' => ['required', 'string', 'max:255'],
            'attachments.*.url' => ['required', 'string', 'max:2048'],
            'attachments.*.type' => ['sometimes', Rule::in(['pdf', 'video', 'link', 'file'])],
            'category' => ['required', 'string', 'max:100'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:50'],
            'status' => ['sometimes', 'string', 'in:draft,published,archived'],
            'difficulty_level' => ['nullable', 'string', 'max:50'],
            'estimated_time' => ['nullable', 'string', 'max:50'],
            'xp' => ['nullable', 'integer', 'min:0'],
            'stages' => ['required_without_all:content,attachments', 'array'],
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
