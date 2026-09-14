<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
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
            // No DB-unique constraint (project-wide rule — see
            // emp_code/packet_code elsewhere) — enforced here instead so a
            // soft-deleted student's old roll number can be reused.
            'roll_no' => ['required', 'string', 'max:50', Rule::unique('students', 'roll_no')->whereNull('deleted_at')],
            'semester' => ['required', 'integer', 'min:1'],
            // Must match an existing (non-deleted) program's name exactly —
            // kept as a name rather than program_id since that's how it was
            // asked for, but this stops it drifting from the programs table.
            'program_name' => ['nullable', 'string', 'max:255', Rule::exists('programs', 'name')->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
