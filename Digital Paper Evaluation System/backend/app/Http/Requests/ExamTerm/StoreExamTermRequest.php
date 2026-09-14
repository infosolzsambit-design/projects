<?php

namespace App\Http\Requests\ExamTerm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamTermRequest extends FormRequest
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
            // Scoped to non-deleted rows — a soft-deleted exam term's name
            // is free to be reused (see departments.name for the same fix).
            'name' => ['required', 'string', 'max:255', Rule::unique('exam_terms', 'name')->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
