<?php

namespace App\Http\Requests\ExamType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamTypeRequest extends FormRequest
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
            // Scoped to non-deleted rows — a soft-deleted exam type's name
            // is free to be reused (see exam_terms.name for the same fix).
            'name' => ['required', 'string', 'max:255', Rule::unique('exam_types', 'name')->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
