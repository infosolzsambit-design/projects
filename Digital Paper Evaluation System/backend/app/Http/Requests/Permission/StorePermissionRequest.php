<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePermissionRequest extends FormRequest
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
            'permission_sub_group_id' => [
                'required', 'integer',
                Rule::exists('permission_sub_groups', 'id')
                    ->whereNull('deleted_at')
                    ->where('permission_group_id', $this->input('permission_group_id')),
            ],
            // Scoped to non-deleted rows — a soft-deleted permission's name
            // is free to be reused (see courses.code for the same fix).
            // The frontend already auto-slugifies as you type (see
            // utils/slugify.js) — this regex is the server-side backstop
            // against anything bypassing that (direct API calls, etc).
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('permissions', 'name')->whereNull('deleted_at')],
        ];
    }
}
