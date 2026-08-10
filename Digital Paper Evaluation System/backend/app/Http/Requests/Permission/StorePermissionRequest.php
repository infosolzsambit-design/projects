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
            // Scoped to non-deleted rows — a soft-deleted permission's name
            // is free to be reused (see courses.code for the same fix).
            'name' => ['required', 'string', 'max:255', Rule::unique('permissions', 'name')->whereNull('deleted_at')],
        ];
    }
}
