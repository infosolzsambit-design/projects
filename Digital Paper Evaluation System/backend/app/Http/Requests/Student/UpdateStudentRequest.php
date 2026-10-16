<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'registration_no' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('students', 'registration_no')->ignore($this->route('student')?->id)->whereNull('deleted_at')],
            'batch' => ['sometimes', 'nullable', 'string', 'max:20'],
            'program_name' => ['nullable', 'string', 'max:255', Rule::exists('programs', 'name')->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
