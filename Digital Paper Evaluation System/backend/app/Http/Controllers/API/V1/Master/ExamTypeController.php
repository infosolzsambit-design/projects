<?php

namespace App\Http\Controllers\API\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExamType\StoreExamTypeRequest;
use App\Http\Requests\ExamType\UpdateExamTypeRequest;
use App\Http\Resources\ExamTypeResource;
use App\Models\ExamType;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// NOTE: no permission gating yet — every authenticated user (auth:sanctum,
// applied at the route-group level) can manage exam types for now.
// Per-action permission middleware is intentionally deferred until
// permissions are set up separately (same as ExamTermController).
class ExamTypeController extends Controller
{
    use ApiResponse;

    /**
     * One multi-purpose listing endpoint (same shape as ExamTermController::index):
     *  - ?id=5                    → single record (like show(), but via query param)
     *  - ?status=deleted          → only soft-deleted exam types
     *  - ?status=all              → every matching exam type, unpaginated
     *      - ?table_fields=["name"]  → trims the SELECT to just those columns (+id)
     *      - ?is_active=yes|no    → filter by the exam type's own active/inactive flag
     *  - default                  → paginated, searchable listing
     *      - ?search=, ?name=, ?is_active=yes|no, ?sort_by=, ?sort_by_field=, ?per_page=
     *      - ?table_fields=["name"]  → same column-trimming as the "all" branch
     */
    public function index(Request $request): JsonResponse
    {
        $query = ExamType::query();

        if ($request->filled('status') && $request->string('status')->toString() === 'deleted') {
            $query->onlyTrashed();
        }

        if ($request->filled('id')) {
            $examType = $query->find($request->input('id'));

            if (! $examType) {
                return $this->notFound('No exam type found.');
            }

            return $this->success(new ExamTypeResource($examType), 'Exam type fetched successfully.');
        }

        if ($request->filled('status') && $request->string('status')->toString() === 'all') {
            if ($request->filled('table_fields')) {
                $this->applyFieldSelection($query, $request);
            }

            $this->applyActiveFilter($query, $request);
            $this->applySorting($query, $request);

            $examTypes = $query->get();

            return $this->success($examTypes, 'Exam types fetched successfully.');
        }

        // Searches every column the list actually shows (see TypesView.vue's
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

        $examTypes = $query->paginate($perPage);

        return $this->paginated(
            $examTypes,
            'Exam types fetched successfully.',
            $fieldsRequested ? null : ExamTypeResource::collection($examTypes),
        );
    }

    /**
     * Trims the SELECT to just the requested column names (always keeping
     * id). ExamType has no relations, so there's no dot-notation
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

    public function store(StoreExamTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= true;

        $examType = ExamType::create($data);

        return $this->success(new ExamTypeResource($examType), 'Exam type created successfully.', 201);
    }

    public function show(ExamType $examType): JsonResponse
    {
        return $this->success(new ExamTypeResource($examType), 'Exam type retrieved successfully.');
    }

    public function update(UpdateExamTypeRequest $request, ExamType $examType): JsonResponse
    {
        $examType->update($request->validated());

        return $this->success(new ExamTypeResource($examType->fresh()), 'Exam type updated successfully.');
    }

    public function destroy(ExamType $examType): JsonResponse
    {
        $examType->delete();

        return $this->success(null, 'Exam type deleted successfully.');
    }

    public function restore(ExamType $examType): JsonResponse
    {
        $examType->restore();

        return $this->success(new ExamTypeResource($examType), 'Exam type restored successfully.');
    }
}
