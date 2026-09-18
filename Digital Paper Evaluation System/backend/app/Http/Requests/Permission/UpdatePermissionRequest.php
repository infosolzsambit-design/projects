<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
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
        $permission = $this->route('permission');
        // Falls back to the permission's current group if this request
        // only changed the sub-group (or vice versa) — same effective
        // group used either way for the "sub-group belongs to group" check.
        $groupId = $this->input('permission_group_id', $permission?->permission_group_id);

        return [
            'permission_group_id' => ['sometimes', 'required', 'integer', Rule::exists('permission_groups', 'id')->whereNull('deleted_at')],
            'permission_sub_group_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('permission_sub_groups', 'id')->whereNull('deleted_at')->where('permission_group_id', $groupId),
            ],
            // See StorePermissionRequest for why this regex exists
            // alongside the frontend's own live auto-slugify.
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('permissions', 'name')->ignore($permission?->id)->whereNull('deleted_at')],
        ];
    }
}
