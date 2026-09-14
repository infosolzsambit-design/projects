<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeacherResource;
use App\Services\AuditLogService;
use App\Services\TeacherBulkUploadService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Backs the "Bulk Teacher Upload" page (Teachers list → Bulk Upload button):
// a CSV is parsed into rows client-side (no file ever reaches the backend,
// just plain JSON — see TeacherBulkUploadView.vue), checked here via
// validateRows(), edited/re-checked in the browser until every row is
// valid, then committed here via store(). No permission gating yet, same
// as every other module (see TeacherController).
class TeacherBulkUploadController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TeacherBulkUploadService $bulkUploadService,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * Checks every row without creating anything — field-level rules plus
     * duplicate-email/duplicate-phone checks *within this same upload*
     * (something a one-row-at-a-time request could never catch).
     */
    public function validateRows(Request $request): JsonResponse
    {
        $rows = $request->input('rows');

        if (! is_array($rows) || $rows === []) {
            return $this->error('No rows to validate.', 422);
        }

        $results = $this->bulkUploadService->validateRows($rows);

        return $this->success([
            'all_valid' => collect($results)->every(fn ($result) => $result['valid']),
            'rows' => $results,
        ], 'Rows validated.');
    }

    /**
     * Re-validates (never trusts the browser's "already validated" state —
     * data may have been edited since, or gone stale against the DB) and,
     * only if every row is still valid, creates all of them in one
     * transaction: all rows succeed together or none are created at all.
     */
    public function store(Request $request): JsonResponse
    {
        $rows = $request->input('rows');

        if (! is_array($rows) || $rows === []) {
            return $this->error('No rows to import.', 422);
        }

        $results = $this->bulkUploadService->validateRows($rows);

        if (! collect($results)->every(fn ($result) => $result['valid'])) {
            return $this->error('Some rows failed validation — nothing was imported.', 422, ['rows' => $results]);
        }

        $created = DB::transaction(fn () => $this->bulkUploadService->createRows($rows, $results));

        $this->auditLog->log(
            event: 'bulk-created',
            module: 'Teacher Management',
            description: count($created).' teacher(s) created via bulk CSV upload.',
            newValues: ['count' => count($created), 'emails' => collect($created)->pluck('email')->all()],
            tags: ['bulk-upload', 'teacher'],
        );

        return $this->success([
            'created_count' => count($created),
            'teachers' => TeacherResource::collection(collect($created)->map(fn ($user) => $user->load('teacherDetail'))),
        ], count($created).' teacher(s) created successfully.', 201);
    }
}
