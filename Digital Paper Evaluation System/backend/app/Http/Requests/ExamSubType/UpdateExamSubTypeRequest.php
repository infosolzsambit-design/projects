<?php

namespace App\Http\Requests\ExamSubType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExamSubTypeRequest extends FormRequest
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
        $examSubType = $this->route('exam_sub_type');

        return [
            'exam_term_id' => ['sometimes', 'required', 'integer', Rule::exists('exam_terms', 'id')->whereNull('deleted_at')],
            'exam_type_id' => ['sometimes', 'required', 'integer', Rule::exists('exam_types', 'id')->whereNull('deleted_at')],
            // Same scope as StoreExamSubTypeRequest — falls back to the saved
            // term/type when only the name (or only one of them) is sent.
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('exam_sub_types', 'name')
                ->ignore($examSubType?->id)
                ->where('exam_term_id', $this->input('exam_term_id', $examSubType?->exam_term_id))
                ->where('exam_type_id', $this->input('exam_type_id', $examSubType?->exam_type_id))
                ->whereNull('deleted_at')],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'This exam term and exam type already have a sub type with this name.',
        ];
    }
}
