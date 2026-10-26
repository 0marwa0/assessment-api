<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePracticeSessionRequest extends FormRequest
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
            'simulation_id' => ['required', 'integer', 'exists:simulations,id'],
            'simulation_title' => ['required', 'string', 'max:255'],
            'simulation_level' => ['required', 'string', 'max:50'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            'duration_seconds' => ['required', 'integer', 'min:0'],
            'transcript' => ['required', 'array'],
            'transcript.*.role' => ['required', 'string', 'max:50'],
            'transcript.*.text' => ['required', 'string'],
        ];
    }
}
