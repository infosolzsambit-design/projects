<?php

namespace App\Http\Controllers\API\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExamSubType\StoreExamSubTypeRequest;
use App\Http\Requests\ExamSubType\UpdateExamSubTypeRequest;
use App\Http\Resources\ExamSubTypeResource;
use App\Models\ExamSubType;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\MainDbSyncConfig;
use App\Services\MainDbApiService;
use App\Services\MainDbSyncService;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

// NOTE: no permission gating yet — same as ExamTypeController (the UI
// gates on exam-sub-type-* permissions).
class ExamSubTypeController extends Controller
{
    use ApiResponse;

    /**
     * One multi-purpose listing endpoint (same shape as ExamTypeController::index):
     *  - ?id=5                    → single record (like show(), but via query param)
     *  - ?status=deleted          → only soft-deleted exam sub types
     *  - ?status=all              → every matching exam sub type, unpaginated
     *      - ?table_fields=["name"]  → trims the SELECT to just those columns (+id)
     *  - default                  → paginated, searchable listing
     *      - ?search=, ?name=, ?sort_by=, ?sort_by_field=, ?per_page=
     *  - every list branch: ?is_active=yes|no, ?exam_type_id=, ?exam_term_id=
     */
    public function index(Request $request): JsonResponse
    {
        $query = ExamSubType::query()->with(['examType:id,inserted_id,name', 'examTerm:id,inserted_id,name']);

        if ($request->filled('status') && $request->string('status')->toString() === 'deleted') {
            $query->onlyTrashed();
        }

        if ($request->filled('id')) {
            $examSubType = $query->find($request->input('id'));

            if (! $examSubType) {
                return $this->notFound('No exam sub type found.');
            }

            return $this->success(new ExamSubTypeResource($examSubType), 'Exam sub type fetched successfully.');
        }

        $this->applyFilters($query, $request);

        if ($request->filled('status') && $request->string('status')->toString() === 'all') {
            if ($request->filled('table_fields')) {
                $query->setEagerLoads([]);
                $this->applyFieldSelection($query, $request);
            }

            $this->applySorting($query, $request);

            return $this->success($query->get(), 'Exam sub types fetched successfully.');
        }

        // Searches every column the list shows: Name, Exam Type, Exam Term,
        // and "Active"/"Inactive" for the boolean `status` column.
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('examType', fn ($t) => $t->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('examTerm', fn ($t) => $t->where('name', 'like', "%{$search}%"));
                // Prefix match — "active" is a substring of "inactive".
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
            $query->setEagerLoads([]);
            $this->applyFieldSelection($query, $request);
        }

        $this->applySorting($query, $request);

        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $examSubTypes = $query->paginate($perPage);

        return $this->paginated(
            $examSubTypes,
            'Exam sub types fetched successfully.',
            $fieldsRequested ? null : ExamSubTypeResource::collection($examSubTypes),
        );
    }

    /** Trims the SELECT to just the requested column names (always keeping id). */
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

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('is_active')) {
            $statusMap = ['yes' => true, 'no' => false];
            $isActive = Str::lower($request->string('is_active')->toString());

            if (array_key_exists($isActive, $statusMap)) {
                $query->where('status', $statusMap[$isActive]);
            }
        }

        foreach (['exam_type_id', 'exam_term_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->integer($column));
            }
        }
    }

    private function applySorting(Builder $query, Request $request): void
    {
        $sortField = in_array($request->input('sort_by_field'), ['id', 'inserted_id', 'name', 'status', 'created_at'], true)
            ? $request->input('sort_by_field')
            : 'id';
        $sortDir = Str::lower((string) $request->input('sort_by')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortDir);
    }

    public function store(StoreExamSubTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= true;

        $examSubType = ExamSubType::create($data);

        return $this->success(new ExamSubTypeResource($examSubType->load(['examType', 'examTerm'])), 'Exam sub type created successfully.', 201);
    }

    public function show(ExamSubType $examSubType): JsonResponse
    {
        return $this->success(new ExamSubTypeResource($examSubType->load(['examType', 'examTerm'])), 'Exam sub type retrieved successfully.');
    }

    public function update(UpdateExamSubTypeRequest $request, ExamSubType $examSubType): JsonResponse
    {
        $examSubType->update($request->validated());

        return $this->success(new ExamSubTypeResource($examSubType->fresh(['examType', 'examTerm'])), 'Exam sub type updated successfully.');
    }

    public function destroy(ExamSubType $examSubType): JsonResponse
    {
        $examSubType->delete();

        return $this->success(null, 'Exam sub type deleted successfully.');
    }

    public function restore(ExamSubType $examSubType): JsonResponse
    {
        $examSubType->restore();

        return $this->success(new ExamSubTypeResource($examSubType->load(['examType', 'examTerm'])), 'Exam sub type restored successfully.');
    }

    /**
     * POST /exam-sub-types/sync — see MainDbSyncService (matched by
     * inserted_id; new → added, existing → updated/restored, gone → soft-
     * deleted; hand-added sub types untouched). The API's type_id / term_id
     * are the exam type's / exam term's inserted_id (Ref Id) — mapped to our
     * own ids here; one not synced yet is left empty. assessment_type_id,
     * assessment_type and total_count aren't stored.
     */
    public function sync(MainDbApiService $mainDb, MainDbSyncService $syncer): JsonResponse
    {
        try {
            $config = MainDbSyncConfig::forEntity('exam-sub-types');
            $remote = $mainDb->fetch($config->path, $config->method, $config->queryParams());
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        // main database id → our id
        $typeIds = ExamType::withTrashed()->whereNotNull('inserted_id')->pluck('id', 'inserted_id');
        $termIds = ExamTerm::withTrashed()->whereNotNull('inserted_id')->pluck('id', 'inserted_id');
        $ourId = fn ($ids, $remoteId) => is_numeric(trim((string) $remoteId)) ? $ids->get((int) trim((string) $remoteId)) : null;

        $summary = $syncer->sync(ExamSubType::class, $remote, function (array $row) use ($typeIds, $termIds, $ourId) {
            $name = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', (string) ($row['name'] ?? '')));

            return $name === '' ? null : [
                'name' => Str::limit($name, 255, ''),
                'exam_type_id' => $ourId($typeIds, $row['type_id'] ?? null),
                'exam_term_id' => $ourId($termIds, $row['term_id'] ?? null),
                'deleted_from_main_db' => MainDbSyncService::isDeletedInMainDb($row),
            ];
        }, 'exam-sub-types');

        return $this->success($summary, 'Exam sub types synced successfully.');
    }
}
