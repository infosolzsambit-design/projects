<?php

namespace App\Models;

use App\Traits\HasDateTimeTimestamps;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission implements AuditableContract
{
    use Auditable, HasDateTimeTimestamps, HasUserstamps, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guard_name',
    ];
}
