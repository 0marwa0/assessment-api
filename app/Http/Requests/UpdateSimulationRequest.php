<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSimulationRequest extends FormRequest
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
            'description' => ['sometimes', 'string', 'max:5000'],
            'level' => ['sometimes', 'string', 'max:50'],
            'time_required' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', 'string', 'max:50'],
            'opening_message' => ['sometimes', 'string', 'max:5000'],
        ];
    }
}
