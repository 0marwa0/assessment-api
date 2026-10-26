<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUploadRequest extends FormRequest
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
        // MG10: images for the material editor (jpg/png/webp, up to 5 MB).
        [$mimes, $max] = match ($this->input('type')) {
            'pdf' => ['mimes:pdf', 'max:20480'],
            'image' => ['mimes:jpg,jpeg,png,webp', 'max:5120'],
            default => ['mimes:mp4,mov,webm,ogg', 'max:512000'],
        };

        return [
            'type' => ['required', Rule::in(['video', 'pdf', 'image'])],
            'file' => ['required', 'file', $mimes, $max],
        ];
    }
}
