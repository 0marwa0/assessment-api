<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSimulationTestMessageRequest extends FormRequest
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
            'role' => ['required', 'string', 'in:user,assistant,simulation,system'],
            'content' => ['required', 'string', 'max:10000'],
        ];
    }
}
