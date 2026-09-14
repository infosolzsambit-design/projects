<?php

namespace App\Http\Requests\ExamTerm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExamTermRequest extends FormRequest
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
        $examTermId = $this->route('exam_term')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('exam_terms', 'name')->ignore($examTermId)->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
