<?php

namespace App\Models;

use App\Traits\HasDateTimeTimestamps;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole implements AuditableContract
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
