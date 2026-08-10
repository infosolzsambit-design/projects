<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Manual audit trail for events that aren't plain Eloquent model changes
 * (login, logout, failed login, password change, role/permission
 * assignment, status change, ...). Model create/update/delete/restore is
 * already captured automatically by OwenIt\Auditing\Auditable on each model.
 */
class AuditLogService
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  list<string>  $tags
     */
    public function log(
        string $event,
        string $module,
        string $description,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        array $tags = [],
        ?User $causer = null,
    ): Audit {
        $causer ??= $this->request->user();

        return Audit::create([
            'user_type' => $causer ? $causer::class : null,
            'user_id' => $causer?->id,
            'event' => $event,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'url' => $this->request->fullUrl(),
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 1023),
            'tags' => implode(',', $tags),
            'module' => $module,
            'description' => $description,
            'http_method' => $this->request->method(),
            'route' => $this->request->route()?->getName() ?: $this->request->path(),
            'request_id' => $this->request->attributes->get('request_id'),
        ]);
    }
}
