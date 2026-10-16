<?php

namespace App\Http\Requests\Qr;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /qr/exam-students — Generate QR's search. Every id here is the main
 * database's own id (our inserted_id / "Ref Id"), never our own
 * auto-increment id — it's passed to DataApi/examStudent as is, after
 * checking it belongs to a synced record the main database hasn't deleted
 * (deleted_from_main_db = 0, i.e. the API's delete_status 1).
 */
class ExamStudentSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_term_id' => ['required', 'integer', Rule::exists('exam_terms', 'inserted_id')->whereNull('deleted_at')->where('deleted_from_main_db', false)],
            'exam_type_id' => ['required', 'integer', Rule::exists('exam_types', 'inserted_id')->whereNull('deleted_at')->where('deleted_from_main_db', false)],
            // The exam sub type's Ref Id — the API answers 400 without it.
            'enroll_session_id' => ['required', 'integer', Rule::exists('exam_sub_types', 'inserted_id')->whereNull('deleted_at')->where('deleted_from_main_db', false)],
            'program_id' => ['required', 'integer', Rule::exists('programs', 'inserted_id')->whereNull('deleted_at')->where('deleted_from_main_db', false)],
            'course_id' => ['bail', 'required', 'integer', Rule::exists('courses', 'inserted_id')->whereNull('deleted_at')->where('deleted_from_main_db', false),
                // Only a course mapped to the chosen program.
                function (string $attribute, mixed $value, \Closure $fail) {
                    $mapped = Course::where('inserted_id', $value)
                        ->whereHas('programs', fn ($q) => $q->where('programs.inserted_id', $this->input('program_id')))
                        ->exists();
                    if (! $mapped) {
                        $fail('This course is not mapped to the selected program.');
                    }
                }],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'inserted_id')->whereNull('deleted_at')->where('deleted_from_main_db', false)],
            'semester' => ['required', 'integer', 'min:1', 'max:20'],
            'exam_year' => ['nullable', 'string', 'max:20'],
            'batch' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'exam_term_id.required' => 'Select an exam term.',
            'exam_type_id.required' => 'Select an exam type.',
            'exam_term_id.exists' => 'Select a synced exam term.',
            'exam_type_id.exists' => 'Select a synced exam type.',
            'enroll_session_id.required' => 'Select an exam sub type.',
            'enroll_session_id.exists' => 'Select a synced exam sub type.',
            'program_id.required' => 'Select a program.',
            'course_id.required' => 'Select a course.',
            'semester.required' => 'Select a semester.',
            'program_id.exists' => 'Select a synced program.',
            'course_id.exists' => 'Select a synced course.',
            'department_id.exists' => 'Select a synced department.',
        ];
    }
}
