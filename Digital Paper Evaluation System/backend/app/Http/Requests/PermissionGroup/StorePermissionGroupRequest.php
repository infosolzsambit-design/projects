<?php

namespace App\Http\Requests\PermissionGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePermissionGroupRequest extends FormRequest
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
            // Scoped to non-deleted rows — a soft-deleted group's name is
            // free to be reused (see departments.name for the same fix).
            'name' => ['required', 'string', 'max:255', Rule::unique('permission_groups', 'name')->whereNull('deleted_at')],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
