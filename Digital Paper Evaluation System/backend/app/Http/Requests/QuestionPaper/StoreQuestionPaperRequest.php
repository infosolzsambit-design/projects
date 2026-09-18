<?php

namespace App\Http\Requests\QuestionPaper;

use App\Http\Requests\QuestionPaper\Concerns\ValidatesQuestionPaperNodes;
use App\Models\QuestionPaper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The whole setup flow is one request: exam metadata, the PDF, and the
 * full structure tree arrive together, and the paper is created already
 * "ready" — see QuestionPaperController::store(). Nothing is written to
 * the database for a paper still being set up in the browser (see
 * QuestionPaperStructureBuilder.vue); only a complete, valid submission
 * ever reaches this far.
 *
 * `groups` arrives as a JSON string (multipart/form-data can't carry a
 * deeply nested structure as a plain field) — see prepareForValidation().
 * The recursive structure validation itself is shared with
 * UpdateQuestionPaperRequest (used when editing an already-saved paper's
 * structure later) via ValidatesQuestionPaperNodes, so both report errors
 * at identical dotted paths.
 */
class StoreQuestionPaperRequest extends FormRequest
{
    use ValidatesQuestionPaperNodes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('groups'))) {
            $decoded = json_decode($this->input('groups'), true);
            $this->merge(['groups' => is_array($decoded) ? $decoded : []]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'exam_year' => ['required', 'integer', 'digits:4', 'min:2000'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'exam_term_id' => ['required', 'integer', Rule::exists('exam_terms', 'id')->whereNull('deleted_at')],
            'full_marks' => ['nullable', 'integer', 'min:1'],
            'time_allotted' => ['nullable', 'string', 'max:100'],
            // Scanned papers run bigger than typical uploads elsewhere in
            // this app — 20MB covers a generously photographed multi-page
            // paper (the sample in designed_files/question_paper.pdf is
            // under 1MB for 3 pages).
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],

            'groups' => ['required', 'array', 'min:1'],
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateNoDuplicatePaper($v));
        $validator->after(fn (Validator $v) => $this->validateGroupsStructure($v));
    }

    /**
     * One question paper per (exam_year, course_id, exam_term_id,
     * semester) combination — a second upload for the same exam is a
     * duplicate, not a new paper (see QuestionPaperSetupView.vue's Step 1
     * "Setup" button, which pre-checks this same combination before moving
     * on to Step 2, so this is a backstop that always applies, not just a
     * UX nicety). Only checked once the four fields are themselves valid —
     * an already-invalid exam_year/course_id/etc. gets its own error
     * without this piling on a second, confusing one.
     */
    private function validateNoDuplicatePaper(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['exam_year', 'course_id', 'exam_term_id', 'semester'])) {
            return;
        }

        $exists = QuestionPaper::where('exam_year', $this->input('exam_year'))
            ->where('course_id', $this->input('course_id'))
            ->where('exam_term_id', $this->input('exam_term_id'))
            ->where('semester', $this->input('semester'))
            ->exists();

        if ($exists) {
            $validator->errors()->add('exam_year', 'A question paper already exists for this Exam Year, Course, Exam Term and Semester combination.');
        }
    }
}
