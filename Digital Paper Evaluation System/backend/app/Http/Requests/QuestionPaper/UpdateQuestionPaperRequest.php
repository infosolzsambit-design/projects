<?php

namespace App\Http\Requests\QuestionPaper;

use App\Http\Requests\QuestionPaper\Concerns\ValidatesQuestionPaperNodes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Editing an *existing* saved paper's structure later (the "Edit Setup"
 * list action) — the exam metadata plus the paper's whole structure tree,
 * saved together as one submission. `groups` is a list of top-level
 * QuestionPaperNode trees (see that model's docblock for what
 * `mode`/`children` mean) and fully replaces whatever structure already
 * exists (see QuestionPaperController::update()) rather than patching it.
 *
 * The recursive structure validation is shared with StoreQuestionPaperRequest
 * (a brand new paper is now created with its structure already attached, in
 * one request — see that class) via ValidatesQuestionPaperNodes, so both
 * report errors at identical dotted paths.
 */
class UpdateQuestionPaperRequest extends FormRequest
{
    use ValidatesQuestionPaperNodes;

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
            'exam_year' => ['required', 'integer', 'digits:4', 'min:2000'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'exam_term_id' => ['required', 'integer', Rule::exists('exam_terms', 'id')->whereNull('deleted_at')],
            'full_marks' => ['nullable', 'integer', 'min:1'],
            'time_allotted' => ['nullable', 'string', 'max:100'],

            'groups' => ['required', 'array', 'min:1'],
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateGroupsStructure($v));
    }
}
