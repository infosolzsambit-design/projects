<?php

namespace App\Http\Requests\PermissionSubGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionSubGroupRequest extends FormRequest
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
        $subGroupId = $this->route('permission_sub_group')?->id;

        return [
            'permission_group_id' => ['sometimes', 'required', 'integer', Rule::exists('permission_groups', 'id')->whereNull('deleted_at')],
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('permission_sub_groups', 'name')->ignore($subGroupId)->whereNull('deleted_at')],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
