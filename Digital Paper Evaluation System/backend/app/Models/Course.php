<?php

namespace App\Models;

use App\Traits\HasAuditContext;
use App\Traits\HasDateTimeTimestamps;
use App\Traits\HasUserstamps;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Course extends Model implements AuditableContract
{
    /** @use HasFactory<CourseFactory> */
    use Auditable, HasAuditContext, HasDateTimeTimestamps, HasFactory, HasUserstamps, SoftDeletes {
        HasAuditContext::transformAudit insteadof Auditable;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'type',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public const DUPLICATE_MESSAGE = 'A course with this Name, Code and Type already exists.';

    /**
     * A course is unique by the combination name + code + type among
     * non-deleted courses (so "Physics / PHY101 / Theory" and "Physics /
     * PHY101 / Practical" can both exist). Case-insensitive via the column
     * collation. Used by the create/edit form, bulk upload and restore.
     */
    public static function hasDuplicate(string $name, string $code, ?string $type, ?int $ignoreId = null): bool
    {
        return static::query()
            ->where('name', trim($name))
            ->where('code', trim($code))
            ->when(
                $type === null || trim($type) === '',
                fn ($q) => $q->where(fn ($w) => $w->whereNull('type')->orWhere('type', '')),
                fn ($q) => $q->where('type', trim($type)),
            )
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'program_course_mappings');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
