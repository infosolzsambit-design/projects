<?php

namespace App\Http\Requests\Course;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseRequest extends FormRequest
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
            // Scoped to non-deleted rows — a soft-deleted course's code is
            // free to be reused (see the migration dropping the plain DB
            // unique constraint, which didn't know about deleted_at).
            'code' => ['required', 'string', 'max:50', Rule::unique('courses', 'code')->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
