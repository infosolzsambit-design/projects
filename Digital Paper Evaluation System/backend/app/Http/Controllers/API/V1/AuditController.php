<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditResource;
use App\Models\Audit;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Read-only by design — audit logs are immutable from the application.
 * There is intentionally no store/update/destroy here.
 */
class AuditController extends Controller implements HasMiddleware
{
    use ApiResponse;

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:audit-list', only: ['index']),
            new Middleware('permission:audit-view', only: ['show']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $query = Audit::query()->with('user')->latest('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($module = $request->string('module')->toString()) {
            $query->where('module', $module);
        }

        if ($event = $request->string('event')->toString()) {
            $query->where('event', $event);
        }

        if ($auditableType = $request->string('auditable_type')->toString()) {
            $query->where('auditable_type', $auditableType);
        }

        if ($request->filled('auditable_id')) {
            $query->where('auditable_id', (int) $request->input('auditable_id'));
        }

        if ($ip = $request->string('ip_address')->toString()) {
            $query->where('ip_address', $ip);
        }

        if ($dateFrom = $request->string('date_from')->toString()) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->string('date_to')->toString()) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $audits = $query->paginate($perPage);

        return $this->paginated($audits, 'Audit logs retrieved successfully.', AuditResource::collection($audits));
    }

    public function show(Audit $audit): JsonResponse
    {
        return $this->success(new AuditResource($audit->load('user')), 'Audit log retrieved successfully.');
    }
}
