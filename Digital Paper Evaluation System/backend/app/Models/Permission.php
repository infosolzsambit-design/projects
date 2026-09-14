<?php

namespace App\Models;

use App\Traits\HasAuditContext;
use App\Traits\HasDateTimeTimestamps;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission implements AuditableContract
{
    use Auditable, HasAuditContext, HasDateTimeTimestamps, HasUserstamps, SoftDeletes {
        HasAuditContext::transformAudit insteadof Auditable;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guard_name',
        'permission_group_id',
        'permission_sub_group_id',
    ];

    // No database foreign key (project-wide rule) — resolved through these
    // relations.
    public function group(): BelongsTo
    {
        return $this->belongsTo(PermissionGroup::class, 'permission_group_id');
    }

    public function subGroup(): BelongsTo
    {
        return $this->belongsTo(PermissionSubGroup::class, 'permission_sub_group_id');
    }
}
