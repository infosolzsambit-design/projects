<?php

namespace App\Http\Requests\Program;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:255'],
            // Scoped to non-deleted rows — a soft-deleted program's code is
            // free to be reused (see courses.code for the same fix).
            'code' => ['required', 'string', 'max:50', Rule::unique('programs', 'code')->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
            // A program must map to at least one course when created.
            'course_ids' => ['required', 'array', 'min:1'],
            'course_ids.*' => ['distinct', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
        ];
    }
}
