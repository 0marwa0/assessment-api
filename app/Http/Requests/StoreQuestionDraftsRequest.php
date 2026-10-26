<?php

namespace App\Http\Requests;

use App\Services\QuestionDraftGenerator;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionDraftsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // manager-only via the route group
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'material_ids' => ['required', 'array', 'min:1', 'max:5'],
            'material_ids.*' => ['integer', 'distinct', 'exists:materials,id'],
            'count' => ['required', 'integer', 'min:1', 'max:20'],
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['string', 'distinct', 'in:'.implode(',', QuestionDraftGenerator::TYPES)],
            'difficulty' => ['required', 'string', 'in:'.implode(',', QuestionDraftGenerator::DIFFICULTIES)],
        ];
    }
}
