<?php

namespace App\Http\Requests\AnswerSheet;

use App\Models\QuestionPaper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Phase 1 of an answer-sheet upload — just the packet's own fields
 * (question paper/course/semester/program/packet code), no rows or PDFs
 * yet. AnswerSheetUploadView.vue's Submit calls this first to get a
 * mapping id, then streams the (already client-side-validated) rows/PDFs
 * to it in small batches via StoreAnswerSheetRowsRequest — see that
 * request's own docblock for why a batch upload is necessary at this
 * feature's scale (3,000-5,000 rows/PDFs per submission) rather than one
 * request carrying everything.
 */
class StoreQuestionAnswerSheetMappingRequest extends FormRequest
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
            'question_paper_id' => ['required', 'integer', Rule::exists('question_papers', 'id')->whereNull('deleted_at')],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'exam_term_id' => ['required', 'integer', Rule::exists('exam_terms', 'id')->whereNull('deleted_at')],
            'exam_type_id' => ['required', 'integer', Rule::exists('exam_types', 'id')->whereNull('deleted_at')],
            'program_name' => ['required', 'string', 'max:255', Rule::exists('programs', 'name')->whereNull('deleted_at')],
            'packet_code' => ['required', 'string', 'max:255'],
            // One of the chosen question paper's departments (checked in after()).
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * The department must be one the question paper is tagged with — the
     * upload page only offers those, this enforces it.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['question_paper_id', 'department_id'])) {
                    return;
                }
                $paper = QuestionPaper::find($this->integer('question_paper_id'));
                if (! $paper?->departments()->whereKey($this->integer('department_id'))->exists()) {
                    $validator->errors()->add('department_id', 'Select one of the departments this question paper is tagged with.');
                }
            },
        ];
    }
}
