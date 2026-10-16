<?php

namespace App\Http\Requests\Teacher;

use App\Helpers\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * name/email/phone_no/password land on users (see TeacherController::store)
     * — username is generated server-side, not collected here. department_id/
     * designation land on teacher_details — department_id is resolved to the
     * matching Department's name server-side, and both are stored (see
     * TeacherController::store()).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            // digits:10 — exactly 10 numeric characters, so it also rejects a
            // decimal point or any other non-digit character. Not unique —
            // login is by email only (see AuthController::login).
            'phone_no' => ['required', 'digits:10'],
            // Optional — a teacher can be created with no password set at
            // all (see TeacherController::store()) and use "Forgot
            // password?" the first time they sign in. If one IS given here,
            // it still has to meet the same policy as everywhere else.
            'password' => ['nullable', 'string', 'confirmed', PasswordPolicy::rule()],
            // Scoped to non-deleted rows — a soft-deleted teacher's emp
            // code is free to be reused (same fix as courses.code/
            // programs.code/departments.name).
            'emp_code' => ['required', 'string', 'max:50', Rule::unique('teacher_details', 'emp_code')->whereNull('deleted_at')],
            // A teacher can be in several departments (teacher_department_mappings).
            'department_ids' => ['required', 'array', 'min:1'],
            'department_ids.*' => ['distinct', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'designation' => ['required', 'string', 'max:255'],
        ];
    }
}
