<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
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
            // Scoped to non-deleted rows — a soft-deleted department's name
            // is free to be reused (see courses.code for the same fix).
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:50'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
