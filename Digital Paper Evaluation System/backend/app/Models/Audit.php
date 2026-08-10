<?php

namespace App\Models;

use App\Traits\HasDateTimeTimestamps;
use OwenIt\Auditing\Models\Audit as BaseAudit;

/**
 * Audit records are immutable from the application: there is intentionally
 * no update or delete path anywhere in this codebase (see AuditController,
 * which is read-only). No HasUserstamps/SoftDeletes on purpose.
 */
class Audit extends BaseAudit
{
    use HasDateTimeTimestamps;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_type',
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'url',
        'ip_address',
        'user_agent',
        'tags',
        'module',
        'description',
        'http_method',
        'route',
        'request_id',
    ];
}
