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
            'department' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('programs', 'code')->ignore($programId)->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
            // Optional to resend on update, but if included a program still
            // can't be left with zero mapped courses.
            'course_ids' => ['sometimes', 'array', 'min:1'],
            'course_ids.*' => ['distinct', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
        ];
    }
}
