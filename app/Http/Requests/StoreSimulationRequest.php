<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSimulationRequest extends FormRequest
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
            'level' => ['required', 'string', 'max:50'],
            'time_required' => ['required', 'string', 'max:50'],
            'status' => ['required', 'string', 'max:50'],
            'opening_message' => ['required', 'string', 'max:5000'],
        ];
    }
}
