<?php

namespace App\Http\Requests\AnswerSheet;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'program_name' => ['required', 'string', 'max:255', Rule::exists('programs', 'name')->whereNull('deleted_at')],
            'packet_code' => ['required', 'string', 'max:255'],
        ];
    }
}
