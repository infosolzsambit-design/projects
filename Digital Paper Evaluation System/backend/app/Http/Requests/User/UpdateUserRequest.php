<?php

namespace App\Http\Requests\User;

use App\Helpers\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $userId = $this->route('user')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('users', 'username')->ignore($userId)],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone_no' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone_no')->ignore($userId)->whereNull('deleted_at')],
            'password' => ['sometimes', 'nullable', 'string', 'confirmed', PasswordPolicy::rule()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
