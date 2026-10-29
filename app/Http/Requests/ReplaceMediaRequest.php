<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceMediaRequest extends FormRequest
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
            'file' => ['required', 'file'],
            'visibility' => ['nullable', 'string', 'in:'.implode(',', config('media.visibility.allowed'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'A replacement file is required.',
            'file.file' => 'The file failed to upload. Check the server upload size limits.',
        ];
    }
}
