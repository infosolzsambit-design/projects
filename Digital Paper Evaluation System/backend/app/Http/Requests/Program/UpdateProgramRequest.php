<?php

namespace App\Http\Requests\Program;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'label' => ['sometimes', 'required', 'string', 'max:20'],
            'department_id' => ['sometimes', 'required', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            // Unique only together with name + label — see after().
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'status' => ['sometimes', 'boolean'],
            // Optional to resend on update — an empty array is allowed too,
            // so a program can be edited down to zero mapped courses.
            'course_ids' => ['sometimes', 'array'],
            'course_ids.*' => ['distinct', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * Same combined name + code + label check as StoreProgramRequest,
     * using the program's current value for any of the three not sent,
     * and ignoring the program itself.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $program = $this->route('program');
                if (! $program instanceof Program || ! $this->hasAny(['name', 'code', 'label'])
                    || $validator->errors()->hasAny(['name', 'code', 'label'])) {
                    return;
                }
                $name = (string) $this->input('name', $program->name);
                $code = (string) $this->input('code', $program->code);
                $label = $this->has('label') ? (string) $this->input('label') : $program->label;

                if (Program::hasDuplicate($name, $code, $label, $program->id)) {
                    foreach (['name', 'code', 'label'] as $field) {
                        $validator->errors()->add($field, Program::DUPLICATE_MESSAGE);
                    }
                }
            },
        ];
    }
}
