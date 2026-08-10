<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by both "sync permissions directly to a user" and
 * "sync permissions to a role" — identical payload shape.
 */
class SyncPermissionsRequest extends FormRequest
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
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ];
    }
}
