<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Qr\ExamStudentSearchRequest;
use App\Http\Requests\Qr\GenerateQrCodesRequest;
use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\Department;
use App\Models\ExamSubType;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\Program;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Services\MainDbApiService;
use App\Services\QrCodeNumberService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Generate QR & Scan" → Generate QR (GenerateQrView.vue).
 */
class QrCodeController extends Controller
{
    use ApiResponse;

    /** The college main database's exam-student list. */
    private const EXAM_STUDENT_PATH = 'DataApi/examStudent';

    /**
     * GET /qr/program-courses?program_id=118 — Generate QR's Course dropdown:
     * the courses mapped to that program. program_id is the program's Ref Id
     * (inserted_id); each course comes back with its Ref Id as `id`. Only
     * active, synced courses the main database hasn't deleted.
     */
    public function programCourses(Request $request): JsonResponse
    {
        $courses = Course::query()
            ->whereNotNull('inserted_id')
            ->where('status', true)
            ->where('deleted_from_main_db', false)
            ->whereHas('programs', fn ($q) => $q->where('programs.inserted_id', $request->integer('program_id')))
            ->orderBy('name')
            ->get(['inserted_id', 'name', 'code'])
            ->map(fn (Course $course) => [
                'id' => $course->inserted_id,
                'name' => $course->code ? "{$course->name} ({$course->code})" : $course->name,
            ]);

        return $this->success($courses, 'Program courses fetched successfully.');
    }

    /**
     * GET /qr/exam-students — the students sitting an exam, one row per
     * student per course, from the main database (DataApi/examStudent).
     * Exam term, exam type, exam sub type (enroll_session_id), program,
     * course (mapped to that program) and semester are required; exam year,
     * department and batch narrow it. Every id is the main database's own
     * (our inserted_id / "Ref Id") — never our auto-increment id — and goes
     * to the API as is; program/department come back as our names.
     */
    public function examStudents(ExamStudentSearchRequest $request, MainDbApiService $mainDb): JsonResponse
    {
        try {
            $students = $this->fetchStudents($mainDb, $request->validated());
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'items' => $students,
            'total' => $students->count(),
            'student_count' => $students->pluck('registration_no')->unique()->count(),
        ], 'Exam students fetched successfully.');
    }

    /**
     * DataApi/examStudent for these (Ref Id) params, each row cleaned and
     * with our program/department names.
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @throws RuntimeException when the API call fails
     */
    private function fetchStudents(MainDbApiService $mainDb, array $data): Collection
    {
        // Same params, same order, as the API's Postman request.
        $query = [];
        foreach (['exam_term_id', 'exam_type_id', 'enroll_session_id', 'program_id', 'course_id', 'semester', 'exam_year', 'department_id', 'batch'] as $field) {
            $query[$field] = trim((string) ($data[$field] ?? ''));
        }

        $rows = $mainDb->fetch(self::EXAM_STUDENT_PATH, 'POST', $query);

        // main database id → our name
        $programs = Program::withTrashed()->whereNotNull('inserted_id')->pluck('name', 'inserted_id');
        $departments = Department::withTrashed()->whereNotNull('inserted_id')->pluck('name', 'inserted_id');
        // Names can start with a non-breaking space (U+00A0), which trim() doesn't remove.
        $clean = fn ($value) => trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $value));

        return collect($rows)->map(fn (array $row) => [
            'student_id' => $clean($row['student_id'] ?? ''),
            'registration_no' => $clean($row['regisration_id'] ?? $row['registration_id'] ?? ''),
            'name' => $clean($row['name'] ?? ''),
            'course_name' => $clean($row['course_name'] ?? ''),
            'course_code' => $clean($row['course_code'] ?? ''),
            'program_name' => $programs->get((int) ($row['program_id'] ?? 0)),
            'department_id' => $clean($row['department_id'] ?? ''), // Ref Id
            'department_name' => $departments->get((int) ($row['department_id'] ?? 0)),
            'semester' => $clean($row['semester'] ?? ''),
            'batch' => $clean($row['batch'] ?? ''),
            'exam_year' => $clean($row['exam_year'] ?? ''),
        ])->values();
    }

    /**
     * GET /qr/question-papers?course_id=&semester=&exam_term_id=&department_id=
     * — the Generate QR modal's Question Paper dropdown (all Ref Ids): ready
     * papers for that course, semester and exam term, tagged with the
     * department. Each comes back with its own (our) id — it's our record.
     */
    public function questionPapers(Request $request): JsonResponse
    {
        $papers = GenerateQrCodesRequest::matchingQuestionPapers(
            $request->integer('course_id'), $request->integer('semester'), $request->integer('exam_term_id'), $request->integer('department_id'),
        )
            ->with('course:id,name,code')
            ->orderByDesc('exam_year')->orderByDesc('id')
            ->get(['id', 'course_id', 'exam_year', 'semester'])
            ->map(fn (QuestionPaper $paper) => [
                'id' => $paper->id,
                'name' => trim(($paper->course?->name ?? 'Unknown course').($paper->course?->code ? " ({$paper->course->code})" : ''))
                    ." — {$paper->exam_year}, Sem {$paper->semester} (#{$paper->id})",
            ]);

        return $this->success($papers, 'Question papers fetched successfully.');
    }

    /**
     * POST /qr/generate — the Generate QR modal's Generate. Re-reads the
     * students from the main database (search params + the modal's
     * department, exam year and batch) and, in one transaction:
     *  - finds this packet (question_answer_sheet_mappings: same exam term,
     *    type, sub type, program, course, semester, department and batch)
     *    or creates it, with is_scan_complete = 0;
     *  - adds one answer sheet per student not already in it, each with the
     *    next QR number (QrCodeNumberService — START_QR_POINT, then +1;
     *    never overlapping, even with two people generating at once).
     * Everything stored uses our own ids, never the main database's Ref Ids.
     */
    public function generate(GenerateQrCodesRequest $request, MainDbApiService $mainDb, QrCodeNumberService $qrNumbers): JsonResponse
    {
        $data = $request->validated();

        try {
            $rows = $this->fetchStudents($mainDb, $data);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        // Only rows really matching the packet (in case the API ignores a
        // filter), one per student.
        $students = $rows
            ->filter(fn (array $row) => $row['registration_no'] !== ''
                && (string) $row['department_id'] === (string) $data['department_id']
                && $row['batch'] === trim($data['batch']))
            ->unique('registration_no')
            ->values();

        if ($students->isEmpty()) {
            return $this->error('No students found for this department and batch.', 422);
        }

        // Ref Id → our own id.
        $ourId = fn (string $model, $ref) => $model::where('inserted_id', $ref)->value('id');
        $department = Department::where('inserted_id', $data['department_id'])->first(['id', 'name']);
        $packet = [
            'exam_term_id' => $ourId(ExamTerm::class, $data['exam_term_id']),
            'exam_type_id' => $ourId(ExamType::class, $data['exam_type_id']),
            'exam_sub_type_id' => $ourId(ExamSubType::class, $data['enroll_session_id']),
            'program_name' => Program::where('inserted_id', $data['program_id'])->value('name'),
            'course_id' => $ourId(Course::class, $data['course_id']),
            'semester' => (int) $data['semester'],
            'department_id' => $department->id,
            'batch' => trim($data['batch']),
        ];

        $result = DB::transaction(function () use ($data, $students, $packet, $department, $qrNumbers) {
            $qrNumbers->lock(); // one generate at a time from here on

            $mapping = QuestionAnswerSheetMapping::where($packet)->first();
            $reused = (bool) $mapping;
            $mapping ??= QuestionAnswerSheetMapping::create($packet + [
                'question_paper_id' => $data['question_paper_id'],
                'department_name' => $department->name,
                'exam_year' => trim($data['exam_year']),
                'packet_code' => trim($data['packet_code']),
                'is_scan_complete' => false,
            ]);

            $existing = $mapping->answerSheets()->pluck('registration_no')->filter()->map(fn ($r) => (string) $r)->all();
            $new = $students->reject(fn (array $row) => in_array($row['registration_no'], $existing, true))->values();
            $numbers = $qrNumbers->reserve($new->count());

            foreach ($new as $i => $row) {
                AnswerSheet::create([
                    'question_answer_sheet_mapping_id' => $mapping->id,
                    'subject_code' => $row['course_code'] ?: null,
                    'subject_name' => $row['course_name'] ?: null,
                    'semester' => $packet['semester'],
                    'subject_barcode' => $numbers[$i],
                    'roll_no' => $row['registration_no'],
                    'name' => $row['name'] ?: null,
                    'registration_no' => $row['registration_no'],
                ]);
            }

            return [
                'mapping_id' => $mapping->id,
                'packet_code' => $mapping->packet_code,
                'existing_packet' => $reused,
                'created' => $new->count(),
                'already_generated' => $students->count() - $new->count(),
                'first_qr' => $numbers[0] ?? null,
                'last_qr' => $numbers === [] ? null : end($numbers),
            ];
        });

        $message = $result['created'] > 0
            ? "QR codes generated for {$result['created']} student(s)."
            : 'Every student already has a QR code in this packet — nothing new to generate.';

        return $this->success($result, $message, $result['created'] > 0 ? 201 : 200);
    }

    /**
     * GET /qr/packets/{mapping}/codes — Scan Papers' "Print QR Code": the
     * packet's details and every answer sheet's QR number with its
     * student, in the order they were generated. The PDF itself is drawn in
     * the browser (ScanPapersView.vue → utils/qrCodePdf.js).
     */
    public function packetCodes(QuestionAnswerSheetMapping $questionAnswerSheetMapping): JsonResponse
    {
        $mapping = $questionAnswerSheetMapping->load(['course:id,name,code', 'examTerm:id,name', 'examType:id,name', 'examSubType:id,name']);

        $codes = $mapping->answerSheets()
            ->orderBy('id')
            ->get(['subject_barcode', 'registration_no', 'name'])
            ->map(fn (AnswerSheet $sheet) => [
                'qr_code' => $sheet->subject_barcode,
                'registration_no' => $sheet->registration_no,
                'name' => $sheet->name,
            ]);

        return $this->success([
            'packet' => [
                'id' => $mapping->id,
                'packet_code' => $mapping->packet_code,
                'program_name' => $mapping->program_name,
                'course_name' => $mapping->course?->name,
                'course_code' => $mapping->course?->code,
                'semester' => $mapping->semester,
                'exam_term_name' => $mapping->examTerm?->name,
                'exam_type_name' => $mapping->examType?->name,
                'exam_sub_type_name' => $mapping->examSubType?->name,
                'department_name' => $mapping->department_name,
                'batch' => $mapping->batch,
                'exam_year' => $mapping->getAttribute('exam_year'),
            ],
            'codes' => $codes,
        ], 'QR codes fetched successfully.');
    }
}
