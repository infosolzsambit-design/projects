<?php

namespace App\Http\Requests\PermissionGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionGroupRequest extends FormRequest
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
        $groupId = $this->route('permission_group')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('permission_groups', 'name')->ignore($groupId)->whereNull('deleted_at')],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
