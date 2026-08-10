<?php

namespace App\Http\Requests\User;

use App\Helpers\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone_no' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
