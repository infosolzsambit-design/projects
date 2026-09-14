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
            // department_id is what's actually selected (a searchable
            // dropdown sourced from the Department master list); the
            // `department` name column is resolved from it server-side —
            // see ProgramController::store().
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            // Scoped to non-deleted rows — a soft-deleted program's code is
            // free to be reused (see courses.code for the same fix).
            'code' => ['required', 'string', 'max:50', Rule::unique('programs', 'code')->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
            // Courses are optional — a program can be created without any
            // mapped yet and have them added later via update.
            'course_ids' => ['sometimes', 'array'],
            'course_ids.*' => ['distinct', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
        ];
    }
}
