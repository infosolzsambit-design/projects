<?php

namespace App\Http\Requests\ExamSubType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamSubTypeRequest extends FormRequest
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
            'exam_term_id' => ['required', 'integer', Rule::exists('exam_terms', 'id')->whereNull('deleted_at')],
            'exam_type_id' => ['required', 'integer', Rule::exists('exam_types', 'id')->whereNull('deleted_at')],
            // Unique within the same exam term + exam type, among non-deleted
            // rows — a soft-deleted sub type's name is free to be reused.
            'name' => ['required', 'string', 'max:255', Rule::unique('exam_sub_types', 'name')
                ->where('exam_term_id', $this->input('exam_term_id'))
                ->where('exam_type_id', $this->input('exam_type_id'))
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
