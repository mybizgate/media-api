<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
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
            'module' => ['required', 'string', 'regex:'.config('media.module.pattern')],
            'entity_id' => ['nullable', 'string', 'regex:'.config('media.entity_id.pattern')],
            'visibility' => ['nullable', 'string', 'in:'.implode(',', config('media.visibility.allowed'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'A file is required.',
            'file.file' => 'The file failed to upload. Check the server upload size limits.',
            'module.regex' => 'The module may only contain a-z, 0-9, "-" and "_" (max 50).',
            'entity_id.regex' => 'The entity id may only contain letters, digits, ".", "-" and "_" (max 100).',
        ];
    }
}
