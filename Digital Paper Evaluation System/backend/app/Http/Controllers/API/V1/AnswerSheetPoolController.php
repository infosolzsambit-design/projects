<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnswerSheetPoolResource;
use App\Models\AnswerSheet;
use App\Models\AnswerSheetPool;
use App\Services\AuditLogService;
use App\Services\UserNotificationService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Backs the "Shared Pools" page (Assign Teacher menu): list the pools made
 * with Assign Teacher → Pool, change which teachers share one, or cancel
 * one. A started sheet always stays with the teacher who started it — only
 * sheets still waiting in the pool are affected by any of this. No
 * permission gate on the API (the page itself checks
 * 'assign-answersheet-to-teacher', same as Assign Teacher).
 */
class AnswerSheetPoolController extends Controller
{
    use ApiResponse;

    private const SORTABLE = ['id', 'created_at', 'exam_year', 'semester'];

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly UserNotificationService $notifications,
    ) {}

    /**
     * GET /answer-sheet-pools — same multi-purpose shape as every other
     * index(): ?id= (one pool), ?status=deleted (cancelled pools) | all
     * (unpaginated), otherwise paginated with ?search= (program, course
     * name/code), ?course_id=, ?exam_year=, ?exam_type_id=, ?sort_by=,
     * ?sort_by_field=.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->baseQuery();
        $status = $request->string('status')->toString();

        if ($request->filled('id')) {
            $pool = $query->withTrashed()->find($request->integer('id'));

            return $pool
                ? $this->success(new AnswerSheetPoolResource($this->withTeacherCounts(collect([$pool]))->first()), 'Pool fetched successfully.')
                : $this->notFound('Pool not found.');
        }

        if ($status === 'deleted') {
            $query->onlyTrashed();
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(fn (Builder $q) => $q
                ->where('program_name', 'like', "%{$search}%")
                ->orWhereHas('course', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")));
        }
        foreach (['course_id', 'exam_year', 'exam_type_id', 'exam_term_id', 'semester'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->integer($field));
            }
        }

        $sortField = in_array($request->string('sort_by_field')->toString(), self::SORTABLE, true)
            ? $request->string('sort_by_field')->toString()
            : 'id';
        $query->orderBy($sortField, $request->string('sort_by')->toString() === 'asc' ? 'asc' : 'desc');

        if ($status === 'all') {
            return $this->success(AnswerSheetPoolResource::collection($this->withTeacherCounts($query->get())), 'Pools fetched successfully.');
        }

        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));
        $page = $query->paginate($perPage);

        return $this->paginated($page, 'Pools fetched successfully.', AnswerSheetPoolResource::collection($this->withTeacherCounts(collect($page->items()))));
    }

    /**
     * PUT /answer-sheet-pools/{pool}/teachers {teacher_ids: [...]} — replaces
     * who shares the pool. Newly added teachers get a bell notification;
     * removed teachers keep any sheet they already started (only the
     * waiting ones stop showing for them).
     */
    public function updateTeachers(Request $request, AnswerSheetPool $pool): JsonResponse
    {
        $data = $request->validate([
            'teacher_ids' => ['required', 'array', 'min:1'],
            'teacher_ids.*' => ['required', 'integer', 'distinct', Rule::exists('teacher_details', 'user_id')->whereNull('deleted_at')],
        ]);

        $before = $pool->teachers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $after = array_map('intval', $data['teacher_ids']);
        $pool->teachers()->sync($after);

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        $waiting = $pool->sheets()->waitingInPool()->count();

        if ($added && $waiting) {
            $this->notifications->answerSheetsPooled($added, $waiting, (int) $pool->course_id, poolSize: count($after));
        }

        $this->auditLog->log(
            event: 'answer-sheet-pool-teachers-updated',
            module: 'Assign Teacher',
            description: "Changed the teachers of pool #{$pool->id}: ".count($added).' added, '.count($removed).' removed.',
            auditable: $pool,
            oldValues: ['teacher_ids' => $before],
            newValues: ['teacher_ids' => $after],
            tags: ['assign-teacher', 'pool'],
        );

        return $this->success(
            new AnswerSheetPoolResource($this->withTeacherCounts(collect([$this->baseQuery()->find($pool->id)]))->first()),
            'Pool teachers updated successfully.',
        );
    }

    /**
     * POST /answer-sheet-pools/{pool}/cancel — every sheet still waiting in
     * the pool goes back to "pending to assign" (its pool link and the
     * pool's evaluation window are cleared); sheets already started stay
     * with their teachers. The pool itself is soft-deleted (shown as
     * cancelled).
     */
    public function cancel(AnswerSheetPool $pool): JsonResponse
    {
        [$returned, $kept] = DB::transaction(function () use ($pool) {
            $waiting = $pool->sheets()->waitingInPool()->lockForUpdate()->get();
            // Per-sheet ->update() for the userstamp/audit trail, same as
            // AssignTeacherService.
            foreach ($waiting as $sheet) {
                $sheet->update([
                    'answer_sheet_pool_id' => null,
                    'evaluation_start_date' => null,
                    'evaluation_end_date' => null,
                    'evaluation_time_per_sheet' => null,
                ]);
            }
            $kept = $pool->sheets()->whereNotNull('teacher_id')->count();
            $pool->delete();

            return [$waiting->count(), $kept];
        });

        $this->auditLog->log(
            event: 'answer-sheet-pool-cancelled',
            module: 'Assign Teacher',
            description: "Cancelled pool #{$pool->id}: {$returned} sheet(s) back to pending, {$kept} already-started sheet(s) kept by their teachers.",
            auditable: $pool,
            newValues: ['returned_to_pending' => $returned, 'kept_by_teachers' => $kept],
            tags: ['assign-teacher', 'pool'],
        );

        return $this->success(
            ['returned_to_pending' => $returned, 'kept_by_teachers' => $kept],
            "Pool cancelled — {$returned} answer sheet(s) are back in pending to assign.",
        );
    }

    /**
     * Per teacher, how many of each pool's sheets they started and how many
     * of those they've evaluated — one grouped query for the whole page.
     * Teachers sharing the pool come first (0 when they haven't started any);
     * anyone removed from the pool who had already started sheets is listed
     * too, flagged in_pool = false. Set as each pool's teacher_breakdown.
     */
    private function withTeacherCounts(\Illuminate\Support\Collection $pools): \Illuminate\Support\Collection
    {
        if ($pools->isEmpty()) {
            return $pools;
        }

        $counts = AnswerSheet::query()
            ->whereIn('answer_sheet_pool_id', $pools->pluck('id'))
            ->whereNotNull('teacher_id')
            ->groupBy('answer_sheet_pool_id', 'teacher_id')
            ->selectRaw('answer_sheet_pool_id, teacher_id, COUNT(*) as started, SUM(CASE WHEN marks IS NOT NULL THEN 1 ELSE 0 END) as evaluated')
            ->get()
            // Plain collection: an Eloquent one's except() matches model
            // primary keys (these grouped rows have none), not list keys.
            ->toBase()
            ->groupBy('answer_sheet_pool_id');

        $formerIds = $counts->flatten()->pluck('teacher_id')->unique()->all();
        $users = \App\Models\User::with('teacherDetail:id,user_id,emp_code')->whereIn('id', $formerIds)->get(['id', 'name'])->keyBy('id');

        return $pools->each(function (AnswerSheetPool $pool) use ($counts, $users) {
            $byTeacher = ($counts->get($pool->id) ?? collect())->keyBy('teacher_id');
            $current = $pool->teachers->map(fn ($teacher) => [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'emp_code' => $teacher->teacherDetail?->emp_code,
                'in_pool' => true,
                'started_count' => (int) ($byTeacher->get($teacher->id)?->started ?? 0),
                'evaluated_count' => (int) ($byTeacher->get($teacher->id)?->evaluated ?? 0),
            ]);
            $removed = $byTeacher->except($pool->teachers->pluck('id')->all())->map(fn ($row) => [
                'id' => (int) $row->teacher_id,
                'name' => $users->get($row->teacher_id)?->name ?? 'Unknown teacher',
                'emp_code' => $users->get($row->teacher_id)?->teacherDetail?->emp_code,
                'in_pool' => false,
                'started_count' => (int) $row->started,
                'evaluated_count' => (int) $row->evaluated,
            ]);
            $pool->setAttribute('teacher_breakdown', $current->concat($removed->values())->values()->all());
        });
    }

    /** Pools with everything the list shows, counts included. */
    private function baseQuery(): Builder
    {
        return AnswerSheetPool::query()
            ->with(['course:id,name,code,type', 'department:id,name', 'examTerm:id,name', 'examType:id,name', 'creator:id,name', 'teachers.teacherDetail'])
            ->withCount([
                'sheets',
                'sheets as waiting_count' => fn ($q) => $q->waitingInPool(),
                'sheets as started_count' => fn ($q) => $q->whereNotNull('teacher_id'),
                'sheets as evaluated_count' => fn ($q) => $q->whereNotNull('marks'),
            ]);
    }
}
