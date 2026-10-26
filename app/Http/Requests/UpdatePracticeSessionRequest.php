<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePracticeSessionRequest extends FormRequest
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
            'score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'feedback' => ['sometimes', 'string', 'max:5000'],
        ];
    }
}
