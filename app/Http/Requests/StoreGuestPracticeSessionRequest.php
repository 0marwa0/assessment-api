<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGuestPracticeSessionRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            'duration_seconds' => ['required', 'integer', 'min:0'],
            'transcript' => ['required', 'array'],
            'transcript.*.role' => ['required', 'string', 'max:50'],
            'transcript.*.text' => ['required', 'string'],
        ];
    }
}
