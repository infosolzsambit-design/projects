<?php

namespace App\Http\Controllers\API\V1\Report;

use App\Http\Controllers\Controller;
use App\Models\AnswerSheet;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Sidebar's Report → Evaluated Copy Download — bulk download of the
 * evaluated copies (the student's scanned answer sheet with the teacher's
 * ticks / crosses / pencil marks drawn on and the marks stamped, plus the
 * top sheet in front). Same search fields as the other reports.
 *
 * The copies themselves are built in the browser, one by one, exactly like
 * Reset Evaluation's "Download evaluated" (frontend utils/evaluatedPdf.js —
 * the original PDF + draft_annotations), then zipped there. So the backend
 * only answers:
 *  - index()  — the evaluated sheets of the search (light rows, no
 *    annotations) and a summary of what can't be downloaded yet;
 *  - sheet()  — one sheet's file path, annotations and marks, fetched per
 *    sheet while the ZIP is built (annotations can be large, so they're
 *    never sent for the whole list at once).
 * The top sheet comes from AnswerBookTopSheetReportController::view().
 * No permission gate yet (to be added later).
 */
class EvaluatedCopyDownloadController extends Controller
{
    use ApiResponse;

    /** GET /reports/evaluated-copies — evaluated sheets of the search. */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        $summary = $this->baseQuery($filters)
            ->selectRaw(
                'SUM(CASE WHEN answer_sheets.absent = 1 THEN 1 ELSE 0 END) as absent, '.
                'SUM(CASE WHEN answer_sheets.absent = 0 AND answer_sheets.marks IS NOT NULL THEN 1 ELSE 0 END) as evaluated, '.
                'SUM(CASE WHEN answer_sheets.absent = 0 AND answer_sheets.marks IS NULL THEN 1 ELSE 0 END) as not_evaluated, '.
                'SUM(CASE WHEN answer_sheets.absent = 0 AND answer_sheets.marks IS NOT NULL AND answer_sheets.pdf_path IS NULL THEN 1 ELSE 0 END) as no_file',
            )
            ->first();

        $rows = $this->baseQuery($filters)
            ->where('answer_sheets.absent', false)
            ->whereNotNull('answer_sheets.marks')
            ->leftJoin('users as u', 'u.id', '=', 'answer_sheets.teacher_id')
            ->leftJoin('teacher_details as td', function ($join) {
                $join->on('td.user_id', '=', 'u.id')->whereNull('td.deleted_at');
            })
            ->orderBy('answer_sheets.roll_no')
            ->orderBy('answer_sheets.id')
            ->get([
                'answer_sheets.id', 'answer_sheets.subject_barcode', 'answer_sheets.barcode', 'answer_sheets.roll_no',
                'answer_sheets.registration_no', 'answer_sheets.name', 'answer_sheets.marks', 'answer_sheets.evaluated_at',
                'answer_sheets.pdf_path', 'qp.full_marks', 'u.name as teacher_name', 'td.emp_code as teacher_emp_code',
            ])
            ->map(fn (AnswerSheet $row) => [
                'id' => $row->id,
                'script_code' => $row->subject_barcode ?: $row->barcode,
                'roll_no' => $row->roll_no,
                'registration_no' => $row->registration_no,
                'name' => $row->name,
                'teacher_name' => $row->teacher_name,
                'teacher_emp_code' => $row->teacher_emp_code,
                'marks' => (float) $row->marks,
                'full_marks' => $row->full_marks !== null ? (int) $row->full_marks : null,
                'evaluated_at' => $row->evaluated_at ? Carbon::parse($row->evaluated_at)->format('d-m-Y h:i A') : null,
                // No uploaded scan → nothing to draw the evaluation on.
                'has_file' => $row->pdf_path !== null,
            ])
            ->values()
            ->all();

        return $this->success([
            'summary' => [
                'evaluated' => (int) ($summary->evaluated ?? 0),
                'not_evaluated' => (int) ($summary->not_evaluated ?? 0),
                'absent' => (int) ($summary->absent ?? 0),
                'no_file' => (int) ($summary->no_file ?? 0),
            ],
            'rows' => $rows,
        ], 'Evaluated copies fetched successfully.');
    }

    /** GET /reports/evaluated-copies/{answerSheet} — what one copy is built from. */
    public function sheet(AnswerSheet $answerSheet): JsonResponse
    {
        if ($answerSheet->marks === null || $answerSheet->absent) {
            return $this->error('This answer sheet has not been evaluated yet.', 422);
        }
        if ($answerSheet->pdf_path === null) {
            return $this->error('This answer sheet has no uploaded file.', 422);
        }

        $answerSheet->loadMissing('mapping.questionPaper');

        return $this->success([
            'id' => $answerSheet->id,
            'pdf_url' => $answerSheet->pdf_path,
            'draft_annotations' => $answerSheet->draft_annotations,
            'marks' => (float) $answerSheet->marks,
            'max_marks' => $answerSheet->mapping?->questionPaper?->full_marks,
        ], 'Evaluated copy data fetched successfully.');
    }

    /**
     * Same search fields as the other reports.
     *
     * @return array{program_name: string, course_id: int, department_id: ?int, exam_term_id: int, exam_type_id: int, exam_sub_type_id: ?int, semester: int, exam_year: int, teacher_id: ?int}
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'program_name' => ['required', 'string'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            // Optional — the packet's department (question_answer_sheet_mappings.department_id).
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'exam_term_id' => ['required', 'integer', Rule::exists('exam_terms', 'id')->whereNull('deleted_at')],
            'exam_type_id' => ['required', 'integer', Rule::exists('exam_types', 'id')->whereNull('deleted_at')],
            'exam_sub_type_id' => ['nullable', 'integer', Rule::exists('exam_sub_types', 'id')],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'exam_year' => ['required', 'integer', 'digits:4'],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teacher_details', 'user_id')->whereNull('deleted_at')],
        ]);
    }

    /**
     * @param  array{program_name: string, course_id: int, department_id: ?int, exam_term_id: int, exam_type_id: int, exam_sub_type_id: ?int, semester: int, exam_year: int, teacher_id: ?int}  $filters
     */
    private function baseQuery(array $filters): Builder
    {
        return AnswerSheet::query()
            ->join('question_answer_sheet_mappings as m', 'm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('question_papers as qp', 'qp.id', '=', 'm.question_paper_id')
            ->whereNull('m.deleted_at')
            ->where('m.program_name', $filters['program_name'])
            ->where('m.course_id', $filters['course_id'])
            ->where('m.exam_term_id', $filters['exam_term_id'])
            ->where('m.exam_type_id', $filters['exam_type_id'])
            ->where('m.semester', $filters['semester'])
            ->where('qp.exam_year', $filters['exam_year'])
            ->when(! empty($filters['exam_sub_type_id']), fn ($q) => $q->where('m.exam_sub_type_id', $filters['exam_sub_type_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('m.department_id', $filters['department_id']))
            ->when(! empty($filters['teacher_id']), fn ($q) => $q->where('answer_sheets.teacher_id', $filters['teacher_id']));
    }
}
