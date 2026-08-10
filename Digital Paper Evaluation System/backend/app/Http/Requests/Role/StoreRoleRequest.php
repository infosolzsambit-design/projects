<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
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
            // Scoped to non-deleted rows — a soft-deleted role's name is
            // free to be reused (see courses.code for the same fix).
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->whereNull('deleted_at')],
        ];
    }
}
