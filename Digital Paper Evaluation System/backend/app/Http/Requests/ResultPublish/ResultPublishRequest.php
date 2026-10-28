<?php

namespace App\Http\Requests\ResultPublish;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /result-publish (JSON or form body) — every id is the main
 * database's own id (our inserted_id / "Ref Id"), never our auto-increment
 * id. A record the main database has since deleted still matches, so
 * results already evaluated for it can still be fetched.
 */
class ResultPublishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_term_id' => ['required', 'integer', Rule::exists('exam_terms', 'inserted_id')],
            'exam_type_id' => ['required', 'integer', Rule::exists('exam_types', 'inserted_id')],
            'exam_sub_type_id' => ['required', 'integer', Rule::exists('exam_sub_types', 'inserted_id')],
            'program_id' => ['required', 'integer', Rule::exists('programs', 'inserted_id')],
            'semester' => ['required', 'integer', 'min:1', 'max:20'],
            // Optional narrowing: one department, one course (their Ref Ids) and / or one student.
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'inserted_id')],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'inserted_id')],
            'registration_no' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'exam_term_id.required' => 'The exam term Ref Id is required.',
            'exam_type_id.required' => 'The exam type Ref Id is required.',
            'exam_sub_type_id.required' => 'The exam sub type Ref Id is required.',
            'program_id.required' => 'The program Ref Id is required.',
            'semester.required' => 'The semester is required.',
            'exam_term_id.exists' => 'No exam term has this Ref Id.',
            'exam_type_id.exists' => 'No exam type has this Ref Id.',
            'exam_sub_type_id.exists' => 'No exam sub type has this Ref Id.',
            'program_id.exists' => 'No program has this Ref Id.',
            'department_id.exists' => 'No department has this Ref Id.',
            'course_id.exists' => 'No course has this Ref Id.',
        ];
    }
}
