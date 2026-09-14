<?php

namespace App\Http\Requests\Teacher;

use App\Helpers\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherRequest extends FormRequest
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
        $userId = $this->route('teacher')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at')],
            // digits:10 — exactly 10 numeric characters, so it also rejects a
            // decimal point or any other non-digit character.
            'phone_no' => ['sometimes', 'required', 'digits:10', Rule::unique('users', 'phone_no')->ignore($userId)->whereNull('deleted_at')],
            'password' => ['sometimes', 'nullable', 'string', 'confirmed', PasswordPolicy::rule()],
            // ignore($userId, 'user_id') — emp_code lives on teacher_details,
            // keyed by user_id, not by its own id (see StoreTeacherRequest
            // for the matching create-time rule).
            'emp_code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('teacher_details', 'emp_code')->ignore($userId, 'user_id')->whereNull('deleted_at')],
            'department_id' => ['sometimes', 'required', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'designation' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            // Set via the Teachers list's own "Face Scan Applicable" row
            // action (see TeachersView.vue) — never the main edit form.
            'face_scan_applicable' => ['sometimes', 'boolean'],
        ];
    }
}
