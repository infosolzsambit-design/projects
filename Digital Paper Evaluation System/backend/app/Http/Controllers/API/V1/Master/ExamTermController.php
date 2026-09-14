<?php

namespace App\Http\Controllers\API\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExamTerm\StoreExamTermRequest;
use App\Http\Requests\ExamTerm\UpdateExamTermRequest;
use App\Http\Resources\ExamTermResource;
use App\Models\ExamTerm;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// NOTE: no permission gating yet — every authenticated user (auth:sanctum,
// applied at the route-group level) can manage exam terms for now.
// Per-action permission middleware is intentionally deferred until
// permissions are set up separately (same as CourseController).
class ExamTermController extends Controller
{
    use ApiResponse;

    /**
     * One multi-purpose listing endpoint (same shape as CourseController::index):
     *  - ?id=5                    → single record (like show(), but via query param)
     *  - ?status=deleted          → only soft-deleted exam terms
     *  - ?status=all              → every matching exam term, unpaginated
     *      - ?table_fields=["name"]  → trims the SELECT to just those columns (+id)
     *      - ?is_active=yes|no    → filter by the exam term's own active/inactive flag
     *  - default                  → paginated, searchable listing
     *      - ?search=, ?name=, ?is_active=yes|no, ?sort_by=, ?sort_by_field=, ?per_page=
     *      - ?table_fields=["name"]  → same column-trimming as the "all" branch
     */
    public function index(Request $request): JsonResponse
    {
        $query = ExamTerm::query();

        if ($request->filled('status') && $request->string('status')->toString() === 'deleted') {
            $query->onlyTrashed();
        }

        if ($request->filled('id')) {
            $examTerm = $query->find($request->input('id'));

            if (! $examTerm) {
                return $this->notFound('No exam term found.');
            }

            return $this->success(new ExamTermResource($examTerm), 'Exam term fetched successfully.');
        }

        if ($request->filled('status') && $request->string('status')->toString() === 'all') {
            if ($request->filled('table_fields')) {
                $this->applyFieldSelection($query, $request);
            }

            $this->applyActiveFilter($query, $request);
            $this->applySorting($query, $request);

            $examTerms = $query->get();

            return $this->success($examTerms, 'Exam terms fetched successfully.');
        }

        // Searches every column the list actually shows (see TermsView.vue's
        // table: Name, Status) — "Active"/"Inactive" match the boolean
        // `status` column since that's how it's displayed.
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
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

        $examTerms = $query->paginate($perPage);

        return $this->paginated(
            $examTerms,
            'Exam terms fetched successfully.',
            $fieldsRequested ? null : ExamTermResource::collection($examTerms),
        );
    }

    /**
     * Trims the SELECT to just the requested column names (always keeping
     * id). ExamTerm has no relations, so there's no dot-notation
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
        $sortField = in_array($request->input('sort_by_field'), ['id', 'name', 'status', 'created_at'], true)
            ? $request->input('sort_by_field')
            : 'id';
        $sortDir = Str::lower((string) $request->input('sort_by')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortDir);
    }

    public function store(StoreExamTermRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= true;

        $examTerm = ExamTerm::create($data);

        return $this->success(new ExamTermResource($examTerm), 'Exam term created successfully.', 201);
    }

    public function show(ExamTerm $examTerm): JsonResponse
    {
        return $this->success(new ExamTermResource($examTerm), 'Exam term retrieved successfully.');
    }

    public function update(UpdateExamTermRequest $request, ExamTerm $examTerm): JsonResponse
    {
        $examTerm->update($request->validated());

        return $this->success(new ExamTermResource($examTerm->fresh()), 'Exam term updated successfully.');
    }

    public function destroy(ExamTerm $examTerm): JsonResponse
    {
        $examTerm->delete();

        return $this->success(null, 'Exam term deleted successfully.');
    }

    public function restore(ExamTerm $examTerm): JsonResponse
    {
        $examTerm->restore();

        return $this->success(new ExamTermResource($examTerm), 'Exam term restored successfully.');
    }
}
