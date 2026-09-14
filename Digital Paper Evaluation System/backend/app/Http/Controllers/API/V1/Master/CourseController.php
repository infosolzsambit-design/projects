<?php

namespace App\Http\Controllers\API\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Course\StoreCourseRequest;
use App\Http\Requests\Course\UpdateCourseRequest;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// NOTE: no permission gating yet — every authenticated user (auth:sanctum,
// applied at the route-group level) can manage courses for now. Per-action
// permission middleware (course-list/create/view/update/delete/restore) is
// intentionally deferred until permissions are set up separately.
class CourseController extends Controller
{
    use ApiResponse;

    /**
     * One multi-purpose listing endpoint:
     *  - ?id=5                    → single record (like show(), but via query param)
     *  - ?status=deleted          → only soft-deleted courses
     *  - ?status=all              → every matching course, unpaginated
     *      - ?table_fields=["name","code"]  → trims the SELECT to just those columns (+id)
     *      - ?is_active=yes|no    → filter by the course's own active/inactive flag
     *  - default                  → paginated, searchable listing
     *      - ?search=, ?name=, ?code=, ?is_active=yes|no, ?sort_by=, ?sort_by_field=, ?per_page=
     *      - ?table_fields=["name","code"]  → same column-trimming as the "all" branch
     */
    public function index(Request $request): JsonResponse
    {
        $query = Course::query();

        if ($request->filled('status') && $request->string('status')->toString() === 'deleted') {
            $query->onlyTrashed();
        }

        if ($request->filled('id')) {
            $course = $query->find($request->input('id'));

            if (! $course) {
                return $this->notFound('No course found.');
            }

            return $this->success(new CourseResource($course), 'Course fetched successfully.');
        }

        if ($request->filled('status') && $request->string('status')->toString() === 'all') {
            if ($request->filled('table_fields')) {
                $this->applyFieldSelection($query, $request);
            }

            $this->applyActiveFilter($query, $request);
            $this->applySorting($query, $request);

            $courses = $query->get();

            return $this->success($courses, 'Courses fetched successfully.');
        }

        // Searches every column the list actually shows (see
        // CoursesView.vue's table: Name, Code, Status) — "Active"/
        // "Inactive" match the boolean `status` column since that's how
        // it's displayed, not literal stored text.
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
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

        if ($request->filled('code')) {
            $query->where('code', 'like', '%'.$request->string('code')->toString().'%');
        }

        // Trimming the SELECT is one of the cheapest wins for fetch speed —
        // available here too, not just on the ?status=all branch. When used,
        // the raw (already-trimmed) rows are returned instead of routing
        // through CourseResource, so a requester never sees a field they
        // didn't select come back as a misleading null.
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

        $courses = $query->paginate($perPage);

        return $this->paginated(
            $courses,
            'Courses fetched successfully.',
            $fieldsRequested ? null : CourseResource::collection($courses),
        );
    }

    /**
     * Trims the SELECT to just the requested column names (always keeping
     * id). Course has no relations, so — unlike the multi-module version of
     * this pattern — there's no dot-notation relation-field handling here.
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
        $sortField = in_array($request->input('sort_by_field'), ['id', 'name', 'code', 'status', 'created_at'], true)
            ? $request->input('sort_by_field')
            : 'id';
        $sortDir = Str::lower((string) $request->input('sort_by')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortDir);
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= true;

        $course = Course::create($data);

        return $this->success(new CourseResource($course), 'Course created successfully.', 201);
    }

    public function show(Course $course): JsonResponse
    {
        return $this->success(new CourseResource($course), 'Course retrieved successfully.');
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        $course->update($request->validated());

        return $this->success(new CourseResource($course->fresh()), 'Course updated successfully.');
    }

    public function destroy(Course $course): JsonResponse
    {
        // A program requires at least one mapped course (see
        // Store/UpdateProgramRequest) — silently letting a mapped course
        // get deleted would leave that program with zero, since a
        // soft-deleted course drops out of Program::with('courses') via
        // Course's own SoftDeletes scope while the pivot row survives.
        if ($course->programs()->exists()) {
            return $this->error('This course is mapped to one or more programs and cannot be deleted.', 409);
        }

        $course->delete();

        return $this->success(null, 'Course deleted successfully.');
    }

    public function restore(Course $course): JsonResponse
    {
        $course->restore();

        return $this->success(new CourseResource($course), 'Course restored successfully.');
    }
}
