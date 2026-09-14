<?php

namespace App\Http\Controllers\API\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Services\CourseBulkUploadService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Backs the "Bulk Upload" page on the Courses list — same two-step
// validate-then-store shape as DepartmentBulkUploadController/
// TeacherBulkUploadController: a CSV is parsed into rows client-side (see
// CourseBulkUploadView.vue), checked here via validateRows(), edited/re-
// checked in the browser until every row is valid, then committed here via
// store().
class CourseBulkUploadController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CourseBulkUploadService $bulkUploadService) {}

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

        $created = DB::transaction(fn () => $this->bulkUploadService->createRows($rows));

        return $this->success([
            'created_count' => count($created),
            'courses' => CourseResource::collection($created),
        ], count($created).' course(s) created successfully.', 201);
    }
}
