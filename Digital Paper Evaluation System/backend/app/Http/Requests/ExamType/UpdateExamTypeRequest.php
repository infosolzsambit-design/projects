<?php

namespace App\Http\Requests\ExamType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExamTypeRequest extends FormRequest
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
        $examTypeId = $this->route('exam_type')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('exam_types', 'name')->ignore($examTypeId)->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
