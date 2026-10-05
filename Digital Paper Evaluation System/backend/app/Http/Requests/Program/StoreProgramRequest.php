<?php

namespace App\Http\Requests\Program;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Program level, e.g. UG / PG.
            'label' => ['required', 'string', 'max:20'],
            // department_id is what's actually selected (a searchable
            // dropdown sourced from the Department master list); the
            // `department` name column is resolved from it server-side —
            // see ProgramController::store().
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            // Not unique on its own — name + code + label together must be
            // (see after() and Program::hasDuplicate()).
            'code' => ['required', 'string', 'max:50'],
            'status' => ['sometimes', 'boolean'],
            // Courses are optional — a program can be created without any
            // mapped yet and have them added later via update.
            'course_ids' => ['sometimes', 'array'],
            'course_ids.*' => ['distinct', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * Name + code + label must be unique together among non-deleted
     * programs. Flagged on all three fields so the form outlines each one.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['name', 'code', 'label'])) {
                    return;
                }
                if (Program::hasDuplicate((string) $this->input('name'), (string) $this->input('code'), (string) $this->input('label'))) {
                    foreach (['name', 'code', 'label'] as $field) {
                        $validator->errors()->add($field, Program::DUPLICATE_MESSAGE);
                    }
                }
            },
        ];
    }
}
