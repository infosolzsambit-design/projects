<?php

namespace App\Http\Requests\Program;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProgramRequest extends FormRequest
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
        $programId = $this->route('program')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'department_id' => ['sometimes', 'required', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('programs', 'code')->ignore($programId)->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
            // Optional to resend on update — an empty array is allowed too,
            // so a program can be edited down to zero mapped courses.
            'course_ids' => ['sometimes', 'array'],
            'course_ids.*' => ['distinct', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
        ];
    }
}
