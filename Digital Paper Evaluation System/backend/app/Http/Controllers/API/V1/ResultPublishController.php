<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResultPublish\ResultPublishRequest;
use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\Department;
use App\Models\ExamSubType;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\Program;
use App\Models\Student;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * POST /result-publish — public API for the main database to fetch results:
 * no user login and no X-API-KEY, only the "Security-Code" header from
 * General Settings (SecurityCodeAuth). Searched by the main database's Ref
 * Ids (exam term, exam type, exam sub type, program) and semester, sent in
 * the request body (JSON or form) — optionally narrowed to one department
 * (department_id — the packet's department, chosen at Upload / Generate QR)
 * and / or one course (course_id), both Ref Ids, and / or one student
 * (registration_no); answers
 * every student of those packets with their result in every course.
 *
 * A course's status per student: evaluated (marks set), absent (marked
 * absent at scanning), problem (open issue, not evaluated yet) or pending
 * (not evaluated yet) — marks are 0 for all but evaluated, so the status,
 * not the marks, tells a real 0 from no result. Numbers are never null.
 * is_complete is true only when nothing is pending or a problem, i.e. the
 * results are final.
 */
class ResultPublishController extends Controller
{
    use ApiResponse;

    public function index(ResultPublishRequest $request): JsonResponse
    {
        $term = ExamTerm::withTrashed()->where('inserted_id', $request->integer('exam_term_id'))->first(['id', 'inserted_id', 'name']);
        $type = ExamType::withTrashed()->where('inserted_id', $request->integer('exam_type_id'))->first(['id', 'inserted_id', 'name']);
        $subType = ExamSubType::withTrashed()->where('inserted_id', $request->integer('exam_sub_type_id'))->first(['id', 'inserted_id', 'name']);
        $program = Program::withTrashed()->where('inserted_id', $request->integer('program_id'))->first(['id', 'inserted_id', 'name']);
        $semester = $request->integer('semester');
        $department = $request->filled('department_id')
            ? Department::withTrashed()->where('inserted_id', $request->integer('department_id'))->first(['id', 'inserted_id', 'name'])
            : null;
        $course = $request->filled('course_id')
            ? Course::withTrashed()->where('inserted_id', $request->integer('course_id'))->first(['id', 'inserted_id', 'name'])
            : null;
        $registrationNo = $request->filled('registration_no') ? trim($request->string('registration_no')->toString()) : null;

        $rows = AnswerSheet::query()
            ->join('question_answer_sheet_mappings as m', 'm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('courses as c', 'c.id', '=', 'm.course_id')
            ->leftJoin('question_papers as qp', 'qp.id', '=', 'm.question_paper_id')
            ->whereNull('m.deleted_at')
            ->where('m.exam_term_id', $term->id)
            ->where('m.exam_type_id', $type->id)
            ->where('m.exam_sub_type_id', $subType->id)
            // Packets keep the program by name (see QuestionAnswerSheetMapping).
            ->where('m.program_name', $program->name)
            ->where('m.semester', $semester)
            ->when($department !== null, fn ($q) => $q->where('m.department_id', $department->id))
            ->when($course !== null, fn ($q) => $q->where('m.course_id', $course->id))
            ->when($registrationNo !== null, fn ($q) => $q->where('answer_sheets.registration_no', $registrationNo))
            ->orderBy('answer_sheets.registration_no')
            ->orderBy('c.name')
            ->get([
                'answer_sheets.name', 'answer_sheets.registration_no', 'answer_sheets.roll_no',
                'answer_sheets.marks', 'answer_sheets.absent', 'answer_sheets.issue_status',
                'c.inserted_id as course_ref_id', 'c.name as course_name', 'c.code as course_code', 'c.type as course_type',
                'qp.full_marks',
            ]);

        // Students' own Ref Ids, by registration number.
        $studentRefIds = Student::whereIn('registration_no', $rows->pluck('registration_no')->filter()->unique())
            ->pluck('inserted_id', 'registration_no');

        $courses = $rows->unique('course_ref_id')
            // Same order as each student's results (the query's ORDER BY c.name — case-insensitive).
            ->sortBy('course_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn ($row) => [
                'course_ref_id' => $row->course_ref_id !== null ? (int) $row->course_ref_id : null,
                'course_code' => $row->course_code,
                'course_name' => $row->course_name,
                'course_type' => $row->course_type,
                'full_marks' => (int) ($row->full_marks ?? 0),
            ])
            ->values();

        $students = $rows->groupBy(fn ($row) => $row->registration_no ?: $row->roll_no)
            ->map(function ($sheets) use ($studentRefIds) {
                $first = $sheets->first();

                return [
                    'student_ref_id' => isset($studentRefIds[$first->registration_no]) ? (int) $studentRefIds[$first->registration_no] : null,
                    'name' => $first->name,
                    'registration_no' => $first->registration_no,
                    'roll_no' => $first->roll_no,
                    'results' => $sheets->map(fn ($sheet) => [
                        'course_ref_id' => $sheet->course_ref_id !== null ? (int) $sheet->course_ref_id : null,
                        'course_code' => $sheet->course_code,
                        'course_name' => $sheet->course_name,
                        'course_type' => $sheet->course_type,
                        'full_marks' => (int) ($sheet->full_marks ?? 0),
                        // 0 when not evaluated (absent / problem / pending) — status says which.
                        'marks' => $sheet->absent || $sheet->marks === null ? 0 : (float) $sheet->marks,
                        'status' => $this->status($sheet),
                    ])->values()->all(),
                ];
            })
            ->values();

        $statuses = $students->flatMap(fn ($s) => array_column($s['results'], 'status'))->countBy();

        return $this->success([
            'exam_term' => ['ref_id' => (int) $term->inserted_id, 'name' => $term->name],
            'exam_type' => ['ref_id' => (int) $type->inserted_id, 'name' => $type->name],
            'exam_sub_type' => ['ref_id' => (int) $subType->inserted_id, 'name' => $subType->name],
            'program' => ['ref_id' => (int) $program->inserted_id, 'name' => $program->name],
            'semester' => $semester,
            // The optional filters, as searched (null when not sent).
            'department' => $department ? ['ref_id' => (int) $department->inserted_id, 'name' => $department->name] : null,
            'course' => $course ? ['ref_id' => (int) $course->inserted_id, 'name' => $course->name] : null,
            'registration_no' => $registrationNo,
            'is_complete' => $rows->isNotEmpty() && ! $statuses->has('pending') && ! $statuses->has('problem'),
            'summary' => [
                'students' => $students->count(),
                'courses' => $courses->count(),
                'evaluated' => $statuses->get('evaluated', 0),
                'absent' => $statuses->get('absent', 0),
                'pending' => $statuses->get('pending', 0),
                'problem' => $statuses->get('problem', 0),
            ],
            'courses' => $courses->all(),
            'students' => $students->all(),
        ], $rows->isEmpty() ? 'No results found for this search.' : 'Results fetched successfully.');
    }

    private function status(object $sheet): string
    {
        return match (true) {
            (bool) $sheet->absent => 'absent',
            $sheet->marks !== null => 'evaluated',
            $sheet->issue_status === 'open' => 'problem',
            default => 'pending',
        };
    }
}
