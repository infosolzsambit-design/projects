<?php

namespace App\Http\Requests\PermissionSubGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePermissionSubGroupRequest extends FormRequest
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
            'permission_group_id' => ['required', 'integer', Rule::exists('permission_groups', 'id')->whereNull('deleted_at')],
            // Scoped to non-deleted rows — a soft-deleted sub-group's name
            // is free to be reused (see permission_groups.name for the
            // same fix).
            'name' => ['required', 'string', 'max:255', Rule::unique('permission_sub_groups', 'name')->whereNull('deleted_at')],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
