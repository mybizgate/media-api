<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /media/{id}: re-point an existing media item at a business record
 * without re-uploading. `entity_id` must be present; an empty value clears it.
 */
class AssignMediaRequest extends FormRequest
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
            'entity_id' => ['present', 'nullable', 'string', 'regex:'.config('media.entity_id.pattern')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entity_id.present' => 'The entity_id field is required (send an empty value to clear it).',
            'entity_id.regex' => 'The entity id may only contain letters, digits, ".", "-" and "_" (max 100).',
        ];
    }
}
