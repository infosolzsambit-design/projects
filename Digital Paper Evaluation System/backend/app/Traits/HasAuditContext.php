<?php

namespace App\Traits;

use Illuminate\Support\Facades\Request;

/**
 * OwenIt\Auditing's own Auditable::toAudit() only populates the package's
 * default columns (event, auditable_*, old/new_values, user_*, url, ip,
 * user_agent, tags) — it has no knowledge of this project's extra context
 * columns (module/description/http_method/route/request_id), which are
 * otherwise only filled in manually via AuditLogService for non-Eloquent
 * events (login, role assignment, ...). This uses the package's documented
 * transformAudit() hook so automatic create/update/delete/restore audits
 * get the same context instead of leaving those columns blank.
 */
trait HasAuditContext
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        $data['module'] ??= class_basename(static::class);
        $data['description'] ??= class_basename(static::class).' '.($data['event'] ?? 'changed').'.';
        $data['http_method'] ??= Request::method();
        $data['route'] ??= Request::route()?->getName() ?: Request::path();
        // request()->attributes is a plain property (RequestId middleware
        // sets it there) — can't go through the Request facade's
        // __callStatic, so app('request_id') is used instead (the same
        // middleware also binds it directly into the container).
        $data['request_id'] ??= app()->bound('request_id') ? app('request_id') : null;

        return $data;
    }
}
