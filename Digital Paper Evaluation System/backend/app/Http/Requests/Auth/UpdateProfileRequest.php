<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `name` lands on users; gender/location/about land on teacher_details
     * (see ProfileController::update()) — email, phone and role stay
     * admin-managed (see UserController/TeacherController), never editable
     * from here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'gender' => ['sometimes', 'nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'about' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
