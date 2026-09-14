<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// NOTE: no permission gating yet — every authenticated user (auth:sanctum,
// applied at the route-group level) can manage students for now.
// Per-action permission middleware is intentionally deferred until
// permissions are set up separately (same as CourseController). Placed
// flat under API/V1 — doesn't fit Auth/Master/User, and no new category
// folder was asked for.
class StudentController extends Controller
{
    use ApiResponse;

    /**
     * One multi-purpose listing endpoint (same shape as CourseController::index):
     *  - ?id=5                    → single record (like show(), but via query param)
     *  - ?status=deleted          → only soft-deleted students
     *  - ?status=all              → every matching student, unpaginated
     *      - ?table_fields=["name","semester"]  → trims the SELECT to just those columns (+id)
     *      - ?is_active=yes|no    → filter by the student's own active/inactive flag
     *  - default                  → paginated, searchable listing
     *      - ?search=, ?name=, ?semester= (exact match), ?program_name=, ?is_active=yes|no, ?sort_by=, ?sort_by_field=, ?per_page=
     *      - ?table_fields=["name","semester"]  → same column-trimming as the "all" branch
     */
    public function index(Request $request): JsonResponse
    {
        $query = Student::query();

        if ($request->filled('status') && $request->string('status')->toString() === 'deleted') {
            $query->onlyTrashed();
        }

        if ($request->filled('id')) {
            $student = $query->find($request->input('id'));

            if (! $student) {
                return $this->notFound('No student found.');
            }

            return $this->success(new StudentResource($student), 'Student fetched successfully.');
        }

        if ($request->filled('status') && $request->string('status')->toString() === 'all') {
            if ($request->filled('table_fields')) {
                $this->applyFieldSelection($query, $request);
            }

            $this->applyActiveFilter($query, $request);
            $this->applySorting($query, $request);

            $students = $query->get();

            return $this->success($students, 'Students fetched successfully.');
        }

        // Searches every column the list actually shows (see
        // StudentsView.vue's table: Name, Semester, Program, Status) —
        // "Active"/"Inactive" match the boolean `status` column since
        // that's how it's displayed, not literal stored text.
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('roll_no', 'like', "%{$search}%")
                    ->orWhere('program_name', 'like', "%{$search}%")
                    ->orWhere('semester', 'like', "%{$search}%");
                // Prefix match, not "contains" — "active" is itself a
                // substring of "inactive" ("in-active"), so a naive
                // stripos() on either word would make searching "active"
                // wrongly match inactive rows too.
                $needle = strtolower($search);
                if (str_starts_with('active', $needle)) {
                    $q->orWhere('status', true);
                }
                if (str_starts_with('inactive', $needle)) {
                    $q->orWhere('status', false);
                }
            });
        }

        if ($request->filled('name')) {
            $query->where('name', 'like', '%'.$request->string('name')->toString().'%');
        }

        if ($request->filled('semester')) {
            $query->where('semester', $request->integer('semester'));
        }

        if ($request->filled('program_name')) {
            $query->where('program_name', 'like', '%'.$request->string('program_name')->toString().'%');
        }

        $fieldsRequested = $request->filled('table_fields');
        if ($fieldsRequested) {
            $this->applyFieldSelection($query, $request);
        }

        $this->applyActiveFilter($query, $request);
        $this->applySorting($query, $request);

        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $students = $query->paginate($perPage);

        return $this->paginated(
            $students,
            'Students fetched successfully.',
            $fieldsRequested ? null : StudentResource::collection($students),
        );
    }

    /**
     * Trims the SELECT to just the requested column names (always keeping
     * id). Student has no relations, so there's no dot-notation
     * relation-field handling here.
     */
    private function applyFieldSelection(Builder $query, Request $request): void
    {
        $fields = $request->input('table_fields');

        if (is_string($fields)) {
            $fields = json_decode(str_replace("'", '"', $fields), true);
        }

        if (is_array($fields) && $fields !== []) {
            $query->select(array_values(array_unique(array_merge(['id'], $fields))));
        }
    }

    private function applyActiveFilter(Builder $query, Request $request): void
    {
        if (! $request->filled('is_active')) {
            return;
        }

        $statusMap = ['yes' => true, 'no' => false];
        $isActive = Str::lower($request->string('is_active')->toString());

        if (array_key_exists($isActive, $statusMap)) {
            $query->where('status', $statusMap[$isActive]);
        }
    }

    private function applySorting(Builder $query, Request $request): void
    {
        $sortField = in_array($request->input('sort_by_field'), ['id', 'name', 'roll_no', 'semester', 'program_name', 'status', 'created_at'], true)
            ? $request->input('sort_by_field')
            : 'id';
        $sortDir = Str::lower((string) $request->input('sort_by')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortDir);
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= true;

        $student = Student::create($data);

        return $this->success(new StudentResource($student), 'Student created successfully.', 201);
    }

    public function show(Student $student): JsonResponse
    {
        return $this->success(new StudentResource($student), 'Student retrieved successfully.');
    }

    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $student->update($request->validated());

        return $this->success(new StudentResource($student->fresh()), 'Student updated successfully.');
    }

    public function destroy(Student $student): JsonResponse
    {
        $student->delete();

        return $this->success(null, 'Student deleted successfully.');
    }

    public function restore(Student $student): JsonResponse
    {
        $student->restore();

        return $this->success(new StudentResource($student), 'Student restored successfully.');
    }
}
