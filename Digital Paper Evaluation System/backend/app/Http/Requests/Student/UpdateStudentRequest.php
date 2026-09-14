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
            'roll_no' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('students', 'roll_no')->ignore($this->route('student')?->id)->whereNull('deleted_at')],
            'semester' => ['sometimes', 'required', 'integer', 'min:1'],
            'program_name' => ['nullable', 'string', 'max:255', Rule::exists('programs', 'name')->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
