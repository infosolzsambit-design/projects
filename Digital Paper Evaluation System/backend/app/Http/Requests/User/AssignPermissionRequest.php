<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class AssignPermissionRequest extends FormRequest
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
            'permission_id' => ['required', 'integer', 'exists:permissions,id'],
        ];
    }
}
