<?php

namespace App\Http\Requests\Qr;

use App\Models\Course;
use App\Models\Department;
use App\Models\ExamTerm;
use App\Models\QuestionPaper;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /qr/generate — Generate QR's modal. The search's params (Ref Ids,
 * same rules as ExamStudentSearchRequest) plus the modal's own fields:
 * department (Ref Id), exam year, batch, question paper (our own id — it's
 * our record) and packet code, all required.
 */
class GenerateQrCodesRequest extends ExamStudentSearchRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'department_id' => ['required', 'integer', Rule::exists('departments', 'inserted_id')->whereNull('deleted_at')->where('deleted_from_main_db', false)],
            'exam_year' => ['required', 'string', 'max:20'],
            'batch' => ['required', 'string', 'max:20'],
            'question_paper_id' => ['required', 'integer', Rule::exists('question_papers', 'id')->whereNull('deleted_at')->where('status', 'ready')],
            'packet_code' => ['required', 'string', 'max:255'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'department_id.required' => 'Select a department.',
            'question_paper_id.required' => 'Select a question paper.',
            'question_paper_id.exists' => 'Select a ready question paper.',
        ]);
    }

    /**
     * The question paper must be for this course, semester and exam term,
     * and tagged with the chosen department (QrCodeController::questionPapers()
     * only offers those; this enforces it).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['question_paper_id', 'course_id', 'semester', 'exam_term_id', 'department_id'])) {
                    return;
                }
                $matches = self::matchingQuestionPapers(
                    $this->integer('course_id'), $this->integer('semester'), $this->integer('exam_term_id'), $this->integer('department_id'),
                )->whereKey($this->integer('question_paper_id'))->exists();
                if (! $matches) {
                    $validator->errors()->add('question_paper_id', 'This question paper is not for the selected course, semester, exam term and department.');
                }
            },
        ];
    }

    /**
     * Ready question papers for a course + semester + exam term, tagged with
     * the department — all given as Ref Ids. A paper with no exam term set
     * is allowed (older papers).
     */
    public static function matchingQuestionPapers(int $courseRef, int $semester, int $examTermRef, int $departmentRef)
    {
        $courseId = Course::where('inserted_id', $courseRef)->value('id');
        $examTermId = ExamTerm::where('inserted_id', $examTermRef)->value('id');
        $departmentId = Department::where('inserted_id', $departmentRef)->value('id');

        return QuestionPaper::query()
            ->where('status', 'ready')
            ->where('course_id', $courseId ?? 0)
            ->where('semester', $semester)
            ->where(fn ($q) => $q->where('exam_term_id', $examTermId ?? 0)->orWhereNull('exam_term_id'))
            ->whereHas('departments', fn ($q) => $q->whereKey($departmentId ?? 0));
    }
}
