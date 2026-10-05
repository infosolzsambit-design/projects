<?php

namespace App\Models;

use App\Traits\HasAuditContext;
use App\Traits\HasDateTimeTimestamps;
use App\Traits\HasUserstamps;
use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Program extends Model implements AuditableContract
{
    /** @use HasFactory<ProgramFactory> */
    use Auditable, HasAuditContext, HasDateTimeTimestamps, HasFactory, HasUserstamps, SoftDeletes {
        HasAuditContext::transformAudit insteadof Auditable;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'label',
        'department',
        'department_id',
        'code',
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

    public const DUPLICATE_MESSAGE = 'A program with this Name, Code and Label already exists.';

    /**
     * A program is unique by the combination name + code + label among
     * non-deleted programs (so "B.Sc / BSC / UG" and "B.Sc / BSC / PG" can
     * both exist). Case-insensitive and trailing-space-insensitive via the
     * column collation — "ug" and "UG" are the same label. Used by the
     * create/edit form, bulk upload and restore so all three agree.
     */
    public static function hasDuplicate(string $name, string $code, ?string $label, ?int $ignoreId = null): bool
    {
        return static::query()
            ->where('name', trim($name))
            ->where('code', trim($code))
            ->when(
                $label === null || trim($label) === '',
                fn ($q) => $q->where(fn ($w) => $w->whereNull('label')->orWhere('label', '')),
                fn ($q) => $q->where('label', trim($label)),
            )
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'program_course_mappings');
    }

    // No database foreign key (project-wide rule) — `department` still
    // holds the department's name as a denormalized copy for listing/search,
    // this relation is just for convenience when the live record is needed.
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
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
